<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Trading\AutoTraderEngine;
use Trade\Trading\NobitexAutoTraderEngine;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) {
    fwrite(STDERR, "Trade is not installed.\n");
    exit(2);
}

$pdo = Database::connection();
NobitexSchema::ensure();
$runId = bin2hex(random_bytes(12));
$stmt = $pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");
$stmt->execute([':id' => $runId]);
$update = Updater::autoUpdateIfDue();

$baseSummary = [
    'run_id' => $runId,
    'strategy_mode' => 'nobitex_internal_mtf_portfolio',
    'nobitex_universe' => 'all_eligible_spot_markets',
    'universe_awareness' => 'full_orderbook_prescan_each_tick',
    'deep_analysis' => 'top_20_liquid_markets',
    'signal_source' => 'nobitex_internal_1m_5m_15m',
    'tradingview_dependency' => false,
    'analysis_interval_target_seconds' => 60,
    'quote_priority' => ['IRT','USDT'],
    'execution_mode' => 'live_only',
    'update' => $update,
    'backend_version' => Updater::currentVersion(),
    'time_utc' => gmdate(DATE_ATOM),
];

// Do not execute live orders in the PHP process that just replaced backend files.
if (($update['status'] ?? '') === 'updated') {
    $summary = $baseSummary + [
        'exchanges' => [
            'bitpin' => ['status'=>'deferred','reason'=>'backend_updated_restart_next_tick'],
            'nobitex' => ['status'=>'deferred','reason'=>'backend_updated_restart_next_tick'],
        ],
    ];
    $stmt = $pdo->prepare("UPDATE bot_runs SET status='success',summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([':summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),':id'=>$runId]);
    echo json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$results = [];
$enabledCount = 0;
$failedCount = 0;

$runExchange = static function (string $exchange, callable $runner) use (&$results, &$enabledCount, &$failedCount): void {
    if (!NobitexSchema::botEnabled($exchange)) {
        $results[$exchange] = ['status'=>'disabled','exchange'=>$exchange];
        return;
    }
    $enabledCount++;
    try {
        $results[$exchange] = $runner();
    } catch (Throwable $e) {
        $failedCount++;
        $results[$exchange] = ['status'=>'failed','exchange'=>$exchange,'error'=>$e->getMessage()];
    }
};

$runExchange('bitpin', static fn(): array => (new AutoTraderEngine())->run());
$runExchange('nobitex', static function (): array {
    $engine = new NobitexAutoTraderEngine();
    $first = $engine->runBootstrapIfPending();
    if (($first['status'] ?? '') !== 'not_pending') return $first;
    return $engine->run();
});

$overall = $failedCount === 0 ? 'success' : (($enabledCount > $failedCount) ? 'partial' : 'failed');
$summary = $baseSummary + [
    'exchanges' => $results,
    'kill_switch' => (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1',
];

$stmt = $pdo->prepare("UPDATE bot_runs SET status=:status,summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
$stmt->execute([
    ':status'=>$overall,
    ':summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ':id'=>$runId,
]);

echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . PHP_EOL;
exit($overall === 'failed' ? 1 : 0);
