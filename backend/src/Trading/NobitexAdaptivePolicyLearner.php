<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Adaptive Policy Learner v1.
 *
 * Online bounded policy learning for shared-hosting deployments. It learns from
 * fee-aware realized Trade returns plus shadow BUY outcomes. It never edits code
 * and never bypasses explicit exchange costs or hard portfolio/risk controls.
 */
final class NobitexAdaptivePolicyLearner
{
    public const MODEL = 'adaptive_policy_learner_v1';

    private const MIN_MATURE_EVIDENCE = 8.0;
    private const MIN_STRONG_EVIDENCE = 16.0;
    private const MAX_BUFFER_RELAX_PERCENT = 0.06;
    private const MAX_BUFFER_TIGHTEN_PERCENT = 0.18;
    private const MIN_SIZE_MULTIPLIER = 0.60;
    private const MAX_SIZE_MULTIPLIER = 1.15;
    private const MIN_EFFECTIVE_BUFFER_PERCENT = 0.06;
    private const MAX_EFFECTIVE_BUFFER_PERCENT = 0.75;

    /** @var array<string,mixed>|null */
    private static ?array $runtimeSnapshot = null;

    public function snapshot(?PDO $pdo = null, int $limit = 360): array
    {
        $pdo ??= Database::connection();
        $limit = max(60, min(700, $limit));
        $groups = [];
        foreach ($this->realizedSamples($pdo, $limit) as $sample) {
            $key = self::profileKey((string)$sample['regime'], (string)$sample['quote_asset']);
            $groups[$key] ??= self::emptyGroup((string)$sample['regime'], (string)$sample['quote_asset']);
            $groups[$key]['realized_returns'][] = (float)$sample['return_percent'];
        }
        foreach ($this->shadowSamples($pdo, $limit) as $sample) {
            $key = self::profileKey((string)$sample['regime'], (string)$sample['quote_asset']);
            $groups[$key] ??= self::emptyGroup((string)$sample['regime'], (string)$sample['quote_asset']);
            $groups[$key]['shadow_returns'][] = (float)$sample['net_return_percent'];
        }

        $profiles = [];
        foreach ($groups as $group) {
            $realized = self::returnStats($group['realized_returns']);
            $shadow = self::returnStats($group['shadow_returns']);
            $policy = self::policyFromStats(
                $realized['count'], $realized['average'], $realized['positive_rate'], $realized['profit_factor'],
                $shadow['count'], $shadow['average'], $shadow['positive_rate']
            );
            $profiles[] = [
                'regime'=>$group['regime'],'quote_asset'=>$group['quote_asset'],
                'realized_samples'=>$realized['count'],'realized_average_return_percent'=>round($realized['average'], 4),
                'realized_win_rate'=>round($realized['positive_rate'], 4),'realized_profit_factor'=>round(min(99.0, $realized['profit_factor']), 4),
                'shadow_samples'=>$shadow['count'],'shadow_average_net_return_percent'=>round($shadow['average'], 4),
                'shadow_positive_rate'=>round($shadow['positive_rate'], 4),
            ] + $policy;
        }
        usort($profiles, static function(array $a, array $b): int {
            $byEvidence = (float)($b['evidence_weight'] ?? 0) <=> (float)($a['evidence_weight'] ?? 0);
            return $byEvidence !== 0 ? $byEvidence : strcmp((string)$a['regime'], (string)$b['regime']);
        });
        return [
            'model'=>self::MODEL,'policy_scope'=>'regime_quote',
            'training_sources'=>['realized_fee_aware_returns','shadow_forward_returns_after_estimated_costs'],
            'safety_contract'=>[
                'explicit_costs_never_relaxed'=>true,'configured_max_position_is_hard_cap'=>true,
                'portfolio_exposure_is_hard_cap'=>true,'daily_loss_gate_preserved'=>true,'kill_switch_preserved'=>true,
            ],
            'profiles'=>$profiles,'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    public function activateRuntime(?PDO $pdo = null): array
    {
        self::$runtimeSnapshot = $this->snapshot($pdo);
        return self::$runtimeSnapshot;
    }

    public static function clearRuntime(): void { self::$runtimeSnapshot = null; }

    /** @return array<string,mixed> */
    public static function policyFor(string $regime, string $quoteAsset): array
    {
        $regime = self::cleanKey($regime, 'unknown');
        $quoteAsset = strtoupper(trim($quoteAsset));
        if ($quoteAsset === '') $quoteAsset = 'IRT';
        $snapshot = self::$runtimeSnapshot;
        if (!is_array($snapshot)) {
            try {
                $snapshot = (new self())->snapshot();
                self::$runtimeSnapshot = $snapshot;
            } catch (\Throwable) {
                return self::neutralPolicy($regime, $quoteAsset, 'runtime_training_unavailable');
            }
        }
        foreach ((array)($snapshot['profiles'] ?? []) as $profile) {
            if (!is_array($profile)) continue;
            if ((string)($profile['regime'] ?? '') !== $regime) continue;
            if (strtoupper((string)($profile['quote_asset'] ?? '')) !== $quoteAsset) continue;
            return $profile;
        }
        return self::neutralPolicy($regime, $quoteAsset, 'profile_not_learned');
    }

    /**
     * Apply learned policy to the residual Profit-First uncertainty and forward
     * sell bias. Explicit trading costs and structural executability are never
     * rewritten. Hard stop-loss/take-profit/trailing rules remain authoritative.
     *
     * @return array<string,mixed>
     */
    public static function applyToSignal(array $signal, array $market): array
    {
        // First compare the traded spot leg with the same asset's Nobitex IRT
        // spot, Nobitex USDT spot and Nobitex USDT/IRT conversion market. This
        // stays exchange-internal and bounded; learning is applied afterwards.
        $signal = NobitexCrossMarketPriceOracle::applyToSignal($signal, $market);

        $regime = NobitexStrategyLearning::regimeKey($signal);
        $quote = strtoupper(trim((string)($market['quote_asset'] ?? 'IRT'))) ?: 'IRT';
        $policy = self::policyFor($regime, $quote);
        $signal['adaptive_policy'] = $policy + ['model'=>self::MODEL];
        if (NobitexStrategyLearning::strategyKey($signal) !== 'profit_first_v5') return $signal;
        if (!is_numeric($signal['required_edge_buffer_percent'] ?? null) || !is_numeric($signal['expected_net_edge_percent'] ?? null)) return $signal;

        $baseBuffer = max(0.0, (float)$signal['required_edge_buffer_percent']);
        $delta = self::clamp((float)($policy['entry_buffer_delta_percent'] ?? 0.0), -self::MAX_BUFFER_RELAX_PERCENT, self::MAX_BUFFER_TIGHTEN_PERCENT);
        $effectiveBuffer = self::clamp($baseBuffer + $delta, self::MIN_EFFECTIVE_BUFFER_PERCENT, self::MAX_EFFECTIVE_BUFFER_PERCENT);
        $netEdge = (float)$signal['expected_net_edge_percent'];
        $tradable = $netEdge - $effectiveBuffer;
        $ready = (bool)($signal['ready'] ?? false);
        $action = strtolower((string)($signal['action'] ?? 'hold'));
        $reason = (string)($signal['reason'] ?? '');

        $signal['base_required_edge_buffer_percent'] = round($baseBuffer, 4);
        $signal['required_edge_buffer_percent'] = round($effectiveBuffer, 4);
        $signal['tradable_net_edge_percent'] = round($tradable, 4);
        $signal['minimum_net_edge_percent'] = round($effectiveBuffer, 4);
        $signal['expected_net_profit'] = $ready && $tradable > 0.0;
        $signal['adaptive_policy']['base_buffer_percent'] = round($baseBuffer, 4);
        $signal['adaptive_policy']['effective_buffer_percent'] = round($effectiveBuffer, 4);

        if ($ready && $tradable > 0.0 && $action === 'hold'
            && in_array($reason, ['edge_below_adaptive_safety_buffer','cross_market_reference_removed_tradable_edge'], true)) {
            $action = 'buy';
            $reason = 'positive_tradable_net_edge_after_learned_uncertainty';
        } elseif ($action === 'buy' && $tradable <= 0.0) {
            $action = 'hold';
            $reason = 'adaptive_policy_tightened_edge_below_margin';
            $signal['expected_net_profit'] = false;
        }

        // Learn WHEN to accept a forward SELL bias, while hard portfolio exits
        // remain untouched. Strong profitable profiles get slightly more room to
        // continue; weak mature profiles require less negative forward evidence.
        $quality = self::clamp((float)($policy['quality_score'] ?? 0.0), -1.0, 1.0);
        $exitCost = max(0.0, (float)($signal['estimated_exit_cost_percent'] ?? 0.0));
        $gross = (float)($signal['expected_gross_move_percent'] ?? 0.0);
        $baseSellTrigger = max(0.05, $exitCost);
        $sellThresholdMultiplier = self::clamp(1.0 + ($quality * 0.20), 0.82, 1.18);
        $learnedSellTrigger = $baseSellTrigger * $sellThresholdMultiplier;
        $signal['adaptive_policy']['sell_threshold_multiplier'] = round($sellThresholdMultiplier, 4);
        $signal['adaptive_policy']['learned_sell_trigger_percent'] = round($learnedSellTrigger, 4);

        if ($ready && $action === 'sell' && $gross >= -$learnedSellTrigger) {
            $action = 'hold';
            $reason = 'adaptive_policy_holds_forward_sell_bias';
        } elseif ($ready && $action === 'hold' && $tradable <= 0.0 && $gross < -$learnedSellTrigger) {
            $action = 'sell';
            $reason = 'adaptive_policy_forward_sell_bias';
        }

        $signal['action'] = $action;
        $signal['reason'] = $reason;
        if (is_array($signal['selected_strategy'] ?? null)) {
            $signal['selected_strategy']['entry_allowed'] = $action === 'buy';
            $signal['selected_strategy']['exit_bias'] = $action === 'sell';
            $signal['selected_strategy']['reason'] = $reason;
        }
        if (is_array($signal['cost_model'] ?? null)) {
            $signal['cost_model']['base_forecast_buffer_percent'] = round($baseBuffer, 4);
            $signal['cost_model']['adaptive_policy_buffer_delta_percent'] = round($delta, 4);
            $signal['cost_model']['adaptive_forecast_buffer_percent'] = round($effectiveBuffer, 4);
            $signal['cost_model']['adaptive_policy_model'] = self::MODEL;
        }
        return $signal;
    }

    /** @return array<string,mixed> */
    public static function policyFromStats(
        int $realizedCount, float $realizedAverage, float $realizedWinRate, float $realizedProfitFactor,
        int $shadowCount, float $shadowAverage, float $shadowPositiveRate
    ): array {
        $realizedCount = max(0, $realizedCount); $shadowCount = max(0, $shadowCount);
        $realizedWinRate = self::clamp($realizedWinRate, 0.0, 1.0);
        $shadowPositiveRate = self::clamp($shadowPositiveRate, 0.0, 1.0);
        $realizedProfitFactor = max(0.0, min(99.0, $realizedProfitFactor));
        $evidence = $realizedCount + ($shadowCount * 0.25);
        $mature = $evidence >= self::MIN_MATURE_EVIDENCE && ($realizedCount >= 3 || $shadowCount >= 24);
        $strongEvidence = $evidence >= self::MIN_STRONG_EVIDENCE && ($realizedCount >= 5 || $shadowCount >= 40);
        if (!$mature) return [
            'learning_ready'=>false,'strong_evidence'=>false,'evidence_weight'=>round($evidence, 4),'quality_score'=>0.0,
            'entry_buffer_delta_percent'=>0.0,'position_size_multiplier'=>1.0,'stop_loss_multiplier'=>1.0,
            'take_profit_multiplier'=>1.0,'reason'=>'adaptive_policy_warmup',
        ];

        $realizedComponent = tanh(self::clamp($realizedAverage, -3.0, 3.0) / 0.55);
        $shadowComponent = tanh(self::clamp($shadowAverage, -3.0, 3.0) / 0.65);
        $winComponent = ($realizedWinRate - 0.50) * 2.0;
        $shadowWinComponent = ($shadowPositiveRate - 0.50) * 2.0;
        $profitFactorComponent = tanh(($realizedProfitFactor - 1.0) / 0.80);
        $quality = self::clamp(($realizedComponent * 0.38) + ($winComponent * 0.20) + ($profitFactorComponent * 0.17)
            + ($shadowComponent * 0.15) + ($shadowWinComponent * 0.10), -1.0, 1.0);

        $bufferDelta = 0.0; $sizeMultiplier = 1.0; $stopMultiplier = 1.0; $takeMultiplier = 1.0;
        $reason = 'adaptive_policy_neutral';
        if ($quality >= 0.30 && $strongEvidence) {
            $bufferDelta = -min(self::MAX_BUFFER_RELAX_PERCENT, 0.015 + (($quality - 0.30) * 0.07));
            $sizeMultiplier = 1.0 + min(self::MAX_SIZE_MULTIPLIER - 1.0, ($quality - 0.20) * 0.20);
            $stopMultiplier = 1.0 + min(0.05, ($quality - 0.30) * 0.07);
            $takeMultiplier = 1.0 + min(0.15, ($quality - 0.20) * 0.18);
            $reason = 'adaptive_policy_positive_mature_profile';
        } elseif ($quality <= -0.18) {
            $weakness = abs($quality);
            $bufferDelta = min(self::MAX_BUFFER_TIGHTEN_PERCENT, 0.025 + ($weakness * 0.16));
            $sizeMultiplier = max(self::MIN_SIZE_MULTIPLIER, 1.0 - ($weakness * 0.42));
            $stopMultiplier = max(0.88, 1.0 - ($weakness * 0.14));
            $takeMultiplier = max(0.88, 1.0 - ($weakness * 0.10));
            $reason = 'adaptive_policy_defensive_profile';
        } elseif ($quality >= 0.12) {
            $sizeMultiplier = min(1.05, 1.0 + (($quality - 0.12) * 0.08));
            $takeMultiplier = min(1.05, 1.0 + (($quality - 0.12) * 0.08));
            $reason = 'adaptive_policy_positive_observation';
        }
        return [
            'learning_ready'=>true,'strong_evidence'=>$strongEvidence,'evidence_weight'=>round($evidence, 4),
            'quality_score'=>round($quality, 4),
            'entry_buffer_delta_percent'=>round(self::clamp($bufferDelta, -self::MAX_BUFFER_RELAX_PERCENT, self::MAX_BUFFER_TIGHTEN_PERCENT), 4),
            'position_size_multiplier'=>round(self::clamp($sizeMultiplier, self::MIN_SIZE_MULTIPLIER, self::MAX_SIZE_MULTIPLIER), 4),
            'stop_loss_multiplier'=>round(self::clamp($stopMultiplier, 0.88, 1.05), 4),
            'take_profit_multiplier'=>round(self::clamp($takeMultiplier, 0.88, 1.15), 4),'reason'=>$reason,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function realizedSamples(PDO $pdo, int $limit): array
    {
        $rows = $pdo->query(
            "SELECT p.id,p.quote_asset,SUM(COALESCE(r.net_pnl,r.pnl)) AS net_pnl,
                    SUM((r.entry_price*r.amount)+COALESCE(r.entry_fee_quote,0)) AS cost_basis,
                    MAX(COALESCE(r.accounted_at,r.created_at)) AS realized_at,MAX(s.details_json) AS details_json
             FROM nobitex_autotrade_positions p JOIN nobitex_autotrade_pnl r ON r.position_id=p.id
             LEFT JOIN nobitex_autotrade_signals s ON s.order_local_id=p.entry_order_local_id
             WHERE r.accounted_at IS NOT NULL GROUP BY p.id,p.quote_asset HAVING cost_basis>0
             ORDER BY realized_at DESC LIMIT {$limit}"
        )->fetchAll();
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $basis = (float)($row['cost_basis'] ?? 0.0); $net = (float)($row['net_pnl'] ?? 0.0);
            if ($basis <= 0.0 || !is_finite($net)) continue;
            $signal = json_decode((string)($row['details_json'] ?? ''), true); if (!is_array($signal)) $signal = [];
            if (NobitexStrategyLearning::strategyKey($signal) !== 'profit_first_v5') continue;
            $out[] = ['quote_asset'=>strtoupper((string)($row['quote_asset'] ?? 'IRT')),
                'regime'=>NobitexStrategyLearning::regimeKey($signal),'return_percent'=>($net / $basis) * 100.0];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function shadowSamples(PDO $pdo, int $limit): array
    {
        try {
            $rows = $pdo->query(
                "SELECT s.quote_asset,s.details_json,o.return_15m,o.return_60m,o.return_240m
                 FROM nobitex_shadow_signal_outcomes o JOIN nobitex_autotrade_signals s ON s.id=o.signal_id
                 WHERE s.action='buy' AND (o.return_15m IS NOT NULL OR o.return_60m IS NOT NULL OR o.return_240m IS NOT NULL)
                 ORDER BY o.updated_at DESC LIMIT {$limit}"
            )->fetchAll();
        } catch (\Throwable) { return []; }
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $signal = json_decode((string)($row['details_json'] ?? ''), true); if (!is_array($signal)) $signal = [];
            if (NobitexStrategyLearning::strategyKey($signal) !== 'profit_first_v5') continue;
            $forward = null;
            foreach (['return_60m','return_240m','return_15m'] as $field) if (is_numeric($row[$field] ?? null)) { $forward = (float)$row[$field]; break; }
            if ($forward === null || !is_finite($forward)) continue;
            $explicitCost = is_numeric($signal['estimated_roundtrip_cost_percent'] ?? null) ? max(0.0, (float)$signal['estimated_roundtrip_cost_percent']) : 0.0;
            $out[] = ['quote_asset'=>strtoupper((string)($row['quote_asset'] ?? 'IRT')),
                'regime'=>NobitexStrategyLearning::regimeKey($signal),'net_return_percent'=>$forward - $explicitCost];
        }
        return $out;
    }

    private static function emptyGroup(string $regime, string $quote): array
    {
        return ['regime'=>self::cleanKey($regime, 'unknown'),'quote_asset'=>strtoupper(trim($quote)) ?: 'IRT','realized_returns'=>[],'shadow_returns'=>[]];
    }

    private static function returnStats(array $returns): array
    {
        $clean = [];
        foreach ($returns as $value) if (is_numeric($value) && is_finite((float)$value)) $clean[] = self::clamp((float)$value, -25.0, 25.0);
        $count = count($clean);
        if ($count === 0) return ['count'=>0,'average'=>0.0,'positive_rate'=>0.5,'profit_factor'=>1.0];
        $positive = array_values(array_filter($clean, static fn(float $v): bool => $v > 0.0));
        $negative = array_values(array_filter($clean, static fn(float $v): bool => $v < 0.0));
        $positiveSum = array_sum($positive); $negativeSum = abs(array_sum($negative));
        $pf = $negativeSum > 0.00000001 ? $positiveSum / $negativeSum : ($positiveSum > 0.0 ? 99.0 : 1.0);
        return ['count'=>$count,'average'=>array_sum($clean) / $count,'positive_rate'=>count($positive) / $count,'profit_factor'=>$pf];
    }

    private static function neutralPolicy(string $regime, string $quoteAsset, string $reason): array
    {
        return ['regime'=>$regime,'quote_asset'=>$quoteAsset,'realized_samples'=>0,'shadow_samples'=>0,'learning_ready'=>false,
            'strong_evidence'=>false,'evidence_weight'=>0.0,'quality_score'=>0.0,'entry_buffer_delta_percent'=>0.0,
            'position_size_multiplier'=>1.0,'stop_loss_multiplier'=>1.0,'take_profit_multiplier'=>1.0,'reason'=>$reason];
    }

    private static function profileKey(string $regime, string $quote): string
    {
        return self::cleanKey($regime, 'unknown') . '|' . (strtoupper(trim($quote)) ?: 'IRT');
    }

    private static function cleanKey(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
        return $value !== '' ? $value : $fallback;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
