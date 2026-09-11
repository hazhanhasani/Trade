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
     * Profit-first v5 is the primary economic selector. Multi-strategy remains
     * shadow telemetry; Adaptive Policy Learner may only tune residual forecast
     * uncertainty after explicit costs have already been deducted.
     */
    public const STRATEGY_MODE = 'profit_first_v5_shadow_multi_strategy_v1';
    public const DECISION = 'profit_first_net_edge_v5_uncertainty_buffer_v2';
    public const SELECTION = 'positive_tradable_net_edge_after_explicit_costs_uncertainty_buffer_v2';
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
