<?php

declare(strict_types=1);

namespace Trade\MarketData;

use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;

/**
 * Encrypted credential storage for read-only market-data providers.
 *
 * These credentials never grant Trade permission to submit orders. Providers
 * are deliberately kept separate from the execution venue policy.
 */
final class MarketDataCredentialStore
{
    private const ALLOWED = ['abantether', 'bit24', 'tabdeal'];

    public function save(string $source, string $apiKey, string $secret = ''): void
    {
        $source = $this->source($source);
        $apiKey = trim($apiKey);
        $secret = trim($secret);
        if ($apiKey === '') throw new \InvalidArgumentException('API key is required.');
        if (strlen($apiKey) > 4000 || strlen($secret) > 4000) throw new \InvalidArgumentException('Credential length is invalid.');

        $encryptionKey = (string) Config::require('app.encryption_key');
        $stmt = Database::connection()->prepare(
            "INSERT INTO exchange_credentials
                (exchange_name,api_key_enc,secret_key_enc,access_token_enc,refresh_token_enc,created_at,updated_at)
             VALUES (:source,:api,:secret,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                api_key_enc=VALUES(api_key_enc),secret_key_enc=VALUES(secret_key_enc),
                access_token_enc=NULL,refresh_token_enc=NULL,updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([
            ':source'=>$source,
            ':api'=>Crypto::encrypt($apiKey, $encryptionKey),
            ':secret'=>Crypto::encrypt($secret, $encryptionKey),
        ]);
        $this->audit($source . '.market_data_credentials_saved');
    }

    public function delete(string $source): void
    {
        $source = $this->source($source);
        $stmt = Database::connection()->prepare('DELETE FROM exchange_credentials WHERE exchange_name=:source');
        $stmt->execute([':source'=>$source]);
        $this->audit($source . '.market_data_credentials_deleted');
    }

    public function configured(string $source): bool
    {
        $source = $this->source($source);
        $stmt = Database::connection()->prepare('SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name=:source)');
        $stmt->execute([':source'=>$source]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array{api_key:string,secret:string}|null */
    public function credentials(string $source): ?array
    {
        $source = $this->source($source);
        $stmt = Database::connection()->prepare('SELECT api_key_enc,secret_key_enc FROM exchange_credentials WHERE exchange_name=:source LIMIT 1');
        $stmt->execute([':source'=>$source]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;

        $encryptionKey = (string) Config::require('app.encryption_key');
        return [
            'api_key'=>Crypto::decrypt((string)$row['api_key_enc'], $encryptionKey),
            'secret'=>Crypto::decrypt((string)$row['secret_key_enc'], $encryptionKey),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function status(): array
    {
        $out = [];
        foreach (self::ALLOWED as $source) {
            try { $configured = $this->configured($source); }
            catch (\Throwable) { $configured = false; }
            $out[$source] = [
                'configured'=>$configured,
                'execution_allowed'=>false,
                'role'=>'market_data_only',
            ];
        }
        $out['bitpin'] = ['configured'=>true,'execution_allowed'=>false,'role'=>'public_market_data_only'];
        return $out;
    }

    private function source(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, self::ALLOWED, true)) throw new \InvalidArgumentException('Unsupported market-data credential source.');
        return $source;
    }

    private function audit(string $event): void
    {
        try {
            $stmt = Database::connection()->prepare('INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:event,NULL,UTC_TIMESTAMP())');
            $stmt->execute([':event'=>$event]);
        } catch (\Throwable) {}
    }
}
