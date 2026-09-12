<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;

/** Canonical shared settings and one-time retirement cleanup for execution. */
final class Schema
{
    private const CLEANUP_VERSION='3';
    private static bool $ensured=false;

    public static function ensure():void
    {
        if(self::$ensured)return;$pdo=Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS nobitex_autotrade_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,quote_asset VARCHAR(20) NOT NULL DEFAULT 'IRT',risk_profile VARCHAR(20) NOT NULL DEFAULT 'balanced',position_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,max_position_percent DECIMAL(8,4) NOT NULL DEFAULT 10.0000,stop_loss_percent DECIMAL(8,4) NOT NULL DEFAULT 3.0000,take_profit_percent DECIMAL(8,4) NOT NULL DEFAULT 6.0000,daily_loss_limit_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,min_signal_score SMALLINT NOT NULL DEFAULT 60,cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,last_trade_at DATETIME NULL,updated_at DATETIME NOT NULL,INDEX idx_nobitex_autotrade_settings_enabled (enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if(self::tableExists($pdo,'autotrade_settings')){$legacy=$pdo->query('SELECT * FROM autotrade_settings WHERE id=1 LIMIT 1')->fetch();if(is_array($legacy)){$stmt=$pdo->prepare("INSERT INTO nobitex_autotrade_settings (id,enabled,quote_asset,risk_profile,position_percent,max_position_percent,stop_loss_percent,take_profit_percent,daily_loss_limit_percent,min_signal_score,cooldown_minutes,last_trade_at,updated_at) VALUES (1,0,:quote,:profile,:position,:max_position,:stop,:take,:daily,:score,:cooldown,:last_trade,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE quote_asset=VALUES(quote_asset),risk_profile=VALUES(risk_profile),position_percent=VALUES(position_percent),max_position_percent=VALUES(max_position_percent),stop_loss_percent=VALUES(stop_loss_percent),take_profit_percent=VALUES(take_profit_percent),daily_loss_limit_percent=VALUES(daily_loss_limit_percent),min_signal_score=VALUES(min_signal_score),cooldown_minutes=VALUES(cooldown_minutes),last_trade_at=VALUES(last_trade_at),updated_at=UTC_TIMESTAMP()");$quote=strtoupper((string)($legacy['quote_asset']??'IRT'));$stmt->execute([':quote'=>in_array($quote,['IRT','USDT'],true)?$quote:'IRT',':profile'=>(string)($legacy['risk_profile']??'balanced'),':position'=>(float)($legacy['position_percent']??5),':max_position'=>(float)($legacy['max_position_percent']??10),':stop'=>(float)($legacy['stop_loss_percent']??3),':take'=>(float)($legacy['take_profit_percent']??6),':daily'=>(float)($legacy['daily_loss_limit_percent']??5),':score'=>(int)($legacy['min_signal_score']??60),':cooldown'=>(int)($legacy['cooldown_minutes']??15),':last_trade'=>$legacy['last_trade_at']??null]);}}
        $pdo->exec("INSERT IGNORE INTO nobitex_autotrade_settings (id,enabled,quote_asset,risk_profile,position_percent,max_position_percent,stop_loss_percent,take_profit_percent,daily_loss_limit_percent,min_signal_score,cooldown_minutes,updated_at) VALUES (1,0,'IRT','balanced',5,10,3,6,5,60,15,UTC_TIMESTAMP())");
        self::ensureConfiguredRuntimeDefaults($pdo);
        self::cleanupLegacyExecution($pdo);self::$ensured=true;
    }

    public static function settings(?PDO $pdo=null):array{self::ensure();$pdo??=Database::connection();$row=$pdo->query('SELECT * FROM nobitex_autotrade_settings WHERE id=1')->fetch();if(!$row)throw new \RuntimeException('Nobitex auto-trading settings row is missing.');return$row;}

    private static function ensureConfiguredRuntimeDefaults(PDO $pdo):void
    {
        // The installer stores max_orders_per_hour in protected config. Older
        // releases never copied it into the DB key used by NobitexOrderService,
        // so the runtime silently fell back to 30. Seed the real guard exactly
        // once while preserving any later Admin/user override.
        $maxOrders=max(5,min(120,(int)Config::get('trading.max_orders_per_hour',30)));
        $stmt=$pdo->prepare("INSERT IGNORE INTO settings (key_name,value_text,updated_at) VALUES ('nobitex_max_buy_orders_per_hour',:value,UTC_TIMESTAMP())");
        $stmt->execute([':value'=>(string)$maxOrders]);
    }

    private static function cleanupLegacyExecution(PDO $pdo):void
    {
        $stmt=$pdo->prepare("SELECT value_text FROM settings WHERE key_name='legacy_execution_cleanup_version' LIMIT 1");$stmt->execute();
        if((string)($stmt->fetchColumn()?:'')===self::CLEANUP_VERSION){self::removeObsoleteFiles();self::cleanupStoredConfig();return;}
        try{$pdo->exec("DELETE t FROM trades t INNER JOIN orders o ON o.exchange_order_id=t.exchange_order_id WHERE o.exchange_name='bitpin'");}catch(\Throwable){}
        try{$pdo->exec("DELETE FROM trades WHERE LOWER(COALESCE(raw_json,'')) LIKE '%bitpin%'");}catch(\Throwable){}
        $pdo->exec("DELETE FROM orders WHERE exchange_name='bitpin'");$pdo->exec("DELETE FROM exchange_credentials WHERE exchange_name='bitpin'");$pdo->exec("DELETE FROM settings WHERE LOWER(key_name) LIKE '%bitpin%' OR key_name IN ('live_trading_enabled','autotrade_schema_version')");
        try{$pdo->exec("DELETE FROM audit_logs WHERE LOWER(event_name) LIKE '%bitpin%' OR LOWER(COALESCE(context_json,'')) LIKE '%bitpin%'");}catch(\Throwable){}
        // Keep modern Nobitex runs that merely list Bitpin as a read-only market source.
        try{$pdo->exec("DELETE FROM bot_runs WHERE LOWER(COALESCE(summary_json,'')) LIKE '%\"execution_exchange\":\"bitpin\"%' OR LOWER(COALESCE(summary_json,'')) LIKE '%\"exchanges\":{\"bitpin\"%'");}catch(\Throwable){}
        foreach(['autotrade_pnl','autotrade_events','autotrade_signals','autotrade_positions','autotrade_settings','bitpin_market_history_cache']as$table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
        self::removeObsoleteFiles();self::cleanupStoredConfig();$mark=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('legacy_execution_cleanup_version',:version,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$mark->execute([':version'=>self::CLEANUP_VERSION]);
    }

    private static function tableExists(PDO $pdo,string $table):bool{$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');$stmt->execute([':table'=>$table]);return(int)$stmt->fetchColumn()>0;}
    private static function removeObsoleteFiles():void{if(!defined('TRADE_ROOT'))return;foreach(['src/Exchange/BitpinClient.php','src/Trading/AutoTraderEngine.php','src/Trading/OrderService.php','src/Trading/MarketScanner.php']as$relative){$path=TRADE_ROOT.'/'.$relative;if(is_file($path)&&!@unlink($path))throw new \RuntimeException('Unable to remove obsolete execution file: '.$relative);}}
    private static function cleanupStoredConfig():void
    {
        if(!defined('TRADE_ROOT'))return;$file=TRADE_ROOT.'/storage/config.php';if(!is_file($file))return;$config=require$file;if(!is_array($config))return;$changed=false;if(array_key_exists('bitpin',$config)){unset($config['bitpin']);$changed=true;}if(isset($config['trading'])&&is_array($config['trading'])&&array_key_exists('enabled',$config['trading'])){unset($config['trading']['enabled']);$changed=true;}if(!$changed)return;$payload="<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($config,true).";\n";$tmp=$file.'.cleanup-'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$payload,LOCK_EX)===false)throw new \RuntimeException('Unable to rewrite protected configuration during cleanup.');@chmod($tmp,0600);if(!@rename($tmp,$file)){@unlink($tmp);throw new \RuntimeException('Unable to replace protected configuration during cleanup.');}@chmod($file,0600);
    }
}
