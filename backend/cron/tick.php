<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;

if (!Config::installed()) {
    fwrite(STDERR, "Trade is not installed.\n");
    exit(2);
}

$pdo = Database::connection();
$runId = bin2hex(random_bytes(12));
$stmt = $pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");
$stmt->execute([':id' => $runId]);

try {
    // Phase 1 deliberately keeps strategy evaluation isolated from the scheduler.
    // Strategy workers will consume enabled strategy_rules and write signals here.
    // The execution path already exists in OrderService and remains protected by RiskManager.
    $summary = [
        'run_id' => $runId,
        'strategies_enabled' => (int) $pdo->query('SELECT COUNT(*) FROM strategy_rules WHERE enabled=1')->fetchColumn(),
        'queued_signals' => (int) $pdo->query('SELECT COUNT(*) FROM signals WHERE consumed_at IS NULL')->fetchColumn(),
        'kill_switch' => (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0'),
    ];

    $stmt = $pdo->prepare("UPDATE bot_runs SET status='ok',summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([
        ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ':id' => $runId,
    ]);
    echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    $stmt = $pdo->prepare("UPDATE bot_runs SET status='failed',summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([
        ':summary' => json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
        ':id' => $runId,
    ]);
    throw $e;
}
