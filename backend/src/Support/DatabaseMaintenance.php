<?php

declare(strict_types=1);

namespace Trade\Support;

use PDO;

/**
 * Low-impact maintenance for long-running shared-hosting installations.
 *
 * Financial truth tables (orders, trades, positions and PnL) are deliberately
 * never pruned here. Only derived telemetry/cache/history data is bounded.
 * One-time destructive legacy retirement is also executed here so web/API
 * requests never perform DROP/DELETE/unlink cleanup during schema ensure.
 */
final class DatabaseMaintenance
{
    private const MARKER = 'database_maintenance_last_run';
    private const INTERVAL_SECONDS = 86400;
    private const DELETE_BATCH = 5000;
    private static bool $checked = false;

    public static function runIfDue(PDO $pdo): array
    {
        if (self::$checked) return ['status'=>'checked'];
        self::$checked = true;

        try {
            if (!self::tableExists($pdo, 'settings')) return ['status'=>'deferred','reason'=>'settings_missing'];
            $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
            $stmt->execute([':key'=>self::MARKER]);
            $last = trim((string)($stmt->fetchColumn() ?: ''));
            $lastTs = $last !== '' ? strtotime($last . ' UTC') : false;
            if ($lastTs !== false && time() - $lastTs < self::INTERVAL_SECONDS) {
                return ['status'=>'not_due','last_run'=>$last];
            }

            \Trade\Trading\Schema::runLegacyCleanup($pdo);
            $indexes = self::ensureIndexes($pdo);
            $deleted = [];
            $policies = [
                ['trade_portfolio_snapshots', 'captured_at', 90, null],
                ['nobitex_autotrade_events', 'created_at', 90, null],
                ['bot_runs', 'started_at', 90, null],
                ['trade_notifications', 'created_at', 60, 'read_at IS NOT NULL'],
                ['trade_settings_history', 'created_at', 365, null],
                ['audit_logs', 'created_at', 365, null],
            ];
            foreach ($policies as [$table,$column,$days,$extra]) {
                if (!self::tableExists($pdo, $table)) continue;
                $deleted[$table] = self::deleteBatch($pdo, $table, $column, (int)$days, $extra);
            }

            $mark = $pdo->prepare(
                "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
            );
            $mark->execute([':key'=>self::MARKER]);

            return ['status'=>'ok','legacy_cleanup'=>'checked','deleted'=>$deleted,'indexes'=>$indexes,'retention_model'=>'telemetry_only_v1'];
        } catch (\Throwable $e) {
            return ['status'=>'deferred','reason'=>'maintenance_failed','message'=>mb_substr($e->getMessage(),0,240)];
        }
    }

    private static function ensureIndexes(PDO $pdo): array
    {
        $added = [];
        $definitions = [
            'orders' => [
                'idx_order_exchange_status_created' => '(exchange_name,status,created_at)',
            ],
            'bot_runs' => [
                'idx_bot_status_started' => '(status,started_at)',
            ],
            'trade_notifications' => [
                'idx_trade_notifications_read_id' => '(read_at,id)',
            ],
        ];
        foreach ($definitions as $table=>$indexes) {
            if (!self::tableExists($pdo, $table)) continue;
            foreach ($indexes as $name=>$columns) {
                if (self::indexExists($pdo, $table, $name)) continue;
                try {
                    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
                    $added[] = $table.'.'.$name;
                } catch (\Throwable $e) {
                    if (!self::indexExists($pdo, $table, $name)) throw $e;
                }
            }
        }
        return $added;
    }

    private static function deleteBatch(PDO $pdo, string $table, string $column, int $days, ?string $extra): int
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            throw new \InvalidArgumentException('Invalid maintenance identifier.');
        }
        $days = max(1, min(3650, $days));
        $where = "`{$column}` < (UTC_TIMESTAMP() - INTERVAL {$days} DAY)";
        if ($extra !== null && $extra !== '') $where .= ' AND '.$extra;
        $affected = $pdo->exec("DELETE FROM `{$table}` WHERE {$where} ORDER BY `{$column}` ASC LIMIT ".self::DELETE_BATCH);
        return $affected === false ? 0 : (int)$affected;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
        $stmt->execute([':table'=>$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:idx'
        );
        $stmt->execute([':table'=>$table,':idx'=>$index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
