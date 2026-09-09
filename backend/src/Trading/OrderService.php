<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;
use Trade\Exchange\BitpinClient;
use Trade\Security\Crypto;

final class OrderService
{
    public function __construct(private readonly RiskManager $risk = new RiskManager())
    {
    }

    public function client(): BitpinClient
    {
        $pdo = Database::connection();
        $row = $pdo->query('SELECT * FROM exchange_credentials WHERE exchange_name = \'bitpin\' LIMIT 1')->fetch();
        if (!$row) {
            throw new \RuntimeException('Bitpin credentials are not configured.');
        }

        $key = (string) Config::require('app.encryption_key');
        $client = new BitpinClient([
            'base_url' => Config::get('bitpin.base_url', 'https://api.bitpin.market/api/v1'),
            'api_key' => Crypto::decrypt((string) $row['api_key_enc'], $key),
            'secret_key' => Crypto::decrypt((string) $row['secret_key_enc'], $key),
            'access_token' => $row['access_token_enc'] ? Crypto::decrypt((string) $row['access_token_enc'], $key) : null,
            'refresh_token' => $row['refresh_token_enc'] ? Crypto::decrypt((string) $row['refresh_token_enc'], $key) : null,
            'timeout' => Config::get('bitpin.timeout', 12),
            'endpoints' => Config::get('bitpin.endpoints', []),
        ]);
        return $client;
    }

    public function syncTokens(BitpinClient $client): void
    {
        $tokens = $client->tokens();
        if (!$tokens['access'] && !$tokens['refresh']) {
            return;
        }

        $key = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare(
            "UPDATE exchange_credentials SET access_token_enc=:access, refresh_token_enc=:refresh, updated_at=UTC_TIMESTAMP() WHERE exchange_name='bitpin'"
        );
        $stmt->execute([
            ':access' => $tokens['access'] ? Crypto::encrypt((string) $tokens['access'], $key) : null,
            ':refresh' => $tokens['refresh'] ? Crypto::encrypt((string) $tokens['refresh'], $key) : null,
        ]);
    }

    public function create(array $order, string $source = 'api'): array
    {
        $this->risk->assertTradingAllowed($order);
        $client = $this->client();

        $localId = bin2hex(random_bytes(12));
        $identifier = (string) ($order['identifier'] ?? ('trade-' . $localId));
        $payload = array_filter([
            'market' => $order['market'] ?? null,
            'amount1' => $order['amount1'] ?? null,
            'amount2' => $order['amount2'] ?? null,
            'price' => $order['price'] ?? null,
            'mode' => $order['mode'] ?? null,
            'type' => $order['type'] ?? null,
            'identifier' => $identifier,
            'price_limit' => $order['price_limit'] ?? null,
            'price_stop' => $order['price_stop'] ?? null,
            'price_limit_oco' => $order['price_limit_oco'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO orders (local_id, exchange_name, identifier, market_code, side, order_mode, amount, price, status, source, request_json, created_at, updated_at) VALUES (:local_id,\'bitpin\',:identifier,:market,:side,:mode,:amount,:price,\'submitting\',:source,:request_json,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':local_id' => $localId,
            ':identifier' => $identifier,
            ':market' => (string) ($order['market'] ?? ''),
            ':side' => (string) ($order['type'] ?? ''),
            ':mode' => (string) ($order['mode'] ?? ''),
            ':amount' => (string) ($order['amount1'] ?? '0'),
            ':price' => isset($order['price']) ? (string) $order['price'] : null,
            ':source' => $source,
            ':request_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $response = $client->createOrder($payload);
            $this->syncTokens($client);
            $exchangeId = (string) ($response['id'] ?? $response['order_id'] ?? '');
            $stmt = $pdo->prepare('UPDATE orders SET exchange_order_id=:eid,status=\'submitted\',response_json=:response,updated_at=UTC_TIMESTAMP() WHERE local_id=:local_id');
            $stmt->execute([
                ':eid' => $exchangeId !== '' ? $exchangeId : null,
                ':response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':local_id' => $localId,
            ]);
            $this->audit('order.submitted', ['local_id' => $localId, 'exchange_order_id' => $exchangeId, 'source' => $source]);
            return ['local_id' => $localId, 'exchange' => $response];
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare('UPDATE orders SET status=\'failed\',error_text=:error,updated_at=UTC_TIMESTAMP() WHERE local_id=:local_id');
            $stmt->execute([':error' => mb_substr($e->getMessage(), 0, 1000), ':local_id' => $localId]);
            $this->audit('order.failed', ['local_id' => $localId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function cancel(string $exchangeOrderId): array
    {
        $client = $this->client();
        $response = $client->cancelOrder($exchangeOrderId);
        $this->syncTokens($client);
        $stmt = Database::connection()->prepare('UPDATE orders SET status=\'cancelled\',updated_at=UTC_TIMESTAMP() WHERE exchange_order_id=:id');
        $stmt->execute([':id' => $exchangeOrderId]);
        $this->audit('order.cancelled', ['exchange_order_id' => $exchangeOrderId]);
        return $response;
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
