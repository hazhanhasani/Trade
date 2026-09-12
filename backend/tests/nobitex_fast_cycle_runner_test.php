<?php

declare(strict_types=1);

function expectFastCycle(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$runner = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexFastCycleRunner.php');
$cron = file_get_contents(dirname(__DIR__) . '/cron/tick.php');
$schema = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexSchema.php');
$signal = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexInternalSignalEngine.php');
$risk = file_get_contents(dirname(__DIR__) . '/src/Trading/RiskManager.php');

foreach (['runner'=>$runner,'cron'=>$cron,'schema'=>$schema,'signal'=>$signal,'risk'=>$risk] as $name=>$source) {
    expectFastCycle(is_string($source), 'Unable to read ' . $name . ' source.');
}

expectFastCycle(str_contains($runner, "MODEL = 'nobitex_fast_cycle_v1'"), 'Fast-cycle model identifier is missing.');
expectFastCycle(str_contains($runner, 'DEFAULT_CYCLES = 4'), 'Default fast cycle count must remain four per cron minute.');
expectFastCycle(str_contains($runner, 'DEFAULT_INTERVAL_SECONDS = 12'), 'Default decision cadence must remain 12 seconds.');
expectFastCycle(str_contains($runner, 'DEFAULT_MAX_RUNTIME_SECONDS = 50'), 'Fast runner must leave cron overlap headroom.');
expectFastCycle(str_contains($runner, "GET_LOCK('trade_nobitex_fast_cycle_v1',0)"), 'Fast runner must prevent overlapping trading loops.');
expectFastCycle(str_contains($runner, 'NobitexUniverseScanner::resetProcessCache();'), 'Every fast cycle must reset process market cache for fresh order-book analysis.');
expectFastCycle(str_contains($runner, 'new NobitexPositionReconciler()'), 'Fast cycles must reconcile the real Nobitex wallet after execution.');
expectFastCycle(str_contains($runner, 'wallet_fast_cycle_reconciliation'), 'Fast-cycle telemetry must expose wallet reconciliation.');
expectFastCycle(str_contains($runner, 'syncConfirmedTrades(100, $pdo)'), 'Every fast cycle must synchronize confirmed Bale trades.');
expectFastCycle(str_contains($runner, 'flushPending(25, $pdo)'), 'Every fast cycle must retry pending Bale deliveries.');
$reconcilePos=strpos($runner,'new NobitexPositionReconciler()');
$balePos=strpos($runner,'new BaleTradeNotifier()');
expectFastCycle($reconcilePos!==false&&$balePos!==false&&$reconcilePos<$balePos,'Wallet quantity must be reconciled before Bale reads confirmed trades.');

expectFastCycle(str_contains($cron, 'use Trade\\Trading\\NobitexFastCycleRunner;'), 'Cron must import the fast-cycle runner.');
expectFastCycle(str_contains($cron, "'analysis_interval_target_seconds'=>12"), 'Cron telemetry must advertise the 12-second analysis target.');
expectFastCycle(str_contains($cron, '(new NobitexFastCycleRunner())->run($runId)'), 'Cron must execute the fast-cycle runner.');
expectFastCycle(!str_contains($cron, 'new NobitexAutoTraderEngine()'), 'Cron must not fall back to the old one-cycle-per-minute execution path.');

expectFastCycle(str_contains($schema, "'nobitex_fast_cycles_per_tick','4'"), 'Schema must seed four fast cycles.');
expectFastCycle(str_contains($schema, "'nobitex_fast_cycle_interval_seconds','12'"), 'Schema must seed the 12-second cadence.');
expectFastCycle(str_contains($schema, "'nobitex_fast_max_runtime_seconds','50'"), 'Schema must seed the bounded fast runtime.');
expectFastCycle(str_contains($schema, "cooldown_minutes=LEAST(cooldown_minutes,3)"), 'Balanced/aggressive upgrade must reduce the stale 15-minute re-entry cooldown.');
expectFastCycle(str_contains($schema, "risk_profile IN ('balanced','aggressive')"), 'Fast cooldown migration must not target safe profile.');

// Faster analysis must never mean forced churn. The profitability model still
// requires positive tradable edge after fees/slippage/uncertainty.
expectFastCycle(str_contains($signal, '$expectedNetProfit = $tradableNetEdge > 0.0;'), 'Positive post-cost tradable edge must remain mandatory.');
expectFastCycle(str_contains($signal, '$buyGate = $ready && $expectedNetProfit;'), 'BUY must still require the positive-edge gate.');
expectFastCycle(str_contains($risk, '$cooldown = max($cooldown, 30);'), 'Safe risk profile must keep its 30-minute cooldown floor.');

fwrite(STDOUT, "Nobitex fast-cycle responsiveness regression tests passed.\n");
