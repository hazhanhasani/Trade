<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Integrations\BaleTradeNotifier;
use Trade\Trading\AutoTraderEngine;
use Trade\Trading\NobitexAutoTraderEngine;
use Trade\Trading\NobitexRuntimeModels;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) {
    fwrite(STDERR, "Trade is not installed.\n");
    exit(2);
}

$heartbeatPath = dirname(__DIR__) . '/storage/cron-heartbeat.json';
$heartbeat = [
    'status' => 'running',
    'started_at' => gmdate(DATE_ATOM),
    'finished_at' => null,
    'backend_version' => Updater::currentVersion(),
    'pid' => getmypid(),
    'sapi' => PHP_SAPI,
    'php_version' => PHP_VERSION,
];
$writeHeartbeat = static function (array $payload) use ($heartbeatPath): void {
    @file_put_contents(
        $heartbeatPath,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
};
$writeHeartbeat($heartbeat);
register_shutdown_function(static function () use (&$heartbeat, $writeHeartbeat): void {
    if (($heartbeat['status'] ?? '') !== 'running') return;
    $last = error_get_last();
    $heartbeat['status'] = $last ? 'fatal' : 'terminated';
    $heartbeat['finished_at'] = gmdate(DATE_ATOM);
    if ($last) {
        $heartbeat['last_error'] = [
            'type' => (int) ($last['type'] ?? 0),
            'message' => mb_substr((string) ($last['message'] ?? ''), 0, 1000),
            'file' => (string) ($last['file'] ?? ''),
            'line' => (int) ($last['line'] ?? 0),
        ];
    }
    $writeHeartbeat($heartbeat);
});

$pdo = Database::connection();
$versionBeforeUpdate = Updater::currentVersion();
$update = Updater::autoUpdateIfDue();
$versionAfterUpdate = Updater::currentVersion();

$updatedThisProcess = ($update['status'] ?? '') === 'updated'
    && $versionAfterUpdate !== $versionBeforeUpdate
    && version_compare($versionAfterUpdate, $versionBeforeUpdate, '>');

if (($update['status'] ?? '') === 'updated' && !$updatedThisProcess) {
    $update['previous_result_status'] = 'updated';
    $update['status'] = 'up_to_date';
    $update['current_version'] = $versionAfterUpdate;
    $update['latest_version'] = (string) ($update['latest_version'] ?? $versionAfterUpdate);
    $update['replayed_update_state'] = true;
}

if ($updatedThisProcess) {
    $heartbeat['status'] = 'updated_deferred';
    $heartbeat['finished_at'] = gmdate(DATE_ATOM);
    $heartbeat['backend_version'] = $versionAfterUpdate;
    $writeHeartbeat($heartbeat);

    echo json_encode([
        'status'=>'success',
        'update'=>$update,
        'backend_version'=>$versionAfterUpdate,
        'exchanges'=>[
            'bitpin'=>['status'=>'deferred','reason'=>'backend_updated_restart_next_tick'],
            'nobitex'=>['status'=>'deferred','reason'=>'backend_updated_restart_next_tick'],
        ],
        'time_utc'=>gmdate(DATE_ATOM),
    ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

try {
    NobitexSchema::ensure();
    $runId = bin2hex(random_bytes(12));
    $stmt = $pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");
    $stmt->execute([':id' => $runId]);

    $baseSummary = [
        'run_id' => $runId,
        'strategy_mode' => NobitexRuntimeModels::STRATEGY_MODE,
        'decision_model' => NobitexRuntimeModels::DECISION,
        'selection_model' => NobitexRuntimeModels::SELECTION,
        'execution_model' => NobitexRuntimeModels::EXECUTION,
        'execution_learning_model' => NobitexRuntimeModels::EXECUTION_LEARNING,
        'global_portfolio_model' => NobitexRuntimeModels::GLOBAL_PORTFOLIO,
        'strategy_learning_model' => NobitexRuntimeModels::STRATEGY_LEARNING,
        'edge_calibration_model' => NobitexRuntimeModels::EDGE_CALIBRATION,
        'order_value_guard_model' => NobitexRuntimeModels::ORDER_VALUE_GUARD,
        'nobitex_universe' => 'all_executable_irt_usdt_spot_markets',
        'universe_awareness' => 'full_orderbook_scan_each_tick',
        'deep_analysis' => 'all_executable_markets_no_top_n_gate',
        'score_based_selection' => false,
        'signal_source' => 'nobitex_internal_1m_5m_15m',
        'tradingview_dependency' => false,
        'analysis_interval_target_seconds' => 60,
        'quote_priority' => ['IRT','USDT'],
        'execution_mode' => 'live_only',
        'update' => $update,
        'backend_version' => $versionAfterUpdate,
        'time_utc' => gmdate(DATE_ATOM),
    ];

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
        $engine->runBootstrapIfPending();
        return $engine->run();
    });

    $bale = ['status'=>'disabled'];
    try {
        $notifier = new BaleTradeNotifier();
        $baleStatus = $notifier->status($pdo);
        if (($baleStatus['enabled'] ?? false) && ($baleStatus['configured'] ?? false)) {
            $sync = $notifier->syncConfirmedTrades(50, $pdo);
            $delivery = $notifier->flushPending(10, $pdo);
            $bale = ['status'=>'ok','sync'=>$sync,'delivery'=>$delivery];
        }
    } catch (Throwable $e) {
        $bale = ['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
    }

    $overall = $failedCount === 0 ? 'success' : (($enabledCount > $failedCount) ? 'partial' : 'failed');
    $summary = $baseSummary + [
        'exchanges' => $results,
        'bale_notifications' => $bale,
        'kill_switch' => (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1',
    ];

    $stmt = $pdo->prepare("UPDATE bot_runs SET status=:status,summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([
        ':status'=>$overall,
        ':summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ':id'=>$runId,
    ]);

    $heartbeat['status'] = $overall;
    $heartbeat['finished_at'] = gmdate(DATE_ATOM);
    $heartbeat['run_id'] = $runId;
    $heartbeat['backend_version'] = $versionAfterUpdate;
    $writeHeartbeat($heartbeat);

    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($overall === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    $heartbeat['status'] = 'failed_before_summary';
    $heartbeat['finished_at'] = gmdate(DATE_ATOM);
    $heartbeat['error'] = mb_substr($e->getMessage(), 0, 1000);
    $heartbeat['backend_version'] = Updater::currentVersion();
    $writeHeartbeat($heartbeat);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
