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
            'source_ip' => $this->configuredSourceIp(),
        ]);
    }

    public function syncTokens(BitpinClient $client): void
    {
        $tokens = $client->tokens();
        if (!$tokens['access'] && !$tokens['refresh']) return;
        $key = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare("UPDATE exchange_credentials SET access_token_enc=:a,refresh_token_enc=:r,updated_at=UTC_TIMESTAMP() WHERE exchange_name='bitpin'");
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
        if ($value !== false) return in_array(strtolower(trim((string) $value)), ['1','true','yes','on'], true);
        return (bool) Config::get('trading.enabled', false);
    }

    public function create(array $input, string $source = 'api'): array
    {
        $normalized = $this->normalizeOrderInput($input);
        $this->assertAllowed($normalized);

        $payload = [
            'symbol' => $normalized['symbol'],
            'type' => $normalized['order_type'],
            'side' => $normalized['side'],
            'identifier' => $normalized['identifier'],
        ];
        if ($normalized['base_amount'] !== null) $payload['base_amount'] = $this->num($normalized['base_amount']);
        if ($normalized['quote_amount'] !== null) $payload['quote_amount'] = $this->num($normalized['quote_amount']);
        if ($normalized['price'] !== null) $payload['price'] = $this->num($normalized['price']);
        if ($normalized['stop_price'] !== null) $payload['stop_price'] = $this->num($normalized['stop_price']);
        if ($normalized['oco_target_price'] !== null) $payload['oco_target_price'] = $this->num($normalized['oco_target_price']);

        $localId = bin2hex(random_bytes(12));
        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO orders (local_id,exchange_name,identifier,market_code,side,order_mode,amount,price,status,source,request_json,created_at,updated_at) VALUES (:local,'bitpin',:identifier,:market,:side,:mode,:amount,:price,'submitting',:source,:request,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([
            ':local' => $localId,
            ':identifier' => $normalized['identifier'],
            ':market' => $normalized['symbol'],
            ':side' => $normalized['side'],
            ':mode' => $normalized['order_type'],
            ':amount' => $normalized['base_amount'] ?? 0,
            ':price' => $normalized['price'] ?? $normalized['reference_price'],
            ':source' => $source,
            ':request' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $client = $this->client();
            $response = $client->createOrder($payload);
            $this->syncTokens($client);
            $exchangeId = (string) ($response['id'] ?? $response['order_id'] ?? '');
            $remoteState = strtolower(trim((string) ($response['state'] ?? $response['status'] ?? '')));
            $localStatus = in_array($remoteState, ['closed','filled','fully_filled','completed','done'], true) ? 'filled' : 'submitted';
            $stmt = $pdo->prepare("UPDATE orders SET exchange_order_id=:eid,status=:status,response_json=:response,updated_at=UTC_TIMESTAMP() WHERE local_id=:local");
            $stmt->execute([
                ':eid' => $exchangeId !== '' ? $exchangeId : null,
                ':status' => $localStatus,
                ':response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':local' => $localId,
            ]);
            $this->audit('order.live_submitted', [
                'local_id'=>$localId,
                'exchange_order_id'=>$exchangeId,
                'source'=>$source,
                'symbol'=>$normalized['symbol'],
                'order_type'=>$normalized['order_type'],
                'side'=>$normalized['side'],
            ]);
            return ['local_id'=>$localId,'exchange'=>$response];
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare("UPDATE orders SET status='failed',error_text=:error,updated_at=UTC_TIMESTAMP() WHERE local_id=:local");
            $stmt->execute([':error'=>mb_substr($e->getMessage(),0,1000),':local'=>$localId]);
            $this->audit('order.live_failed', ['local_id'=>$localId,'error'=>$e->getMessage(),'source'=>$source]);
            throw $e;
        }
    }

    public function cancel(string $exchangeOrderId): array
    {
        $this->assertEnabled();
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $exchangeOrderId)) throw new \InvalidArgumentException('Invalid order id.');
        $client = $this->client();
        $response = $client->cancelOrder($exchangeOrderId);
        $this->syncTokens($client);
        $stmt = Database::connection()->prepare("UPDATE orders SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE exchange_order_id=:id");
        $stmt->execute([':id'=>$exchangeOrderId]);
        $this->audit('order.live_cancelled', ['exchange_order_id'=>$exchangeOrderId]);
        return $response;
    }

    private function normalizeOrderInput(array $input): array
    {
        $legacyType = strtolower(trim((string) ($input['type'] ?? '')));
        $side = strtolower(trim((string) ($input['side'] ?? '')));
        if ($side === '' && in_array($legacyType, ['buy','sell'], true)) $side = $legacyType;
        if (!in_array($side, ['buy','sell'], true)) throw new \InvalidArgumentException('side must be buy or sell.');

        $orderType = strtolower(trim((string) ($input['order_type'] ?? '')));
        if ($orderType === '' && $legacyType !== '' && !in_array($legacyType, ['buy','sell'], true)) $orderType = $legacyType;
        if ($orderType === '') $orderType = strtolower(trim((string) ($input['mode'] ?? 'limit')));
        $orderType = match ($orderType) {
            'stop_limit'=>'stop_limit', 'stop_market'=>'stop_market', 'oco'=>'oco', 'limit'=>'limit', 'market'=>'market',
            default=>throw new \InvalidArgumentException('Unsupported Bitpin order type.'),
        };

        $symbol = strtoupper(trim((string) ($input['symbol'] ?? $input['market_code'] ?? '')));
        $legacyMarket = $input['market'] ?? null;
        if ($symbol === '' && is_string($legacyMarket) && !ctype_digit($legacyMarket)) $symbol = strtoupper(trim($legacyMarket));
        if ($symbol === '' && filter_var($legacyMarket, FILTER_VALIDATE_INT)) $symbol = $this->resolveLegacyMarketId((int) $legacyMarket);
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $symbol)) throw new \InvalidArgumentException('symbol is required by the current Bitpin API, for example TON_USDT.');

        $baseAmount = $this->optionalPositive($input['base_amount'] ?? $input['amount1'] ?? null, 'base_amount');
        $quoteAmount = $this->optionalPositive($input['quote_amount'] ?? null, 'quote_amount');
        if ($baseAmount === null && $quoteAmount === null) throw new \InvalidArgumentException('base_amount or quote_amount is required.');

        $submittedPrice = $this->optionalPositive($input['price'] ?? null, 'price');
        $referencePrice = $this->optionalPositive($input['reference_price'] ?? $submittedPrice, 'reference_price');
        $price = in_array($orderType, ['market','stop_market'], true) ? null : $submittedPrice;
        $stopPrice = $this->optionalPositive($input['stop_price'] ?? $input['price_stop'] ?? null, 'stop_price');
        $ocoTargetPrice = $this->optionalPositive($input['oco_target_price'] ?? $input['price_limit_oco'] ?? null, 'oco_target_price');

        if ($orderType === 'limit' && $price === null) throw new \InvalidArgumentException('price is required for a limit order.');
        if (in_array($orderType, ['stop_limit','stop_market'], true) && $stopPrice === null) throw new \InvalidArgumentException('stop_price is required for a stop order.');
        if ($orderType === 'stop_limit' && $price === null) {
            $price = $this->optionalPositive($input['price_limit'] ?? null, 'price_limit');
            if ($price === null) throw new \InvalidArgumentException('price is required for a stop-limit order.');
        }
        if ($orderType === 'oco' && ($price === null || $stopPrice === null || $ocoTargetPrice === null)) throw new \InvalidArgumentException('price, stop_price and oco_target_price are required for OCO orders.');

        $identifier = trim((string) ($input['identifier'] ?? '')) ?: 'trade-' . bin2hex(random_bytes(10));
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $identifier)) throw new \InvalidArgumentException('Invalid identifier.');

        return [
            'symbol'=>$symbol,
            'order_type'=>$orderType,
            'side'=>$side,
            'base_amount'=>$baseAmount,
            'quote_amount'=>$quoteAmount,
            'price'=>$price,
            'reference_price'=>$referencePrice,
            'stop_price'=>$stopPrice,
            'oco_target_price'=>$ocoTargetPrice,
            'identifier'=>$identifier,
        ];
    }

    private function resolveLegacyMarketId(int $marketId): string
    {
        if ($marketId < 1) return '';
        try {
            $client = $this->client();
            $records = $this->marketRecords($client->markets());
            foreach ($records as $row) {
                $symbol = strtoupper(trim((string) ($row['symbol'] ?? $row['code'] ?? '')));
                if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $symbol)) continue;
                $realId = (int) ($row['id'] ?? $row['market_id'] ?? 0);
                if ($realId === $marketId || $this->stableMarketId($symbol) === $marketId) return $symbol;
            }
        } catch (\Throwable) {}
        return '';
    }

    private function marketRecords(array $response): array
    {
        if (array_is_list($response)) return array_values(array_filter($response, 'is_array'));
        foreach (['results','data','items','markets'] as $key) {
            if (!isset($response[$key]) || !is_array($response[$key])) continue;
            $value = $response[$key];
            if (array_is_list($value)) return array_values(array_filter($value, 'is_array'));
            foreach (['results','data','items'] as $nested) {
                if (isset($value[$nested]) && is_array($value[$nested]) && array_is_list($value[$nested])) return array_values(array_filter($value[$nested], 'is_array'));
            }
        }
        return [];
    }

    private function stableMarketId(string $symbol): int
    {
        $key = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        $id = (int) sprintf('%u', crc32($key));
        return $id > 0 ? $id : 1;
    }

    private function assertAllowed(array $order): void
    {
        $this->assertEnabled();
        $pdo = Database::connection();
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0');
        if ($kill === '1') throw new \RuntimeException('Kill switch is enabled.');

        $maxOrders = max(1, (int) Config::get('trading.max_orders_per_hour', 10));
        $count = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR) AND status IN ('submitting','submitted','filled')")->fetchColumn();
        if ($count >= $maxOrders) throw new \RuntimeException('Hourly order limit reached.');

        $maxValue = (float) Config::get('trading.max_order_value', 0);
        if ($maxValue <= 0) return;
        $value = 0.0;
        if (($order['quote_amount'] ?? null) !== null) $value = (float) $order['quote_amount'];
        elseif (($order['base_amount'] ?? null) !== null && ($order['reference_price'] ?? null) !== null) $value = (float) $order['base_amount'] * (float) $order['reference_price'];
        if ($value > $maxValue) throw new \RuntimeException('Order exceeds configured max_order_value.');
    }

    private function assertEnabled(): void
    {
        if (!$this->liveEnabled()) throw new \RuntimeException('Live trading is disabled.');
    }

    private function configuredSourceIp(): ?string
    {
        $configured = trim((string) Config::get('bitpin.source_ip', ''));
        if ($configured === '') return null;
        return filter_var($configured, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $configured : null;
    }

    private function optionalPositive(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value) || (float) $value <= 0 || !is_finite((float) $value)) throw new \InvalidArgumentException($field . ' must be a positive number.');
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
            ':event'=>$event,
            ':context'=>json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
