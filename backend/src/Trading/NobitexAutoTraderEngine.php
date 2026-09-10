<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Nobitex live orchestration entry point.
 *
 * The tick coordinates fee accounting, portfolio intelligence, one global
 * RLS-native/Toman-display exposure budget, bounded order repricing, smart
 * candidate fallback and guarded portfolio rotation. Runtime diagnostics use
 * NobitexRuntimeModels so stale legacy model names cannot survive upgrades.
 */
final class NobitexAutoTraderEngine
{
    private const MAX_ACTIONS_PER_TICK = 20;
    private const MAX_FALLBACKS_PER_TICK = 8;
    private const FALLBACK_SKIP_SECONDS = 90;

    public function run(): array
    {
        NobitexUniverseScanner::resetProcessCache();
        $accounting = new NobitexTradeAccounting();
        $accountingBefore = $this->safeAccountingSync($accounting);
        $intelligence = new NobitexPortfolioIntelligence();
        $policy = $this->safeActivateIntelligence($intelligence);
        $globalRisk = new NobitexGlobalRiskRuntime();
        $globalPolicy = $this->safeActivateGlobalRisk($globalRisk);
        $reprice = $this->safeExecutionReprice();

        try {
            return $this->runWithPolicy($accounting, $accountingBefore, $intelligence, $policy, $globalPolicy, $reprice);
        } finally {
            NobitexPortfolioIntelligence::clearRuntimePolicy();
            NobitexGlobalRiskRuntime::clear();
            NobitexExecutionLearning::clearRuntime();
        }
    }

