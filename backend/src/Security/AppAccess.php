<?php

declare(strict_types=1);

namespace Trade\Security;

use PDO;
use Trade\Config;

final class AppAccess
{
    private const PAIR_TTL_MINUTES = 10;
    private const PAIR_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_by_admin_id BIGINT UNSIGNED NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_app_token_active (revoked_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_pairings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_id BIGINT UNSIGNED NOT NULL,
            pairing_code_hash CHAR(64) NOT NULL UNIQUE,
            token_enc LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_pairing_expiry (used_at, expires_at),
            CONSTRAINT fk_pairing_token FOREIGN KEY (token_id) REFERENCES app_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function bootstrapLegacy(PDO $pdo): void
    {
        self::ensureSchema($pdo);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM app_tokens WHERE revoked_at IS NULL')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $legacyHash = strtolower(trim((string) Config::get('app.api_token_hash', '')));
        if (preg_match('/^[a-f0-9]{64}$/', $legacyHash) !== 1) {
            return;
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO app_tokens (label,token_hash,created_at) VALUES (:label,:hash,UTC_TIMESTAMP())');
        $stmt->execute([':label' => 'Installer token', ':hash' => $legacyHash]);
    }

    public static function validate(PDO $pdo, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        self::bootstrapLegacy($pdo);
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT id FROM app_tokens WHERE token_hash=:hash AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }

        $touch = $pdo->prepare('UPDATE app_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=:id');
        $touch->execute([':id' => (int) $id]);
        return true;
    }

    public static function issueToken(PDO $pdo, string $label, ?int $adminId = null): array
    {
        self::ensureSchema($pdo);
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 120) : 'Android app';
        $token = self::base64Url(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO app_tokens (label,token_hash,created_by_admin_id,created_at) VALUES (:label,:hash,:admin,UTC_TIMESTAMP())');
        $stmt->execute([
            ':label' => $label,
            ':hash' => hash('sha256', $token),
            ':admin' => $adminId,
        ]);

        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token, 'label' => $label];
    }

    public static function createPairing(PDO $pdo, int $adminId, string $label = 'Android app'): array
    {
        self::cleanup($pdo);
        $issued = self::issueToken($pdo, $label, $adminId);
        $code = self::pairCode();
        $encrypted = Crypto::encrypt((string) $issued['token'], (string) Config::require('app.encryption_key'));

        $stmt = $pdo->prepare('INSERT INTO app_pairings (token_id,pairing_code_hash,token_enc,expires_at,created_at) VALUES (:token_id,:code_hash,:token_enc,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),UTC_TIMESTAMP())');
        $stmt->execute([
            ':token_id' => (int) $issued['id'],
            ':code_hash' => hash('sha256', self::normalizeCode($code)),
            ':token_enc' => $encrypted,
        ]);

        return [
            'code' => $code,
            'token_id' => (int) $issued['id'],
            'label' => (string) $issued['label'],
            'expires_in_seconds' => self::PAIR_TTL_MINUTES * 60,
        ];
    }

    public static function consumePairing(PDO $pdo, string $code): array
    {
        self::cleanup($pdo);
        $normalized = self::normalizeCode($code);
        if (strlen($normalized) !== 10) {
            throw new \InvalidArgumentException('کد اتصال معتبر نیست.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT p.id,p.token_id,p.token_enc,t.label FROM app_pairings p JOIN app_tokens t ON t.id=p.token_id WHERE p.pairing_code_hash=:hash AND p.used_at IS NULL AND p.expires_at>UTC_TIMESTAMP() AND t.revoked_at IS NULL LIMIT 1 FOR UPDATE');
            $stmt->execute([':hash' => hash('sha256', $normalized)]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \RuntimeException('کد اتصال منقضی شده یا قبلاً استفاده شده است.');
            }

            $token = Crypto::decrypt((string) $row['token_enc'], (string) Config::require('app.encryption_key'));
            $used = $pdo->prepare('UPDATE app_pairings SET used_at=UTC_TIMESTAMP() WHERE id=:id');
            $used->execute([':id' => (int) $row['id']]);
            $pdo->commit();

            return [
                'token' => $token,
                'token_id' => (int) $row['token_id'],
                'label' => (string) $row['label'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function revoke(PDO $pdo, int $tokenId): void
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('UPDATE app_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=:id AND revoked_at IS NULL');
        $stmt->execute([':id' => $tokenId]);
    }

    public static function tokens(PDO $pdo): array
    {
        self::bootstrapLegacy($pdo);
        return $pdo->query('SELECT id,label,last_used_at,revoked_at,created_at FROM app_tokens ORDER BY id DESC LIMIT 30')->fetchAll();
    }

    public static function activeCount(PDO $pdo): int
    {
        self::bootstrapLegacy($pdo);
        return (int) $pdo->query('SELECT COUNT(*) FROM app_tokens WHERE revoked_at IS NULL')->fetchColumn();
    }

    public static function cleanup(PDO $pdo): void
    {
        self::ensureSchema($pdo);
        $expired = $pdo->query('SELECT token_id FROM app_pairings WHERE used_at IS NULL AND expires_at<=UTC_TIMESTAMP()')->fetchAll(PDO::FETCH_COLUMN);
        if ($expired !== []) {
            $stmt = $pdo->prepare('UPDATE app_tokens SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=:id');
            foreach ($expired as $tokenId) {
                $stmt->execute([':id' => (int) $tokenId]);
            }
        }
        $pdo->exec('DELETE FROM app_pairings WHERE (used_at IS NOT NULL AND used_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) OR expires_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
    }

    private static function pairCode(): string
    {
        $alphabet = self::PAIR_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
