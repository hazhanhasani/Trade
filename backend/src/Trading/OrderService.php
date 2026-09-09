<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Config;
use Trade\Database;
use Trade\Exchange\BitpinClient;
use Trade\Security\Crypto;

final class OrderService
{
    public function client(): BitpinClient
    {
        $pdo = Database::connection();
        $row = $pdo->query("SELECT * FROM exchange_credentials WHERE exchange_name='bitpin' LIMIT 1")->fetch();
        if (!$row) {
            throw new \RuntimeException('Bitpin credentials are not configured.');
        }

        $key = (string) Config::require('app.encryption_key');
        return new BitpinClient([
            'base_url' => Config::get('bitpin.base_url', 'https://api.bitpin.market/api/v1'),
            'api_key' => Crypto::decrypt((string) $row['api_key_enc'], $key),
            'secret_key' => Crypto::decrypt((string) $row['secret_key_enc'], $key),
            'access_token' => $row['access_token_enc'] ? Crypto::decrypt((string) $row['access_token_enc'], $key) : null,
            'refresh_token' => $row['refresh_token_enc'] ? Crypto::decrypt((string) $row['refresh_token_enc'], $key) : null,
            'timeout' => (int) Config::get('bitpin.timeout', 12),
            'endpoints' => Config::get('bitpin.endpoints', []),
        ]);
    }

    public function syncTokens(BitpinClient $client): void
    {
        $tokens = $client->tokens();
        if (!$tokens['access'] && !$tokens['refresh']) {
            return;
        }

        $key = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare(
            "UPDATE exchange_credentials SET access_token_enc=:a,refresh_token_enc=:r,updated_at=UTC_TIMESTAMP() WHERE exchange_name='bitpin'"
        );
        $stmt->execute([
            ':a' => $tokens['access'] ? Crypto::encrypt((string) $tokens['access'], $key) : null,
            ':r' => $tokens['refresh'] ? Crypto::encrypt((string) $tokens['refresh'], $key) : null,
        ]);
    }

    public function liveEnabled(): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT value_text FROM settings WHERE key_name='live_trading_enabled' LIMIT 1");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) Config::get('trading.enabled', false);
    }

    public function create(array $input, string $source = 'api'): array
    {
        $this->assertAllowed($input);

        $market = filter_var($input['market'] ?? null, FILTER_VALIDATE_INT);
        if (!$market || $market < 1) {
            throw new \InvalidArgumentException('market must be a positive Bitpin market id.');
        }

        $amount1 = $this->positive($input['amount1'] ?? null, 'amount1');
        $price = $this->positive($input['price'] ?? null, 'price');
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $mode = strtolower(trim((string) ($input['mode'] ?? 'limit')));
        if (!in_array($type, ['buy', 'sell'], true)) {
            throw new \InvalidArgumentException('type must be buy or sell.');
        }
        if (!in_array($mode, ['limit', 'market', 'stop_limit', 'oco'], true)) {
            throw new \InvalidArgumentException('Unsupported order mode.');
        }

        $identifier = trim((string) ($input['identifier'] ?? '')) ?: 'trade-' . bin2hex(random_bytes(10));
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid identifier.');
        }

        $payload = [
            'market' => (string) $market,
            'amount1' => $this->num($amount1),
            'price' => $this->num($price),
            'mode' => $mode,
            'type' => $type,
            'identifier' => $identifier,
        ];
        foreach (['price_limit', 'price_stop', 'price_limit_oco', 'amount2'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $payload[$field] = $this->num($this->positive($input[$field], $field));
            }
        }

        $localId = bin2hex(random_bytes(12));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO orders (local_id,exchange_name,identifier,market_code,side,order_mode,amount,price,status,source,request_json,created_at,updated_at)
             VALUES (:local,'bitpin',:identifier,:market,:side,:mode,:amount,:price,'submitting',:source,:request,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        $stmt->execute([
            ':local' => $localId,
            ':identifier' => $identifier,
            ':market' => (string) $market,
            ':side' => $type,
            ':mode' => $mode,
            ':amount' => $payload['amount1'],
            ':price' => $payload['price'],
            ':source' => $source,
            ':request' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $client = $this->client();
            $response = $client->createOrder($payload);
            $this->syncTokens($client);
            $exchangeId = (string) ($response['id'] ?? $response['order_id'] ?? '');
            $remoteState = strtolower(trim((string) ($response['state'] ?? $response['status'] ?? '')));
            $localStatus = in_array($remoteState, ['closed', 'filled', 'fully_filled', 'completed', 'done'], true) ? 'filled' : 'submitted';
            $stmt = $pdo->prepare(
                "UPDATE orders SET exchange_order_id=:eid,status=:status,response_json=:response,updated_at=UTC_TIMESTAMP() WHERE local_id=:local"
            );
            $stmt->execute([
                ':eid' => $exchangeId !== '' ? $exchangeId : null,
                ':status' => $localStatus,
                ':response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':local' => $localId,
            ]);
            $this->audit('order.live_submitted', [
                'local_id' => $localId,
                'exchange_order_id' => $exchangeId,
                'source' => $source,
                'mode' => $mode,
                'side' => $type,
            ]);
            return ['local_id' => $localId, 'exchange' => $response];
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare("UPDATE orders SET status='failed',error_text=:error,updated_at=UTC_TIMESTAMP() WHERE local_id=:local");
            $stmt->execute([':error' => mb_substr($e->getMessage(), 0, 1000), ':local' => $localId]);
            $this->audit('order.live_failed', ['local_id' => $localId, 'error' => $e->getMessage(), 'source' => $source]);
            throw $e;
        }
    }

    public function cancel(string $exchangeOrderId): array
    {
        $this->assertEnabled();
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $exchangeOrderId)) {
            throw new \InvalidArgumentException('Invalid order id.');
        }

        $client = $this->client();
        $response = $client->cancelOrder($exchangeOrderId);
        $this->syncTokens($client);
        $stmt = Database::connection()->prepare("UPDATE orders SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE exchange_order_id=:id");
        $stmt->execute([':id' => $exchangeOrderId]);
        $this->audit('order.live_cancelled', ['exchange_order_id' => $exchangeOrderId]);
        return $response;
    }

    private function assertAllowed(array $order): void
    {
        $this->assertEnabled();
        $pdo = Database::connection();
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0');
        if ($kill === '1') {
            throw new \RuntimeException('Kill switch is enabled.');
        }

        $maxOrders = max(1, (int) Config::get('trading.max_orders_per_hour', 10));
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM orders WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR) AND status IN ('submitting','submitted','filled')"
        )->fetchColumn();
        if ($count >= $maxOrders) {
            throw new \RuntimeException('Hourly order limit reached.');
        }

        $maxValue = (float) Config::get('trading.max_order_value', 0);
        if ($maxValue > 0 && isset($order['amount1'], $order['price'])) {
            $value = (float) $order['amount1'] * (float) $order['price'];
            if ($value > $maxValue) {
                throw new \RuntimeException('Order exceeds configured max_order_value.');
            }
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->liveEnabled()) {
            throw new \RuntimeException('Live trading is disabled.');
        }
    }

    private function positive(mixed $value, string $field): float
    {
        if (!is_numeric($value) || (float) $value <= 0 || !is_finite((float) $value)) {
            throw new \InvalidArgumentException($field . ' must be a positive number.');
        }
        return (float) $value;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    private function audit(string $event, array $context): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:event,:context,UTC_TIMESTAMP())');
        $stmt->execute([
            ':event' => $event,
            ':context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
