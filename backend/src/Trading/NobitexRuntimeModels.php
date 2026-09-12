<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Canonical identifiers exposed in runtime diagnostics, cron summaries and
 * update/debug tooling. Keeping them in one place prevents stale legacy model
 * names from surviving after trading-engine upgrades.
 */
final class NobitexRuntimeModels
{
    /**
     * The regime router is live. Every selected strategy must still prove a
     * positive edge after explicit fees/spread/slippage and the bounded residual
     * uncertainty buffer before a real BUY can be submitted.
     */
    public const STRATEGY_MODE = 'live_cost_aware_multi_strategy_v1';
    public const DECISION = 'cost_aware_live_multistrategy_v1';
    public const SELECTION = 'best_positive_tradable_edge_across_live_strategies_v1';
    public const PRICE_REFERENCE = NobitexCrossMarketPriceOracle::MODEL;
    public const EXECUTION = 'execution_quality_v2';
    public const EXECUTION_LEARNING = NobitexExecutionLearning::MODEL;
    public const ADAPTIVE_EXECUTION = NobitexAdaptiveExecutionPolicy::MODEL;
    public const ADAPTIVE_POLICY = NobitexAdaptivePolicyLearner::MODEL;
    public const GLOBAL_PORTFOLIO = 'global_quote_normalization_v2_toman_display';
    public const ROTATION = 'guarded_opportunity_replacement_v1';
    public const FALLBACK = 'smart_candidate_fallback_v1';
    public const STRATEGY_LEARNING = NobitexStrategyLearning::MODEL;
    public const EDGE_CALIBRATION = NobitexEdgeCalibration::MODEL;
    public const ORDER_VALUE_GUARD = NobitexOrderValueGuard::MODEL;
}
