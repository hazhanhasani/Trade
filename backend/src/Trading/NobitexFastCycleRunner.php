<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Integrations\BaleTradeNotifier;
use Trade\Observability\ErrorReporter;

/**
 * Runs several short Nobitex decision cycles inside one ordinary cPanel cron minute.
 *
 * This improves reaction time without creating a second execution venue or
 * weakening the positive post-cost edge/risk gates. A process-wide DB lock
 * prevents overlapping cron invocations from running two trading loops at once.
 */
final class NobitexFastCycleRunner
{
    public const MODEL = 'nobitex_fast_cycle_v2';
    public const DEFAULT_CYCLES = 4;
    public const DEFAULT_INTERVAL_SECONDS = 12;
    public const DEFAULT_MAX_RUNTIME_SECONDS = 50;

    public function run(string $runId = ''): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        if ((int)$pdo->query("SELECT GET_LOCK('trade_nobitex_fast_cycle_v1',0)")->fetchColumn() !== 1) {
            return [
                'status'=>'skipped',
                'exchange'=>'nobitex',
                'reason'=>'another_fast_cycle_runner_is_active',
                'fast_cycle_model'=>self::MODEL,
            ];
        }

        try {
            $cycles = $this->intSetting($pdo, 'nobitex_fast_cycles_per_tick', self::DEFAULT_CYCLES, 1, 6);
            $interval = $this->intSetting($pdo, 'nobitex_fast_cycle_interval_seconds', self::DEFAULT_INTERVAL_SECONDS, 5, 30);
            $maxRuntime = $this->intSetting($pdo, 'nobitex_fast_max_runtime_seconds', self::DEFAULT_MAX_RUNTIME_SECONDS, 15, 55);
            $started = microtime(true);
            $results = [];
            $baleRuns = [];
            $walletReconcileRuns = [];
            $dustCarryRuns = [];
            $last = ['status'=>'no_trade','exchange'=>'nobitex','reason'=>'no_cycle_completed'];

            // Reconcile real external/manual sells immediately before live
            // decisions as well as in the outer cron. This is intentionally
            // idempotent: a manual full sale closes the managed position first,
            // releasing its slot so the same fast run can look for a replacement.
            $externalTradeReconcile = ['status'=>'deferred','reason'=>'not_run'];
            try {
                $externalTradeReconcile = (new NobitexExternalTradeReconciler())->reconcile($pdo);
            } catch (\Throwable $e) {
                $externalTradeReconcile = ['status'=>'deferred','reason'=>'external_trade_reconcile_error','error'=>mb_substr($e->getMessage(),0,500)];
                ErrorReporter::captureThrowable($e, 'warning', 'nobitex_fast_cycle_external_trade_reconcile', [
                    'exchange'=>'nobitex','run_id'=>$runId,
                ]);
            }

            // The wallet-only fallback runs before RuntimeSafety. Therefore a
            // manual/external reduction that is visible in the wallet but late in
            // trade history cannot leave a phantom position occupying a slot.
            $preDecisionWalletReconcile = ['status'=>'deferred','reason'=>'not_run'];
            try {
                $preDecisionWalletReconcile = (new NobitexPositionReconciler())->reconcile($pdo);
            } catch (\Throwable $e) {
                $preDecisionWalletReconcile = ['status'=>'deferred','reason'=>'wallet_reconcile_error','error'=>mb_substr($e->getMessage(),0,500)];
                ErrorReporter::captureThrowable($e, 'warning', 'nobitex_fast_cycle_predecision_reconcile', [
                    'exchange'=>'nobitex','run_id'=>$runId,
                ]);
            }

            $replacementSlotsReleased = $this->releasedSlots($externalTradeReconcile, $preDecisionWalletReconcile);

            // Partial/cancelled exits may leave a real balance that is below the
            // exchange minimum. Such a residual must not remain an `open`
            // position forever and consume all configured slots. Classify those
            // rows before the first decision cycle; if a previously retired dust
            // balance has become sellable, the manager may submit one guarded
            // reduction-only SELL and the normal engine reconciles it below.
            $residualDust = ['status'=>'deferred','reason'=>'not_run'];
            try {
                $residualDust = (new NobitexResidualDustManager())->reconcile($pdo);
            } catch (\Throwable $e) {
                $residualDust = ['status'=>'deferred','reason'=>'manager_error','error'=>mb_substr($e->getMessage(),0,500)];
                ErrorReporter::captureThrowable($e, 'warning', 'nobitex_residual_dust_reconcile', [
                    'exchange'=>'nobitex','run_id'=>$runId,
                ]);
            }

            for ($cycle = 1; $cycle <= $cycles; $cycle++) {
                if ($cycle > 1) {
                    $elapsed = microtime(true) - $started;
                    if ($elapsed + $interval + 2.0 >= $maxRuntime) break;
                    usleep($interval * 1_000_000);
                }

                NobitexUniverseScanner::resetProcessCache();
                $engine = new NobitexAutoTraderEngine();
                if ($cycle === 1) {
                    try { $engine->runBootstrapIfPending(); } catch (\Throwable $e) {
                        ErrorReporter::captureThrowable($e, 'warning', 'fast_cycle_bootstrap', [
                            'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,
                        ]);
                    }
                }

                try {
                    $last = $engine->run();
                    $results[] = [
                        'cycle'=>$cycle,
                        'status'=>(string)($last['status'] ?? 'unknown'),
                        'reason'=>$last['reason'] ?? null,
                        'selected'=>$last['selected'] ?? null,
                        'order'=>$last['order'] ?? null,
                        'time_utc'=>gmdate(DATE_ATOM),
                    ];
                } catch (\Throwable $e) {
                    $expected = $this->expectedNoTrade($e);
                    if ($expected !== null) {
                        $last = ['status'=>'no_trade','exchange'=>'nobitex'] + $expected;
                        $results[] = ['cycle'=>$cycle,'status'=>'no_trade'] + $expected;
                        // An hourly BUY throttle applies to every candidate, so
                        // spinning through the remaining micro-cycles would only
                        // repeat the same safe rejection. Candidate-specific
                        // rejections may be re-evaluated on the next fresh cycle.
                        if (($expected['reason'] ?? '') === 'buy_hourly_safety_limit_reached') break;
                        continue;
                    }

                    ErrorReporter::captureThrowable($e, 'error', 'cron_exchange_fast_cycle', [
                        'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,'status'=>'failed',
                    ]);
                    $last = ['status'=>'failed','exchange'=>'nobitex','error'=>$e->getMessage()];
                    $results[] = ['cycle'=>$cycle,'status'=>'failed','error'=>mb_substr($e->getMessage(),0,500)];
                    break;
                }

                // RuntimeSafety already reconciles wallet/position state before
                // every engine cycle. Re-query the real wallet after a cycle only
                // when that cycle actually submitted a BUY, because that is the
                // moment Nobitex may deduct the fee from received base quantity.
                // This keeps Bale/next-cycle position amount exact without adding
                // redundant wallet API traffic to ordinary HOLD/SELL cycles.
                try {
                    if ($this->containsBuySubmission($last)) {
                        $walletReconcileRuns[]=['cycle'=>$cycle]+(new NobitexPositionReconciler())->reconcile($pdo);
                    }
                } catch (\Throwable $e) {
                    ErrorReporter::captureThrowable($e, 'warning', 'nobitex_fast_cycle_position_reconcile', [
                        'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,
                    ]);
                    $walletReconcileRuns[]=['cycle'=>$cycle,'status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
                }

                // Trade notifications are synchronized after every micro-cycle
                // and after the real-wallet quantity reconciliation above.
                try {
                    $notifier = new BaleTradeNotifier();
                    $status = $notifier->status($pdo);
                    if (($status['enabled'] ?? false) && ($status['configured'] ?? false)) {
                        $sync = $notifier->syncConfirmedTrades(100, $pdo);
                        $delivery = $notifier->flushPending(25, $pdo);
                        $baleRuns[] = ['cycle'=>$cycle,'sync'=>$sync,'delivery'=>$delivery];
                    }
                } catch (\Throwable $e) {
                    ErrorReporter::captureThrowable($e, 'warning', 'bale_fast_cycle_delivery', [
                        'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,
                    ]);
                    $baleRuns[] = ['cycle'=>$cycle,'status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
                }

                // After Bale has captured the confirmed BUY amount, fold any
                // same-asset Trade-owned IRT dust into the live IRT position. The
                // next ordinary position exit can then sell the combined managed
                // quantity in one legal order. Wallet equality is required by the
                // carry manager, so unrelated manual holdings are never absorbed.
                try {
                    $dustCarryRuns[] = ['cycle'=>$cycle] + (new NobitexManagedDustCarryForward())->reconcile($pdo);
                } catch (\Throwable $e) {
                    ErrorReporter::captureThrowable($e, 'warning', 'nobitex_managed_dust_carry_forward', [
                        'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,
                    ]);
                    $dustCarryRuns[] = ['cycle'=>$cycle,'status'=>'deferred','reason'=>'carry_forward_error','error'=>mb_substr($e->getMessage(),0,500)];
                }

                $status = strtolower((string)($last['status'] ?? ''));
                if (in_array($status, ['disabled','failed'], true)) break;
                if ((microtime(true) - $started) >= $maxRuntime - 2.0) break;
            }

            return $last + [
                'fast_cycle_model'=>self::MODEL,
                'fast_cycles_completed'=>count($results),
                'fast_cycles_requested'=>$cycles,
                'fast_cycle_interval_seconds'=>$interval,
                'fast_cycle_max_runtime_seconds'=>$maxRuntime,
                'fast_cycle_results'=>$results,
                'external_trade_reconciliation'=>$externalTradeReconcile,
                'predecision_wallet_reconciliation'=>$preDecisionWalletReconcile,
                'replacement_slots_released'=>$replacementSlotsReleased,
                'residual_dust_reconciliation'=>$residualDust,
                'wallet_fast_cycle_reconciliation'=>$walletReconcileRuns,
                'bale_fast_cycle'=>$baleRuns,
                'managed_dust_carry_forward'=>$dustCarryRuns,
                'runtime_seconds'=>round(microtime(true)-$started,3),
            ];
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('trade_nobitex_fast_cycle_v1')"); } catch (\Throwable) {}
        }
    }

    private function expectedNoTrade(\Throwable $e): ?array
    {
        if ($e instanceof NobitexCandidateRejectedException) {
            return [
                'reason'=>$e->reasonCode(),
                'candidate_symbol'=>$e->symbol(),
                'assessment'=>$e->assessment(),
                'expected_rejection'=>true,
            ];
        }

        // This exception is a rate/backpressure guard, not an exchange outage or
        // failed order. Reporting it as cron_exchange_fast_cycle/error produced
        // misleading red alerts and marked the whole run failed.
        if ($e instanceof \RuntimeException && trim($e->getMessage()) === 'Nobitex buy order safety limit reached.') {
            return [
                'reason'=>'buy_hourly_safety_limit_reached',
                'safety_throttle'=>true,
                'expected_rejection'=>true,
            ];
        }

        return null;
    }

    private function releasedSlots(array $external, array $wallet): int
    {
        $released = max(0, (int)($wallet['closed'] ?? 0));
        foreach ((array)($external['events'] ?? []) as $event) {
            if (is_array($event) && (string)($event['type'] ?? '') === 'closed') $released++;
        }
        return $released;
    }

    private function containsBuySubmission(array $result): bool
    {
        if ((string)($result['status'] ?? '') === 'buy_submitted') return true;
        foreach ((array)($result['actions'] ?? []) as $action) {
            if (is_array($action) && (string)($action['status'] ?? '') === 'buy_submitted') return true;
        }
        return false;
    }

    private function intSetting(PDO $pdo, string $key, int $default, int $min, int $max): int
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        $value = $stmt->fetchColumn();
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }
}