    private function runWithPolicy(
        NobitexTradeAccounting $accounting,
        array $accountingBefore,
        NobitexPortfolioIntelligence $intelligence,
        array $policy,
        array $globalPolicy,
        array $executionReprice
    ): array {
        $actions = [];
        $last = null;
        $accountingAfter = null;
        $entryLearning = [];
        $fallbackRejections = [];

        for ($i = 0; $i < self::MAX_ACTIONS_PER_TICK; $i++) {
            try {
                $last = (new NobitexPortfolioEngine())->run();
            } catch (NobitexCandidateRejectedException $e) {
                $reason = $e->reasonCode();

                if (str_starts_with($reason, 'global_portfolio_')) {
                    $last = [
                        'status'=>'no_trade',
                        'exchange'=>'nobitex',
                        'reason'=>$reason,
                        'global_risk'=>$e->assessment(),
                    ];
                    $accountingAfter = $this->safeAccountingSync($accounting);
                    break;
                }

                $skip = $this->temporarilySkipCandidate(Database::connection(), $e->symbol(), self::FALLBACK_SKIP_SECONDS);
                $fallbackRejections[] = [
                    'symbol'=>$e->symbol(),
                    'reason'=>$reason,
                    'assessment'=>$e->assessment(),
                    'skip'=>$skip,
                ];
                $accountingAfter = $this->safeAccountingSync($accounting);

                if (count($fallbackRejections) >= self::MAX_FALLBACKS_PER_TICK) {
                    $last = [
                        'status'=>'no_trade',
                        'exchange'=>'nobitex',
                        'reason'=>'smart_candidate_fallback_exhausted',
                        'fallback_rejections'=>array_slice($fallbackRejections, 0, self::MAX_FALLBACKS_PER_TICK),
                    ];
                    break;
                }
                continue;
            } catch (\Throwable $e) {
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
                    if ($fallbackRejections !== []) {
                        $last['smart_fallback'] = [
                            'used'=>true,
                            'rejected_before_selection'=>count($fallbackRejections),
                            'rejections'=>array_slice($fallbackRejections, 0, self::MAX_FALLBACKS_PER_TICK),
                        ];
                    }
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
                    if ((string)($rotation['reason'] ?? '') === 'rotation_state_reconcile_required') break;
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
            'smart_candidate_fallback'=>[
                'enabled'=>true,
                'max_rejections_per_tick'=>self::MAX_FALLBACKS_PER_TICK,
                'temporary_skip_seconds'=>self::FALLBACK_SKIP_SECONDS,
                'rejected_candidates'=>$fallbackRejections,
            ],
        ];
        $portfolioContext = [
            'activation'=>$globalPolicy,
            'position_size_multiplier'=>NobitexGlobalRiskRuntime::runtimePositionMultiplier(),
            'snapshot'=>NobitexGlobalRiskRuntime::runtimeSnapshot(),
        ];

        if ($actions === []) {
            $result = $this->stripLegacyScores(is_array($last) ? $last : [
                'status'=>'no_trade','exchange'=>'nobitex','reason'=>'no_actionable_profit',
            ]);
            $result['runtime_models'] = $this->runtimeModels();
            $result['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            $result['intelligence'] = $intelligenceContext;
            $result['global_portfolio'] = $portfolioContext;
            $result['execution_reprice'] = $executionReprice;
            return $result;
        }

        if (count($actions) === 1 && is_array($last) && in_array((string) ($last['status'] ?? ''), ['waiting_order','portfolio_full'], true)) {
            $one = $actions[0];
            $one['continuation_status'] = $last['status'];
            if (isset($last['rotation'])) $one['rotation'] = $last['rotation'];
            $one['runtime_models'] = $this->runtimeModels();
            $one['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            $one['intelligence'] = $intelligenceContext;
            $one['global_portfolio'] = $portfolioContext;
            $one['execution_reprice'] = $executionReprice;
            return $one;
        }

        return [
            'status'=>'profit_actions_processed',
            'exchange'=>'nobitex',
            'strategy_mode'=>NobitexRuntimeModels::STRATEGY_MODE,
            'decision_model'=>NobitexRuntimeModels::DECISION,
            'selection_model'=>NobitexRuntimeModels::SELECTION,
            'execution_model'=>NobitexRuntimeModels::EXECUTION,
            'execution_learning_model'=>NobitexRuntimeModels::EXECUTION_LEARNING,
            'global_portfolio_model'=>NobitexRuntimeModels::GLOBAL_PORTFOLIO,
            'rotation_model'=>NobitexRuntimeModels::ROTATION,
            'intelligence_model'=>NobitexPortfolioIntelligence::MODEL,
            'strategy_learning_model'=>NobitexRuntimeModels::STRATEGY_LEARNING,
            'edge_calibration_model'=>NobitexRuntimeModels::EDGE_CALIBRATION,
            'order_value_guard_model'=>NobitexRuntimeModels::ORDER_VALUE_GUARD,
            'fallback_model'=>NobitexRuntimeModels::FALLBACK,
            'score_based_selection'=>false,
            'actions_count'=>count($actions),
            'actions'=>$actions,
            'continuation'=>$this->stripLegacyScores(is_array($last) ? $last : []),
            'accounting'=>['before'=>$accountingBefore,'after'=>$accountingAfter],
            'intelligence'=>$intelligenceContext,
            'global_portfolio'=>$portfolioContext,
            'execution_reprice'=>$executionReprice,
        ];
    }

    public function runBootstrapIfPending(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        if (!$this->settingIsTrue($pdo, 'nobitex_bootstrap_first_buy_pending')) {
            return [
                'status'=>'not_pending',
                'exchange'=>'nobitex',
                'selection_model'=>NobitexRuntimeModels::SELECTION,
                'decision_model'=>NobitexRuntimeModels::DECISION,
            ];
        }
        Database::transaction(function (PDO $tx): void {
            $this->writeSetting($tx, 'nobitex_bootstrap_first_buy_pending', '0');
            $this->writeSetting($tx, 'nobitex_bootstrap_retired_at', gmdate('Y-m-d H:i:s'));
            $this->writeSetting($tx, 'nobitex_selection_model', NobitexRuntimeModels::SELECTION);
        });
        return [
            'status'=>'not_pending',
            'exchange'=>'nobitex',
            'bootstrap'=>'retired_score_gate',
            'selection_model'=>NobitexRuntimeModels::SELECTION,
            'decision_model'=>NobitexRuntimeModels::DECISION,
        ];
    }

    /** @return array<string,string> */
    private function runtimeModels(): array
    {
        return [
            'strategy_mode'=>NobitexRuntimeModels::STRATEGY_MODE,
            'decision'=>NobitexRuntimeModels::DECISION,
            'selection'=>NobitexRuntimeModels::SELECTION,
            'execution'=>NobitexRuntimeModels::EXECUTION,
            'execution_learning'=>NobitexRuntimeModels::EXECUTION_LEARNING,
            'global_portfolio'=>NobitexRuntimeModels::GLOBAL_PORTFOLIO,
            'rotation'=>NobitexRuntimeModels::ROTATION,
            'strategy_learning'=>NobitexRuntimeModels::STRATEGY_LEARNING,
            'edge_calibration'=>NobitexRuntimeModels::EDGE_CALIBRATION,
            'order_value_guard'=>NobitexRuntimeModels::ORDER_VALUE_GUARD,
            'fallback'=>NobitexRuntimeModels::FALLBACK,
        ];
    }

    private function safeActivateIntelligence(NobitexPortfolioIntelligence $intelligence): array
    {
        try { return ['status'=>'ok','snapshot'=>$intelligence->activateRuntimePolicy()]; }
        catch (\Throwable $e) {
            NobitexPortfolioIntelligence::clearRuntimePolicy();
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private function safeActivateGlobalRisk(NobitexGlobalRiskRuntime $risk): array
    {
        try { return ['status'=>'ok','snapshot'=>$risk->activate()]; }
        catch (\Throwable $e) {
            NobitexGlobalRiskRuntime::clear();
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private function safeExecutionReprice(): array
    {
        try { return (new NobitexExecutionRepriceService())->reconcile(); }
        catch (\Throwable $e) { return ['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,300)]; }
    }

    private function safeRecordIntelligence(NobitexPortfolioIntelligence $intelligence, array $action): array
    {
        try { return $intelligence->recordEntryFromAction(Database::connection(), $action); }
        catch (\Throwable $e) { return ['status'=>'deferred','error'=>mb_substr($e->getMessage(), 0, 300)]; }
    }

    private function temporarilySkipCandidate(PDO $pdo, string $symbol, int $requestedSeconds): array
    {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        if ($symbol === '') return ['applied'=>false,'reason'=>'invalid_symbol'];
        $stmt = $pdo->prepare("SELECT value_text FROM settings WHERE key_name='cooldown_minutes' LIMIT 1");
        $stmt->execute();
        $raw=$stmt->fetchColumn();
        $cooldownMinutes=is_numeric($raw)?(int)$raw:15;
        $cooldownMinutes=max(1,min(1440,$cooldownMinutes));
        $cooldownSeconds=$cooldownMinutes*60;
        $remainingSeconds=max(15,min($cooldownSeconds,max(15,$requestedSeconds)));
        $syntheticLastTrade=time()-max(0,$cooldownSeconds-$remainingSeconds);
        $key='nobitex_last_trade_'.substr(sha1($symbol),0,16);
        $this->writeSetting($pdo,$key,gmdate('Y-m-d H:i:s',$syntheticLastTrade));
        return [
            'applied'=>true,
            'symbol'=>$symbol,
            'remaining_seconds'=>$remainingSeconds,
            'until'=>gmdate(DATE_ATOM,time()+$remainingSeconds),
        ];
    }

    private function safeAccountingSync(NobitexTradeAccounting $accounting): array
    {
        try { return ['status'=>'ok'] + $accounting->sync(); }
        catch (\Throwable $e) { return ['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,240)]; }
    }

    private function settingIsTrue(PDO $pdo, string $key): bool
    {
        $stmt=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        return in_array(strtolower(trim((string)($stmt->fetchColumn()?:'0'))),['1','true','yes','on'],true);
    }

    private function writeSetting(PDO $pdo,string $key,string $value):void
    {
        $stmt=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':key'=>$key,':value'=>$value]);
    }

    private function stripLegacyScores(array $value):array
    {
        foreach(['score','signal_score','opportunity_score','minimum_score'] as $key) unset($value[$key]);
        foreach($value as $key=>$item) if(is_array($item)) $value[$key]=$this->stripLegacyScores($item);
        return$value;
    }
}
