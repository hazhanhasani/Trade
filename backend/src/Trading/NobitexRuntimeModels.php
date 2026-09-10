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
    public const STRATEGY_MODE = 'multi_strategy_profit_first_full_universe_v1';
    public const DECISION = 'multi_strategy_regime_router_net_edge_v1';
    public const SELECTION = 'positive_calibrated_tradable_net_edge_after_costs_v1';
    public const EXECUTION = 'execution_quality_v2';
    public const EXECUTION_LEARNING = NobitexExecutionLearning::MODEL;
    public const ADAPTIVE_EXECUTION = NobitexAdaptiveExecutionPolicy::MODEL;
    public const GLOBAL_PORTFOLIO = 'global_quote_normalization_v2_toman_display';
    public const ROTATION = 'guarded_opportunity_replacement_v1';
    public const FALLBACK = 'smart_candidate_fallback_v1';
    public const STRATEGY_LEARNING = NobitexStrategyLearning::MODEL;
    public const EDGE_CALIBRATION = NobitexEdgeCalibration::MODEL;
    public const ORDER_VALUE_GUARD = NobitexOrderValueGuard::MODEL;
}
