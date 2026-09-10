<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Config;
use Trade\Database;
use Trade\Exchange\NobitexClient;
use Trade\Security\Crypto;

final class NobitexOrderService
{
    public function client(): NobitexClient
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM exchange_credentials WHERE exchange_name='nobitex' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('Nobitex API credentials are not configured.');

        $key = (string) Config::require('app.encryption_key');
        return new NobitexClient([
            'base_url' => Config::get('nobitex.base_url', 'https://apiv2.nobitex.ir'),
            'public_key' => Crypto::decrypt((string) $row['api_key_enc'], $key),
            'private_key' => Crypto::decrypt((string) $row['secret_key_enc'], $key),
            'timeout' => (int) Config::get('nobitex.timeout', 12),
        ]);
    }

    public function credentialsConfigured(): bool
    {
        $stmt = Database::connection()->prepare("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='nobitex')");
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    public function saveCredentials(string $publicKey, string $privateKey): void
    {
        $publicKey = trim($publicKey);
        $privateKey = trim($privateKey);
        if ($publicKey === '' || $privateKey === '') throw new \InvalidArgumentException('Nobitex Public Key and Private Key are both required.');
        if (strlen($publicKey) > 1000 || strlen($privateKey) > 3000) throw new \InvalidArgumentException('Nobitex key length is invalid.');

        $test = new NobitexClient([
            'base_url' => Config::get('nobitex.base_url', 'https://apiv2.nobitex.ir'),
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'timeout' => (int) Config::get('nobitex.timeout', 12),
        ]);
        $test->test();

        $enc = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare(
            "INSERT INTO exchange_credentials (exchange_name,api_key_enc,secret_key_enc,access_token_enc,refresh_token_enc,created_at,updated_at)
             VALUES ('nobitex',:pub,:priv,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE api_key_enc=VALUES(api_key_enc),secret_key_enc=VALUES(secret_key_enc),access_token_enc=NULL,refresh_token_enc=NULL,updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':pub' => Crypto::encrypt($publicKey, $enc), ':priv' => Crypto::encrypt($privateKey, $enc)]);
        $this->audit('nobitex.credentials_saved');
    }

    public function deleteCredentials(): void
    {
        Database::connection()->exec("DELETE FROM exchange_credentials WHERE exchange_name='nobitex'");
        $this->setLiveEnabled(false);
        NobitexSchema::setBotEnabled(false);
        $this->audit('nobitex.credentials_deleted');
    }

    public function liveEnabled(): bool
    {
        $stmt = Database::connection()->prepare("SELECT value_text FROM settings WHERE key_name='live_trading_nobitex_enabled' LIMIT 1");
        $stmt->execute();
        return in_array(strtolower(trim((string) ($stmt->fetchColumn() ?: '0'))), ['1','true','yes','on'], true);
    }

    public function setLiveEnabled(bool $enabled): void
    {
        if ($enabled && !$this->credentialsConfigured()) throw new \RuntimeException('Configure and test Nobitex API credentials first.');
        $stmt = Database::connection()->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES ('live_trading_nobitex_enabled',:v,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':v' => $enabled ? '1' : '0']);
        $this->audit($enabled ? 'nobitex.live_enabled' : 'nobitex.live_disabled');
    }

    public function create(array $input, string $source = 'api'): array
    {
        $this->assertAllowed($input, $source);
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($input['symbol'] ?? $input['market_symbol'] ?? '')) ?? '');
        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '') throw new \InvalidArgumentException('Nobitex symbol must look like TONUSDT or TONIRT.');

        $amount = $this->positive($input['amount'] ?? $input['amount1'] ?? null, 'amount');
        $price = isset($input['price']) && $input['price'] !== '' ? $this->positive($input['price'], 'price') : null;
        $side = strtolower(trim((string) ($input['side'] ?? $input['type'] ?? '')));
        if (!in_array($side, ['buy','sell'], true)) throw new \InvalidArgumentException('side/type must be buy or sell.');
        $mode = strtolower(trim((string) ($input['mode'] ?? $input['execution'] ?? 'limit')));
        if (!in_array($mode, ['limit','market','stop_market','stop_limit','oco'], true)) throw new \InvalidArgumentException('Unsupported Nobitex order mode.');
        if (in_array($mode, ['limit','stop_limit','oco'], true) && $price === null) throw new \InvalidArgumentException('price is required for this order mode.');

        $clientOrderId = trim((string) ($input['clientOrderId'] ?? $input['identifier'] ?? ''));
        if ($clientOrderId === '') $clientOrderId = 'trd-' . substr(bin2hex(random_bytes(12)), 0, 24);
        $clientOrderId = substr($clientOrderId, 0, 32);
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $clientOrderId)) throw new \InvalidArgumentException('Invalid clientOrderId.');

        $payload = [
            'type' => $side,
            'srcCurrency' => strtolower($base),
            'dstCurrency' => strtolower($quote === 'IRT' ? 'rls' : $quote),
            'amount' => $this->num($amount),
            'clientOrderId' => $clientOrderId,
        ];
        if ($mode === 'oco') {
            $payload['mode'] = 'oco';
            $payload['price'] = $this->num((float) $price);
            $payload['stopPrice'] = $this->num($this->positive($input['stopPrice'] ?? $input['price_stop'] ?? null, 'stopPrice'));
            $payload['stopLimitPrice'] = $this->num($this->positive($input['stopLimitPrice'] ?? $input['price_limit_oco'] ?? $input['price_limit'] ?? null, 'stopLimitPrice'));
        } else {
            $payload['execution'] = $mode;
            if ($price !== null) $payload['price'] = $this->num($price);
            if (in_array($mode, ['stop_market','stop_limit'], true)) {
                $payload['stopPrice'] = $this->num($this->positive($input['stopPrice'] ?? $input['price_stop'] ?? null, 'stopPrice'));
            }
        }

        $localId = bin2hex(random_bytes(12));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO orders (local_id,exchange_name,identifier,market_code,side,order_mode,amount,price,status,source,request_json,created_at,updated_at)
             VALUES (:local,'nobitex',:identifier,:market,:side,:mode,:amount,:price,'submitting',:source,:request,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        $stmt->execute([
            ':local' => $localId, ':identifier' => $clientOrderId, ':market' => $symbol, ':side' => $side,
            ':mode' => $mode, ':amount' => $this->num($amount), ':price' => $price === null ? null : $this->num($price),
            ':source' => $source, ':request' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $response = $this->client()->createOrder($payload);
            $order = $this->firstOrder($response);
            $exchangeId = trim((string) ($order['id'] ?? ''));
            $state = strtolower(trim((string) ($order['status'] ?? '')));
            $localStatus = in_array($state, ['done','completed','filled'], true) ? 'filled' : 'submitted';
            $stmt = $pdo->prepare("UPDATE orders SET exchange_order_id=:id,status=:status,response_json=:response,updated_at=UTC_TIMESTAMP() WHERE local_id=:local");
            $stmt->execute([':id' => $exchangeId !== '' ? $exchangeId : null, ':status' => $localStatus, ':response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ':local' => $localId]);
            $this->audit('nobitex.order_submitted', ['local_id'=>$localId,'exchange_order_id'=>$exchangeId,'side'=>$side,'mode'=>$mode,'source'=>$source]);
            return ['local_id'=>$localId,'exchange'=>$response,'order'=>$order];
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare("UPDATE orders SET status='failed',error_text=:error,updated_at=UTC_TIMESTAMP() WHERE local_id=:local");
            $stmt->execute([':error'=>mb_substr($e->getMessage(),0,1000),':local'=>$localId]);
            $this->audit('nobitex.order_failed', ['local_id'=>$localId,'error'=>$e->getMessage(),'source'=>$source]);
            throw $e;
        }
    }

    public function cancel(string $orderId): array
    {
        $this->assertEnabled();
        $orderId = trim($orderId);
        $client = $this->client();
        $response = ctype_digit($orderId) ? $client->cancelOrder($orderId) : $client->cancelOrder(null, $orderId);
        $stmt = Database::connection()->prepare("UPDATE orders SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE exchange_name='nobitex' AND (exchange_order_id=:id OR identifier=:id)");
        $stmt->execute([':id'=>$orderId]);
        $this->audit('nobitex.order_cancelled', ['order_id'=>$orderId]);
        return $response;
    }

    public function normalizedOrder(array $response): array
    {
        return $this->firstOrder($response);
    }

    private function assertAllowed(array $order, string $source = 'api'): void
    {
        $this->assertEnabled();
        $pdo = Database::connection();
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0');
        if ($kill === '1') throw new \RuntimeException('Kill switch is enabled.');

        // Entry throttling is intentionally BUY-only. Exit orders, including
        // stop-loss, trailing-stop and profit-lock exits, must not be blocked
        // merely because the bot opened several positions earlier in the hour.
        $side = strtolower(trim((string) ($order['side'] ?? $order['type'] ?? '')));
        if ($side === 'buy') {
            $stmt = $pdo->prepare("SELECT value_text FROM settings WHERE key_name='nobitex_max_buy_orders_per_hour' LIMIT 1");
            $stmt->execute();
            $configured = $stmt->fetchColumn();
            $maxBuyOrders = is_numeric($configured) ? (int) $configured : 30;
            $maxBuyOrders = max(5, min(120, $maxBuyOrders));

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE exchange_name='nobitex' AND side='buy' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR) AND status IN ('submitting','submitted','filled')");
            $stmt->execute();
            $used = (int) $stmt->fetchColumn();
            if ($used >= $maxBuyOrders) {
                throw new \RuntimeException('Nobitex buy order safety limit reached.');
            }

            // Portfolio Intelligence is intentionally limited to automated BUYs.
            // Manual/API orders keep their existing safety checks, while automated
            // exits are never delayed by learning/correlation logic.
            if (str_starts_with($source, 'autotrade_nobitex')) {
                $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($order['symbol'] ?? $order['market_symbol'] ?? '')) ?? '');
                try {
                    $assessment = (new NobitexPortfolioIntelligence())->assessAutomatedBuy($pdo, $symbol);
                } catch (\Throwable $e) {
                    $this->audit('nobitex.intelligence.guard_error', [
                        'symbol'=>$symbol,
                        'source'=>$source,
                        'error'=>mb_substr($e->getMessage(), 0, 500),
                    ]);
                    throw new \RuntimeException('Portfolio intelligence unavailable; automated BUY blocked.');
                }
                if (!($assessment['allowed'] ?? false)) {
                    $reason = (string)($assessment['reason'] ?? 'portfolio_intelligence_blocked');
                    $this->audit('nobitex.intelligence.buy_blocked', [
                        'symbol'=>$symbol,
                        'source'=>$source,
                        'reason'=>$reason,
                        'assessment'=>$assessment,
                    ]);
                    throw new NobitexCandidateRejectedException($symbol, $reason, $assessment);
                }
            }
        }

        $maxValue = (float) Config::get('trading.max_order_value', 0);
        if ($maxValue > 0 && isset($order['price']) && isset($order['amount1'])) {
            if ((float) $order['price'] * (float) $order['amount1'] > $maxValue) throw new \RuntimeException('Order exceeds configured max_order_value.');
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->liveEnabled()) throw new \RuntimeException('Nobitex live trading is disabled.');
    }

    private function parseSymbol(string $symbol): array
    {
        foreach (['USDT','IRT'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                $base = substr($symbol, 0, -strlen($quote));
                if ($base === 'GRAM' || $base === 'TONCOIN') $base = 'TON';
                return [$base, $quote];
            }
        }
        return ['', ''];
    }

    private function firstOrder(array $response): array
    {
        if (is_array($response['order'] ?? null)) return $response['order'];
        if (is_array($response['orders'] ?? null) && is_array($response['orders'][0] ?? null)) return $response['orders'][0];
        return $response;
    }

    private function positive(mixed $value, string $name): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) throw new \InvalidArgumentException($name . ' must be positive.');
        return (float) $value;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    private function audit(string $event, array $context = []): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:event,:context,UTC_TIMESTAMP())');
        $stmt->execute([':event'=>$event, ':context'=>$context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
}
