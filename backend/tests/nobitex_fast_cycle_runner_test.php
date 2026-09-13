<?php

declare(strict_types=1);

function expectFastCycle(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$runner = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexFastCycleRunner.php');
$dust = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexResidualDustManager.php');
$dustConverter = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexDustConverter.php');
$cron = file_get_contents(dirname(__DIR__) . '/cron/tick.php');
$schema = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexSchema.php');
$signal = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexInternalSignalEngine.php');
$risk = file_get_contents(dirname(__DIR__) . '/src/Trading/RiskManager.php');

foreach (['runner'=>$runner,'dust'=>$dust,'dustConverter'=>$dustConverter,'cron'=>$cron,'schema'=>$schema,'signal'=>$signal,'risk'=>$risk] as $name=>$source) {
    expectFastCycle(is_string($source), 'Unable to read ' . $name . ' source.');
}

expectFastCycle(str_contains($runner, "MODEL = 'nobitex_fast_cycle_v2'"), 'Fast-cycle v2 model identifier is missing.');
expectFastCycle(str_contains($runner, 'DEFAULT_CYCLES = 4'), 'Default fast cycle count must remain four per cron minute.');
expectFastCycle(str_contains($runner, 'DEFAULT_INTERVAL_SECONDS = 12'), 'Default decision cadence must remain 12 seconds.');
expectFastCycle(str_contains($runner, 'DEFAULT_MAX_RUNTIME_SECONDS = 50'), 'Fast runner must leave cron overlap headroom.');
expectFastCycle(str_contains($runner, "GET_LOCK('trade_nobitex_fast_cycle_v1',0)"), 'Fast runner must prevent overlapping trading loops.');
expectFastCycle(str_contains($runner, 'NobitexUniverseScanner::resetProcessCache();'), 'Every fast cycle must reset process market cache for fresh order-book analysis.');
expectFastCycle(str_contains($runner, 'new NobitexPositionReconciler()'), 'Fast cycles must reconcile the real Nobitex wallet.');
expectFastCycle(str_contains($runner, 'containsBuySubmission($last)'), 'Post-cycle wallet reconciliation must run only after BUY-producing cycles.');
expectFastCycle(!str_contains($runner, "SELECT COUNT(*) FROM nobitex_autotrade_positions WHERE status='open'"), 'HOLD/SELL cycles must not perform redundant post-cycle wallet reconciliation merely because an old position is open.');
expectFastCycle(str_contains($runner, 'wallet_fast_cycle_reconciliation'), 'Fast-cycle telemetry must expose wallet reconciliation.');
expectFastCycle(str_contains($runner, 'syncConfirmedTrades(100, $pdo)'), 'Every fast cycle must synchronize confirmed Bale trades.');
expectFastCycle(str_contains($runner, 'flushPending(25, $pdo)'), 'Every fast cycle must retry pending Bale deliveries.');
$reconcilePos=strpos($runner,'new NobitexPositionReconciler()');
$balePos=strpos($runner,'new BaleTradeNotifier()');
expectFastCycle($reconcilePos!==false&&$balePos!==false&&$reconcilePos<$balePos,'Wallet quantity must be reconciled before Bale reads confirmed trades.');

// Manual/external sales must be reconciled before the first live decision so a
// completely sold position releases its slot and can be replaced immediately.
expectFastCycle(str_contains($runner, 'new NobitexExternalTradeReconciler()'), 'Fast runner must detect manual Nobitex SELLs before live decisions.');
$externalPos=strpos($runner,'new NobitexExternalTradeReconciler()');
$enginePos=strpos($runner,'new NobitexAutoTraderEngine()');
expectFastCycle($externalPos!==false&&$enginePos!==false&&$externalPos<$enginePos,'Manual SELL reconciliation must run before entry selection.');
expectFastCycle($reconcilePos!==false&&$enginePos!==false&&$reconcilePos<$enginePos,'Wallet fallback reconciliation must release externally sold positions before entry selection.');
expectFastCycle(str_contains($runner, 'replacement_slots_released'), 'Fast-cycle telemetry must expose replacement capacity released by manual sales.');

// Candidate-level safety/backpressure is a normal no-trade decision, not a cron
// crash. The hourly BUY limit in particular must not emit a false red failure.
expectFastCycle(str_contains($runner, 'NobitexCandidateRejectedException'), 'Candidate rejections must have an explicit non-fatal path.');
expectFastCycle(str_contains($runner, "'buy_hourly_safety_limit_reached'"), 'Hourly BUY throttle must be classified as a safe no-trade reason.');
expectFastCycle(str_contains($runner, "'expected_rejection'=>true"), 'Expected safety rejections must be marked as expected telemetry.');
expectFastCycle(str_contains($runner, "trim($e->getMessage()) === 'Nobitex buy order safety limit reached.'"), 'Legacy hourly safety exception must be normalized instead of reported as cron failure.');

// Exchange-minimum residuals, previous partial exits and amount-step leftovers
// from a supposedly completed SELL must not turn into permanent tiny holdings.
expectFastCycle(str_contains($runner, 'new NobitexResidualDustManager()'), 'Fast runner must reconcile residual dust before live decisions.');
expectFastCycle(str_contains($runner, 'residual_dust_reconciliation'), 'Fast-cycle telemetry must expose residual dust reconciliation.');
$dustPos=strpos($runner,'new NobitexResidualDustManager()');
expectFastCycle($dustPos!==false&&$enginePos!==false&&$dustPos<$enginePos,'Residual dust must release blocked capacity before the first live decision cycle.');
expectFastCycle(str_contains($dust, "MODEL = 'nobitex_residual_dust_manager_v3'"), 'Residual dust manager model marker is missing.');
expectFastCycle(str_contains($dust, "SET status='dust'"), 'Unsellable residual positions must leave active status.');
expectFastCycle(str_contains($dust, "WHERE status='dust'"), 'Residual dust must remain separately traceable for later recovery.');
expectFastCycle(str_contains($dust, 'dustAssessment('), 'Residual dust must be classified from exchange order rules.');
expectFastCycle(str_contains($dust, "'below_exchange_minimum'"), 'Exchange-minimum residual classification is missing.');
expectFastCycle(str_contains($dust, "'amount_below_exchange_step'"), 'Exchange amount-step residual classification is missing.');
expectFastCycle(str_contains($dust, 'capacity_released'), 'Residual retirement must explicitly report released trading capacity.');
expectFastCycle(str_contains($dust, "'pnl_recorded'=>false"), 'Dust bookkeeping must never fabricate realized PnL.');
expectFastCycle(str_contains($dust, "'autotrade_nobitex_dust_sweep'"), 'Sellable residuals must use guarded reduction-only Nobitex execution.');
expectFastCycle(str_contains($dust, 'MAX_SWEEPS_PER_RUN = 1'), 'Residual cleanup must not stack multiple ambiguous SELL submissions in one pass.');
expectFastCycle(str_contains($dust, 'exit_fill_count'), 'Residual manager must detect positions that already had a confirmed partial exit.');
expectFastCycle(str_contains($dust, 'partial_exit_residual_cleanup'), 'Sellable partial-exit leftovers must continue their original exit instead of becoming permanent tiny holdings.');
expectFastCycle(str_contains($dust, 'last_exit_amount'), 'Closed positions must be checked for final SELL amount-step leftovers.');
expectFastCycle(str_contains($dust, 'closed_residual_recovered'), 'Known bot-owned wallet leftovers must be recovered from incorrectly closed rows.');
expectFastCycle(str_contains($dust, 'final_sell_amount_step_residual'), 'Recovered closed dust must name the exact rounding cause.');
expectFastCycle(str_contains($dust, "status IN ('pending_open','open','pending_close','dust')"), 'Closed residual recovery must subtract every already assigned managed quantity.');
expectFastCycle(str_contains($dust, "WHERE id=:id AND status=:expected_status"), 'Residual cleanup must transition only the expected managed state.');

// Only bot-owned dust may be converted to Toman. A user's unrelated idle wallet
// holdings must never be swept. Pending conversion rows are isolated from normal
// residual cleanup to prevent duplicate/cross-quote SELLs.
expectFastCycle(str_contains($dustConverter, "MODEL = 'nobitex_managed_dust_to_toman_v2'"), 'Managed dust-to-Toman model marker is missing.');
expectFastCycle(str_contains($dustConverter, "WHERE status='dust'"), 'Dust converter must source quantities from managed Trade dust rows.');
expectFastCycle(str_contains($dustConverter, "status='dust_converting'"), 'Pending dust conversion must use an isolated state.');
expectFastCycle(str_contains($dustConverter, "'dstCurrency'=>'rls'"), 'Managed dust must be converted to the IRT/Toman quote.');
expectFastCycle(str_contains($dustConverter, "'nobitex_dust_converter'"), 'Managed conversion must retain a dedicated order source.');
expectFastCycle(str_contains($dustConverter, 'prepareOrder(['), 'Dust conversion must preflight live Nobitex minimum/step rules before submitting.');
expectFastCycle(str_contains($dustConverter, "'below_exchange_minimum'"), 'Unsellable dust must be deferred without becoming a runtime error.');
expectFastCycle(str_contains($dustConverter, "'pnl_recorded'=>false"), 'Cross-quote dust conversion must not fabricate strategy PnL.');
expectFastCycle(!str_contains($dustConverter, 'idle_assets_only_no_open_position'), 'v2 must not scan arbitrary user idle assets.');

expectFastCycle(str_contains($cron, 'use Trade\\Trading\\NobitexFastCycleRunner;'), 'Cron must import the fast-cycle runner.');
expectFastCycle(str_contains($cron, "'analysis_interval_target_seconds'=>12"), 'Cron telemetry must advertise the 12-second analysis target.');
expectFastCycle(str_contains($cron, '(new NobitexFastCycleRunner())->run($runId)'), 'Cron must execute the fast-cycle runner.');
expectFastCycle(!str_contains($cron, 'new NobitexAutoTraderEngine()'), 'Cron must not fall back to the old one-cycle-per-minute execution path.');

expectFastCycle(str_contains($schema, "'nobitex_fast_cycles_per_tick','4'"), 'Schema must seed four fast cycles.');
expectFastCycle(str_contains($schema, "'nobitex_fast_cycle_interval_seconds','12'"), 'Schema must seed the 12-second cadence.');
expectFastCycle(str_contains($schema, "'nobitex_fast_max_runtime_seconds','50'"), 'Schema must seed the bounded fast runtime.');
expectFastCycle(str_contains($schema, "cooldown_minutes=LEAST(cooldown_minutes,3)"), 'Balanced/aggressive upgrade must reduce the stale 15-minute re-entry cooldown.');
expectFastCycle(str_contains($schema, "risk_profile IN ('balanced','aggressive')"), 'Fast cooldown migration must not target safe profile.');

// Faster analysis must never mean forced churn. Profit-First and every routed
// live strategy must independently remain positive after execution costs and
// residual uncertainty before the engine may enter the BUY branch.
expectFastCycle(str_contains($signal, '$expectedNetProfit = $tradableNetEdge > 0.0;'), 'Profit-First positive post-cost tradable edge must remain mandatory.');
expectFastCycle(str_contains($signal, '$profitBuyGate = $ready && $expectedNetProfit;'), 'Profit-First BUY must keep the positive-edge gate.');
expectFastCycle(str_contains($signal, '$routedTradableNetEdge > 0.0;'), 'Routed BUY must keep a positive post-cost/post-buffer edge gate.');
expectFastCycle(str_contains($signal, 'elseif ($profitBuyGate || $routedBuyGate)'), 'Live BUY branch must be reachable only through a validated positive-edge strategy gate.');
expectFastCycle(str_contains($risk, '$cooldown = max($cooldown, 30);'), 'Safe risk profile must keep its 30-minute cooldown floor.');

fwrite(STDOUT, "Nobitex fast-cycle responsiveness regression tests passed.\n");
