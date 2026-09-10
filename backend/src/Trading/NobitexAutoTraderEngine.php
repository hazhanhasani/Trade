<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Score-free Nobitex orchestration entry point.
 *
 * PortfolioEngine remains responsible for ordinary entries/exits, risk controls
 * and order reconciliation. When the portfolio is full, the guarded rotation
 * engine may release one weak incumbent only when a materially superior same-
 * quote opportunity clears explicit hold-time, loss and friction guards.
 *
 * Fee accounting runs before and after trading so exits see the latest entry fee,
 * mark price and trailing-profit floor, while completed orders are converted from
 * gross PnL to net PnL after actual/estimated exchange fees.
 *
 * Portfolio Intelligence v2 is activated only for the lifetime of this Nobitex
 * run. Its runtime multiplier can reduce new-entry sizing but is always cleared
 * in finally, preventing the shared RiskManager from leaking Nobitex learning
 * into Bitpin or unrelated status reads.
 */
final class NobitexAutoTraderEngine
{
    private const MAX_ACTIONS_PER_TICK = 20;

    public function run(): array
    {
        NobitexUniverseScanner::resetProcessCache();
        $accounting = new NobitexTradeAccounting();
        $accountingBefore = $this->safeAccountingSync($accounting);
        $intelligence = new NobitexPortfolioIntelligence();
        $policy = $this->safeActivateIntelligence($intelligence);

        try {
            return $this->runWithPolicy($accounting, $accountingBefore, $intelligence, $policy);
        } finally {
            NobitexPortfolioIntelligence::clearRuntimePolicy();
        }
    }

