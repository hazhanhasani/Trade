<?php

declare(strict_types=1);

namespace Trade\MarketData;

use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;

final class MarketDataCredentialStore
{
    private const ALLOWED = ['abantether', 'bit24', 'tabdeal', 'bitpin'];
    private const SECRET_REQUIRED = ['bit24', 'bitpin'];

    public function save(string $source, string $apiKey, string $secret = ''): void
    {
        $source = $this->source($source);
        $apiKey = trim($apiKey);
        $secret = trim($secret);

        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key is required.');
        }
        if ($this->requiresSecret($source) && $secret === '') {
            throw new \InvalidArgumentException(ucfirst($source) . ' secret/private key is required together with the API key.');
        }
        if (strlen($apiKey) > 4000 || strlen($secret) > 4000) {
            throw new \InvalidArgumentException('Credential length is invalid.');
        }

        $key = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare(
            "INSERT INTO exchange_credentials
                (exchange_name,api_key_enc,secret_key_enc,access_token_enc,refresh_token_enc,created_at,updated_at)
             VALUES
                (:source,:api,:secret,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                api_key_enc=VALUES(api_key_enc),
                secret_key_enc=VALUES(secret_key_enc),
                access_token_enc=NULL,
                refresh_token_enc=NULL,
                updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([
            ':source' => $source,
            ':api' => Crypto::encrypt($apiKey, $key),
            ':secret' => Crypto::encrypt($secret, $key),
        ]);
        $this->audit($source . '.market_data_credentials_saved');
    }

    public function delete(string $source): void
    {
        $source = $this->source($source);
        $stmt = Database::connection()->prepare('DELETE FROM exchange_credentials WHERE exchange_name=:source');
        $stmt->execute([':source' => $source]);
        $this->audit($source . '.market_data_credentials_deleted');
    }

    public function configured(string $source): bool
    {
        $source = $this->source($source);
        try {
            $credentials = $this->credentials($source);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($credentials) || trim((string)($credentials['api_key'] ?? '')) === '') {
            return false;
        }
        if ($this->requiresSecret($source) && trim((string)($credentials['secret'] ?? '')) === '') {
            return false;
        }
        return true;
    }

    public function credentials(string $source): ?array
    {
        $source = $this->source($source);
        $stmt = Database::connection()->prepare(
            'SELECT api_key_enc,secret_key_enc FROM exchange_credentials WHERE exchange_name=:source LIMIT 1'
        );
        $stmt->execute([':source' => $source]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $key = (string) Config::require('app.encryption_key');
        return [
            'api_key' => Crypto::decrypt((string)$row['api_key_enc'], $key),
            'secret' => Crypto::decrypt((string)$row['secret_key_enc'], $key),
        ];
    }

    public function status(): array
    {
        $out = [];
        foreach (self::ALLOWED as $source) {
            try {
                $configured = $this->configured($source);
            } catch (\Throwable) {
                $configured = false;
            }
            $out[$source] = [
                'configured' => $configured,
                'execution_allowed' => false,
                'role' => 'market_data_only',
                'requires_secret' => $this->requiresSecret($source),
                'public_api' => in_array($source, ['bitpin', 'tabdeal'], true),
                'public_without_credentials' => in_array($source, ['bitpin', 'tabdeal'], true),
            ];
        }
        return $out;
    }

    private function source(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Unsupported market-data credential source.');
        }
        return $source;
    }

    private function requiresSecret(string $source): bool
    {
        return in_array($source, self::SECRET_REQUIRED, true);
    }

    private function audit(string $event): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:event,NULL,UTC_TIMESTAMP())'
            );
            $stmt->execute([':event' => $event]);
        } catch (\Throwable) {
        }
    }
}
