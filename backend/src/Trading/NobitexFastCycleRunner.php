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
    public const MODEL = 'nobitex_fast_cycle_v1';
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
            $last = ['status'=>'no_trade','exchange'=>'nobitex','reason'=>'no_cycle_completed'];

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
                    ErrorReporter::captureThrowable($e, 'error', 'cron_exchange_fast_cycle', [
                        'exchange'=>'nobitex','run_id'=>$runId,'cycle'=>$cycle,'status'=>'failed',
                    ]);
                    $last = ['status'=>'failed','exchange'=>'nobitex','error'=>$e->getMessage()];
                    $results[] = ['cycle'=>$cycle,'status'=>'failed','error'=>mb_substr($e->getMessage(),0,500)];
                    break;
                }

                // Trade notifications are synchronized after every micro-cycle.
                // A fill confirmed during reconciliation therefore does not have
                // to wait until the next minute-long cron invocation.
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
                'bale_fast_cycle'=>$baleRuns,
                'runtime_seconds'=>round(microtime(true)-$started,3),
            ];
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('trade_nobitex_fast_cycle_v1')"); } catch (\Throwable) {}
        }
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
