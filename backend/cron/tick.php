<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Trading\AutoTraderEngine;
use Trade\Updater;

if (!Config::installed()) {
    fwrite(STDERR, "Trade is not installed.\n");
    exit(2);
}

$pdo = Database::connection();
$runId = bin2hex(random_bytes(12));
$stmt = $pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");
$stmt->execute([':id' => $runId]);

try {
    $update = Updater::autoUpdateIfDue();
    $autotrade = (new AutoTraderEngine())->run();

    $summary = [
        'run_id' => $runId,
        'asset' => 'GRAM',
        'legacy_alias' => 'TON',
        'execution_mode' => 'live_only',
        'autotrade' => $autotrade,
        'kill_switch' => (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1',
        'update' => $update,
        'time_utc' => gmdate(DATE_ATOM),
    ];

    $stmt = $pdo->prepare("UPDATE bot_runs SET status='success',summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([
        ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':id' => $runId,
    ]);
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    $error = [
        'run_id' => $runId,
        'asset' => 'GRAM',
        'execution_mode' => 'live_only',
        'error' => $e->getMessage(),
        'time_utc' => gmdate(DATE_ATOM),
    ];
    $stmt = $pdo->prepare("UPDATE bot_runs SET status='failed',summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([
        ':summary' => json_encode($error, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => $runId,
    ]);
    fwrite(STDERR, json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