    private function runWithPolicy(
        NobitexTradeAccounting $accounting,
        array $accountingBefore,
        NobitexPortfolioIntelligence $intelligence,
        array $policy
    ): array {
        $actions = [];
        $last = null;
        $accountingAfter = null;
        $entryLearning = [];

        for ($i = 0; $i < self::MAX_ACTIONS_PER_TICK; $i++) {
            try {
                $last = (new NobitexPortfolioEngine())->run();
            } catch (\Throwable $e) {
                // A fail-closed Portfolio Intelligence guard can reject an
                // automated BUY before it reaches the exchange. Convert that
                // rejection into an explainable no-trade result rather than
                // crashing the cron run; exits from previous iterations remain
                // unaffected because intelligence is BUY-only in OrderService.
                if (str_contains($e->getMessage(), 'Portfolio intelligence')) {
                    $last = [
                        'status'=>'no_trade',
                        'exchange'=>'nobitex',
                        'reason'=>'portfolio_intelligence_buy_blocked',
                        'error'=>mb_substr($e->getMessage(), 0, 300),
                    ];
                    $accountingAfter = $this->safeAccountingSync($accounting);
                    break;
                }
                throw $e;
            }

            $accountingAfter = $this->safeAccountingSync($accounting);
            $status = (string) ($last['status'] ?? 'unknown');

            if (in_array($status, ['buy_submitted','sell_submitted'], true)) {
                if ($status === 'buy_submitted') {
                    $entryLearning[] = $this->safeRecordIntelligence($intelligence, $last);
                }
                $actions[] = $this->stripLegacyScores($last);
                continue;
            }

            if ($status === 'portfolio_full') {
                try {
                    $rotation = (new NobitexPortfolioRotation())->attempt();
                } catch (\Throwable $e) {
                    $rotation = [
                        'status'=>'no_rotation',
                        'exchange'=>'nobitex',
                        'reason'=>'rotation_engine_error',
                        'error'=>mb_substr($e->getMessage(), 0, 240),
                    ];
                }

                $rotationStatus = (string)($rotation['status'] ?? 'no_rotation');
                if ($rotationStatus === 'sell_submitted') {
                    $actions[] = $this->stripLegacyScores($rotation);
                    $last = $rotation;
                    $accountingAfter = $this->safeAccountingSync($accounting);

                    // A remote sell may already exist even if the local position
                    // failed its optimistic transition to pending_close. Never
                    // loop and risk submitting a duplicate sell in that state.
                    if ((string)($rotation['reason'] ?? '') === 'rotation_state_reconcile_required') {
                        break;
                    }
                    continue;
                }

                $last['rotation'] = $this->stripLegacyScores($rotation);
            }

            break;
        }

        $intelligenceContext = [
            'activation'=>$policy,
            'position_size_multiplier'=>NobitexPortfolioIntelligence::runtimePositionMultiplier(),
            'entry_learning'=>$entryLearning,
        ];

        if ($actions === []) {
            $result = $this->stripLegacyScores(is_array($last) ? $last : [
                'status'=>'no_trade',
                'exchange'=>'nobitex',
                'reason'=>'no_actionable_profit',
            ]);
            $result['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            $result['intelligence'] = $intelligenceContext;
            return $result;
        }

        if (count($actions) === 1 && is_array($last) && in_array((string) ($last['status'] ?? ''), ['waiting_order','portfolio_full'], true)) {
            $one = $actions[0];
            $one['continuation_status'] = $last['status'];
            if (isset($last['rotation'])) $one['rotation'] = $last['rotation'];
            $one['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            $one['intelligence'] = $intelligenceContext;
            return $one;
        }

        return [
            'status'=>'profit_actions_processed',
            'exchange'=>'nobitex',
            'decision_model'=>'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5',
            'rotation_model'=>'guarded_opportunity_replacement_v1',
            'intelligence_model'=>NobitexPortfolioIntelligence::MODEL,
            'score_based_selection'=>false,
            'actions_count'=>count($actions),
            'actions'=>$actions,
            'continuation'=>$this->stripLegacyScores(is_array($last) ? $last : []),
            'accounting'=>['before'=>$accountingBefore,'after'=>$accountingAfter],
            'intelligence'=>$intelligenceContext,
        ];
    }

    /**
     * Retires the old score-gated first-buy path. If an older installation still
     * has that one-shot flag armed, clear it and allow the normal profit-first
     * universe engine to run in the same cron tick.
     */
    public function runBootstrapIfPending(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();

        if (!$this->settingIsTrue($pdo, 'nobitex_bootstrap_first_buy_pending')) {
            return ['status'=>'not_pending','exchange'=>'nobitex'];
        }

        Database::transaction(function (PDO $tx): void {
            $this->writeSetting($tx, 'nobitex_bootstrap_first_buy_pending', '0');
            $this->writeSetting($tx, 'nobitex_bootstrap_retired_at', gmdate('Y-m-d H:i:s'));
            $this->writeSetting($tx, 'nobitex_selection_model', 'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5');
        });

        return [
            'status'=>'not_pending',
            'exchange'=>'nobitex',
            'bootstrap'=>'retired_score_gate',
            'selection_model'=>'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5',
        ];
    }

    private function safeActivateIntelligence(NobitexPortfolioIntelligence $intelligence): array
    {
        try {
            return ['status'=>'ok','snapshot'=>$intelligence->activateRuntimePolicy()];
        } catch (\Throwable $e) {
            NobitexPortfolioIntelligence::clearRuntimePolicy();
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private function safeRecordIntelligence(NobitexPortfolioIntelligence $intelligence, array $action): array
    {
        try {
            return $intelligence->recordEntryFromAction(Database::connection(), $action);
        } catch (\Throwable $e) {
            // The order has already been submitted at this point. Learning must
            // therefore degrade gracefully and never pretend the trade failed.
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private function safeAccountingSync(NobitexTradeAccounting $accounting): array
    {
        try {
            return ['status'=>'ok'] + $accounting->sync();
        } catch (\Throwable $e) {
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,240)];
        }
    }

    private function settingIsTrue(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        return in_array(strtolower(trim((string) ($stmt->fetchColumn() ?: '0'))), ['1','true','yes','on'], true);
    }

    private function writeSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':key'=>$key, ':value'=>$value]);
    }

    private function stripLegacyScores(array $value): array
    {
        foreach (['score','signal_score','opportunity_score','minimum_score'] as $key) {
            unset($value[$key]);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->stripLegacyScores($item);
        }
        return $value;
    }
}
