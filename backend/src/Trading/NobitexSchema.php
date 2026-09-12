<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;

final class NobitexSchema
{
    private static bool $ensured=false;

    public static function ensure():void
    {
        if(self::$ensured)return;
        Schema::ensure();
        $pdo=Database::connection();
        $previous=(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='nobitex_schema_version' LIMIT 1")->fetchColumn()?:'0');

        $statements=[
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
                entry_fee_quote DECIMAL(36,18) NULL,
                entry_fee_source VARCHAR(24) NULL,
                estimated_exit_fee_quote DECIMAL(36,18) NULL,
                total_fees_quote DECIMAL(36,18) NULL,
                gross_realized_pnl DECIMAL(36,18) NULL,
                net_realized_pnl DECIMAL(36,18) NULL,
                mark_price DECIMAL(36,18) NULL,
                peak_price DECIMAL(36,18) NULL,
                trailing_stop DECIMAL(36,18) NULL,
                unrealized_gross_pnl DECIMAL(36,18) NULL,
                unrealized_net_pnl DECIMAL(36,18) NULL,
                unrealized_net_pnl_percent DECIMAL(18,8) NULL,
                highest_net_pnl_percent DECIMAL(18,8) NULL,
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
                gross_pnl DECIMAL(36,18) NULL,
                entry_fee_quote DECIMAL(36,18) NULL,
                exit_fee_quote DECIMAL(36,18) NULL,
                total_fees_quote DECIMAL(36,18) NULL,
                net_pnl DECIMAL(36,18) NULL,
                fee_source VARCHAR(24) NULL,
                accounted_at DATETIME NULL,
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
        foreach($statements as$sql)$pdo->exec($sql);

        if(!in_array($previous,['4','5'],true)){
            self::ensureColumn($pdo,'nobitex_autotrade_positions','entry_fee_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','entry_fee_source','VARCHAR(24) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','estimated_exit_fee_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','total_fees_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','gross_realized_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','net_realized_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','mark_price','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','peak_price','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','trailing_stop','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','unrealized_gross_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','unrealized_net_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','unrealized_net_pnl_percent','DECIMAL(18,8) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_positions','highest_net_pnl_percent','DECIMAL(18,8) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','gross_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','entry_fee_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','exit_fee_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','total_fees_quote','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','net_pnl','DECIMAL(36,18) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','fee_source','VARCHAR(24) NULL');
            self::ensureColumn($pdo,'nobitex_autotrade_pnl','accounted_at','DATETIME NULL');
        }

        self::seedSetting($pdo,'autotrade_nobitex_enabled','0');
        self::seedSetting($pdo,'live_trading_nobitex_enabled','0');
        self::seedSetting($pdo,'nobitex_bootstrap_first_buy_pending','0');
        self::seedSetting($pdo,'nobitex_bootstrap_first_buy_id','trade-first-v113');
        self::seedSetting($pdo,'nobitex_max_positions','5');
        self::seedSetting($pdo,'nobitex_scan_limit','20');
        self::seedSetting($pdo,'nobitex_portfolio_exposure_percent','60');
        self::seedSetting($pdo,'nobitex_analysis_interval_seconds','12');
        self::seedSetting($pdo,'nobitex_fast_cycles_per_tick','4');
        self::seedSetting($pdo,'nobitex_fast_cycle_interval_seconds','12');
        self::seedSetting($pdo,'nobitex_fast_max_runtime_seconds','50');
        self::seedSetting($pdo,'nobitex_signal_source','internal_mtf_1m_5m_15m');
        self::seedSetting($pdo,'nobitex_taker_fee_irt_percent','0.25');
        self::seedSetting($pdo,'nobitex_taker_fee_usdt_percent','0.13');
        self::seedSetting($pdo,'nobitex_trailing_enabled','1');
        self::seedSetting($pdo,'nobitex_trailing_activation_net_percent','0.85');
        self::seedSetting($pdo,'nobitex_trailing_distance_percent','0.65');
        self::seedSetting($pdo,'nobitex_profit_lock_net_percent','0.15');
        self::seedSetting($pdo,'nobitex_rotation_enabled','1');
        self::seedSetting($pdo,'nobitex_rotation_min_advantage_percent','0.75');
        self::seedSetting($pdo,'nobitex_rotation_min_hold_minutes','45');
        self::seedSetting($pdo,'nobitex_rotation_cooldown_minutes','30');
        self::seedSetting($pdo,'nobitex_rotation_max_loss_percent','0.75');
        self::seedSetting($pdo,'nobitex_rotation_friction_margin_percent','0.15');

        if($previous==='1'&&self::isTargetProductionInstall()&&self::canArmExistingInstallation($pdo)){
            self::writeSetting($pdo,'nobitex_bootstrap_first_buy_pending','1');
            self::writeSetting($pdo,'nobitex_bootstrap_first_buy_armed_at',gmdate('Y-m-d H:i:s'));
        }
        if(!in_array($previous,['3','4','5'],true)){
            self::writeSetting($pdo,'tradingview_enabled','0');
            self::writeSetting($pdo,'nobitex_scan_limit','20');
            self::writeSetting($pdo,'nobitex_signal_source','internal_mtf_1m_5m_15m');
        }

        if($previous!=='5'){
            // Existing balanced/aggressive installations were deliberately
            // conservative at 15 minutes. Fast-cycle mode reduces only this
            // re-entry cooldown; Safe mode remains governed by RiskManager's
            // 30-minute minimum. Economic edge/risk gates are untouched.
            try{
                $pdo->exec("UPDATE nobitex_autotrade_settings SET cooldown_minutes=LEAST(cooldown_minutes,3),updated_at=UTC_TIMESTAMP() WHERE risk_profile IN ('balanced','aggressive')");
            }catch(\Throwable){}
            self::writeSetting($pdo,'nobitex_analysis_interval_seconds','12');
            self::writeSetting($pdo,'nobitex_fast_cycles_per_tick','4');
            self::writeSetting($pdo,'nobitex_fast_cycle_interval_seconds','12');
            self::writeSetting($pdo,'nobitex_fast_max_runtime_seconds','50');
        }

        self::writeSetting($pdo,'nobitex_schema_version','5');
        self::$ensured=true;
    }

    public static function botEnabled(string $exchange='nobitex'):bool
    {
        self::ensure();self::executionExchange($exchange);
        $stmt=Database::connection()->prepare("SELECT value_text FROM settings WHERE key_name='autotrade_nobitex_enabled' LIMIT 1");$stmt->execute();
        return in_array(strtolower(trim((string)($stmt->fetchColumn()?:'0'))),['1','true','yes','on'],true);
    }

    public static function setBotEnabled(string $exchange,bool $enabled):void
    {
        self::ensure();self::executionExchange($exchange);
        self::writeSetting(Database::connection(),'autotrade_nobitex_enabled',$enabled?'1':'0');
    }

    private static function canArmExistingInstallation(PDO $pdo):bool
    {
        $credential=(bool)$pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='nobitex')")->fetchColumn();
        $bot=self::settingIsTrue($pdo,'autotrade_nobitex_enabled');
        $live=self::settingIsTrue($pdo,'live_trading_nobitex_enabled');
        $kill=self::settingIsTrue($pdo,'kill_switch');
        $active=(int)$pdo->query("SELECT COUNT(*) FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close')")->fetchColumn();
        $accepted=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE exchange_name='nobitex' AND status IN ('submitting','submitted','filled')")->fetchColumn();
        return$credential&&$bot&&$live&&!$kill&&$active===0&&$accepted===0;
    }

    private static function isTargetProductionInstall():bool
    {
        $host=strtolower((string)(parse_url((string)Config::get('app.url',''),PHP_URL_HOST)?:''));
        return$host==='rado-taxi.sbs';
    }

    private static function settingIsTrue(PDO $pdo,string $key):bool
    {
        $s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);
        return in_array(strtolower(trim((string)($s->fetchColumn()?:'0'))),['1','true','yes','on'],true);
    }

    private static function seedSetting(PDO $pdo,string $key,string $value):void
    {
        $stmt=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=value_text");$stmt->execute([':key'=>$key,':value'=>$value]);
    }

    private static function writeSetting(PDO $pdo,string $key,string $value):void
    {
        $stmt=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$stmt->execute([':key'=>$key,':value'=>$value]);
    }

    private static function ensureColumn(PDO $pdo,string $table,string $column,string $definition):void
    {
        if(!preg_match('/^[a-z0-9_]+$/i',$table)||!preg_match('/^[a-z0-9_]+$/i',$column))throw new \InvalidArgumentException('Invalid schema identifier.');
        $quoted=$pdo->quote($column);$stmt=$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quoted}");if($stmt&&$stmt->fetch())return;
        try{$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");}
        catch(\PDOException $e){$check=$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quoted}");if($check&&$check->fetch())return;throw$e;}
    }

    private static function executionExchange(string $exchange):void
    {
        if(strtolower(trim($exchange))!=='nobitex')throw new \InvalidArgumentException('Nobitex is the only execution exchange.');
    }
}
