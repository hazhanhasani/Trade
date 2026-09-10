<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;

final class Schema
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) return;
        $pdo = Database::connection();
        $version = (string)($pdo->query("SELECT value_text FROM settings WHERE key_name='autotrade_schema_version' LIMIT 1")->fetchColumn() ?: '0');
        if ($version === '2') { self::$ensured = true; return; }

        $statements = [
            "CREATE TABLE IF NOT EXISTS autotrade_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                quote_asset VARCHAR(20) NOT NULL DEFAULT 'IRT',
                risk_profile VARCHAR(20) NOT NULL DEFAULT 'balanced',
                position_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,
                max_position_percent DECIMAL(8,4) NOT NULL DEFAULT 10.0000,
                stop_loss_percent DECIMAL(8,4) NOT NULL DEFAULT 3.0000,
                take_profit_percent DECIMAL(8,4) NOT NULL DEFAULT 6.0000,
                daily_loss_limit_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,
                min_signal_score SMALLINT NOT NULL DEFAULT 60,
                cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
                last_trade_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_autotrade_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS autotrade_signals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                market_id BIGINT UNSIGNED NOT NULL,
                symbol VARCHAR(80) NOT NULL,
                asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
                quote_asset VARCHAR(20) NOT NULL,
                action VARCHAR(12) NOT NULL,
                score SMALLINT NOT NULL,
                price DECIMAL(36,18) NOT NULL,
                details_json LONGTEXT NULL,
                executed TINYINT(1) NOT NULL DEFAULT 0,
                order_local_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_autotrade_signal_created (created_at),
                INDEX idx_autotrade_signal_action (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS autotrade_positions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                market_id BIGINT UNSIGNED NOT NULL,
                symbol VARCHAR(80) NOT NULL,
                asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
                quote_asset VARCHAR(20) NOT NULL,
                amount DECIMAL(36,18) NOT NULL DEFAULT 0,
                entry_price DECIMAL(36,18) NOT NULL DEFAULT 0,
                stop_loss DECIMAL(36,18) NOT NULL DEFAULT 0,
                take_profit DECIMAL(36,18) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL,
                entry_identifier VARCHAR(80) NOT NULL,
                entry_order_local_id VARCHAR(64) NULL,
                entry_exchange_order_id VARCHAR(190) NULL,
                exit_identifier VARCHAR(80) NULL,
                exit_order_local_id VARCHAR(64) NULL,
                exit_exchange_order_id VARCHAR(190) NULL,
                exit_price DECIMAL(36,18) NULL,
                realized_pnl DECIMAL(36,18) NULL,
                opened_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_autotrade_position_status (status, created_at),
                UNIQUE KEY uq_autotrade_entry_identifier (entry_identifier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS autotrade_pnl (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                position_id BIGINT UNSIGNED NOT NULL,
                pnl DECIMAL(36,18) NOT NULL,
                pnl_percent DECIMAL(18,8) NOT NULL,
                quote_asset VARCHAR(20) NOT NULL,
                entry_price DECIMAL(36,18) NOT NULL,
                exit_price DECIMAL(36,18) NOT NULL,
                amount DECIMAL(36,18) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_autotrade_pnl_created (created_at),
                CONSTRAINT fk_autotrade_pnl_position FOREIGN KEY (position_id) REFERENCES autotrade_positions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS autotrade_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL,
                event_name VARCHAR(120) NOT NULL,
                context_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_autotrade_event_created (created_at),
                INDEX idx_autotrade_event_name (event_name, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach($statements as$sql)$pdo->exec($sql);

        $enabled=(bool)Config::get('trading.enabled',false)?1:0;
        $stmt=$pdo->prepare("INSERT IGNORE INTO autotrade_settings (id,enabled,quote_asset,risk_profile,position_percent,max_position_percent,stop_loss_percent,take_profit_percent,daily_loss_limit_percent,min_signal_score,cooldown_minutes,updated_at) VALUES (1,:enabled,'IRT','balanced',5,10,3,6,5,60,15,UTC_TIMESTAMP())");
        $stmt->execute([':enabled'=>$enabled]);

        // v1 used USDT as the installation default. This release intentionally
        // migrates that default to IRT for the requested IRT-first / USDT-fallback policy.
        if($version==='1'){
            $pdo->exec("UPDATE autotrade_settings SET quote_asset='IRT',updated_at=UTC_TIMESTAMP() WHERE id=1 AND quote_asset='USDT'");
        }

        $override=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('live_trading_enabled',:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=value_text");
        $override->execute([':v'=>$enabled?'1':'0']);
        $pdo->exec("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('autotrade_schema_version','2',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text='2',updated_at=UTC_TIMESTAMP()");
        self::$ensured=true;
    }

    public static function settings(?PDO $pdo=null):array
    {
        self::ensure();$pdo??=Database::connection();$row=$pdo->query('SELECT * FROM autotrade_settings WHERE id=1')->fetch();
        if(!$row)throw new \RuntimeException('Auto-trading settings row is missing.');
        return$row;
    }
}
