<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Config;
use Trade\Database;
use Trade\Exchange\BitpinClient;
use Trade\Security\Crypto;

/**
 * Read-only exchange session service.
 * It intentionally exposes no method that submits or cancels live exchange orders.
 */
final class OrderService
{
    public function client(): BitpinClient
    {
        $pdo = Database::connection();
        $row = $pdo->query('SELECT * FROM exchange_credentials WHERE exchange_name = \'bitpin\' LIMIT 1')->fetch();
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
            'timeout' => Config::get('bitpin.timeout', 12),
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
            "UPDATE exchange_credentials SET access_token_enc=:access, refresh_token_enc=:refresh, updated_at=UTC_TIMESTAMP() WHERE exchange_name='bitpin'"
        );
        $stmt->execute([
            ':access' => $tokens['access'] ? Crypto::encrypt((string) $tokens['access'], $key) : null,
            ':refresh' => $tokens['refresh'] ? Crypto::encrypt((string) $tokens['refresh'], $key) : null,
        ]);
    }
}
