<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

final class NobitexSchema
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) return;
        Schema::ensure();
        $pdo = Database::connection();

        $statements = [
            "CREATE TABLE IF NOT EXISTS nobitex_autotrade_signals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                symbol VARCHAR(40) NOT NULL,
                asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
                quote_asset VARCHAR(20) NOT NULL,
                action VARCHAR(12) NOT NULL,
                score SMALLINT NOT NULL,
                price DECIMAL(36,18) NOT NULL,
                details_json LONGTEXT NULL,
                executed TINYINT(1) NOT NULL DEFAULT 0,
                order_local_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_nobitex_signal_created (created_at),
                INDEX idx_nobitex_signal_action (action,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS nobitex_autotrade_positions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                symbol VARCHAR(40) NOT NULL,
                asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
                quote_asset VARCHAR(20) NOT NULL,
                amount DECIMAL(36,18) NOT NULL DEFAULT 0,
                entry_price DECIMAL(36,18) NOT NULL DEFAULT 0,
                stop_loss DECIMAL(36,18) NOT NULL DEFAULT 0,
                take_profit DECIMAL(36,18) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL,
                entry_identifier VARCHAR(32) NOT NULL,
                entry_order_local_id VARCHAR(64) NULL,
                entry_exchange_order_id VARCHAR(190) NULL,
                exit_identifier VARCHAR(32) NULL,
                exit_order_local_id VARCHAR(64) NULL,
                exit_exchange_order_id VARCHAR(190) NULL,
                exit_price DECIMAL(36,18) NULL,
                realized_pnl DECIMAL(36,18) NULL,
                opened_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_nobitex_position_status (status,created_at),
                UNIQUE KEY uq_nobitex_entry_identifier (entry_identifier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS nobitex_autotrade_pnl (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                position_id BIGINT UNSIGNED NOT NULL,
                pnl DECIMAL(36,18) NOT NULL,
                pnl_percent DECIMAL(18,8) NOT NULL,
                quote_asset VARCHAR(20) NOT NULL,
                entry_price DECIMAL(36,18) NOT NULL,
                exit_price DECIMAL(36,18) NOT NULL,
                amount DECIMAL(36,18) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_nobitex_pnl_created (created_at),
                CONSTRAINT fk_nobitex_pnl_position FOREIGN KEY (position_id) REFERENCES nobitex_autotrade_positions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS nobitex_autotrade_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL,
                event_name VARCHAR(120) NOT NULL,
                context_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_nobitex_event_created (created_at),
                INDEX idx_nobitex_event_name (event_name,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) $pdo->exec($sql);

        $legacy = (bool) (Schema::settings($pdo)['enabled'] ?? false);
        self::seedSetting($pdo, 'autotrade_bitpin_enabled', $legacy ? '1' : '0');
        self::seedSetting($pdo, 'autotrade_nobitex_enabled', '0');
        self::seedSetting($pdo, 'live_trading_nobitex_enabled', '0');
        self::seedSetting($pdo, 'nobitex_schema_version', '1');
        self::$ensured = true;
    }

    public static function botEnabled(string $exchange): bool
    {
        self::ensure();
        $exchange = self::exchange($exchange);
        $key = 'autotrade_' . $exchange . '_enabled';
        $stmt = Database::connection()->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        return in_array(strtolower(trim((string) ($stmt->fetchColumn() ?: '0'))), ['1','true','yes','on'], true);
    }

    public static function setBotEnabled(string $exchange, bool $enabled): void
    {
        self::ensure();
        $exchange = self::exchange($exchange);
        $stmt = Database::connection()->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':key'=>'autotrade_' . $exchange . '_enabled', ':value'=>$enabled ? '1' : '0']);
        if ($exchange === 'bitpin') {
            // Keep legacy clients in sync with the Bitpin bot switch.
            Database::connection()->prepare('UPDATE autotrade_settings SET enabled=:v,updated_at=UTC_TIMESTAMP() WHERE id=1')->execute([':v'=>$enabled ? 1 : 0]);
        }
    }

    private static function seedSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=value_text"
        );
        $stmt->execute([':key'=>$key, ':value'=>$value]);
    }

    private static function exchange(string $exchange): string
    {
        $exchange = strtolower(trim($exchange));
        if (!in_array($exchange, ['bitpin','nobitex'], true)) throw new \InvalidArgumentException('Unsupported exchange.');
        return $exchange;
    }
}
