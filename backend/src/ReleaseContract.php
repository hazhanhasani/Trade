<?php

declare(strict_types=1);

namespace Trade;

final class ReleaseContract
{
    public const API_CONTRACT = 1;

    /**
     * Capabilities guaranteed by this backend/API contract. Identifiers stay
     * stable; incompatible semantic changes require incrementing API_CONTRACT.
     */
    public const CAPABILITIES = [
        'api.capability_contract',
        'localization.iran_jalali_time_v1',
        'trading.live_only',
        'trading.live_execution_controls',
        'trading.nobitex_spot',
        'trading.irt_usdt_markets',
        'trading.nobitex_toman_display_v2',
        'trading.manual_orders',
        'trading.auto_trading',
        'trading.dynamic_position_sizing',
        'trading.execution_quality_v5',
        'trading.execution_quality_v2',
        'trading.execution_learning_v1',
        'trading.adaptive_execution_policy_v1',
        'trading.multi_strategy_regime_router_v1',
        'trading.strategy_learning_v2',
        'trading.adaptive_edge_calibration_v1',
        'trading.global_portfolio_exposure_v1',
        'trading.external_position_reconciliation_v1',
        'trading.adaptive_exit_v2',
        'trading.portfolio_rotation_v1',
        'trading.portfolio_rotation_monitor_v1',
        'trading.portfolio_intelligence_v2',
        'trading.smart_candidate_fallback_v1',
        'trading.multi_pending',
        'trading.pending_watchdog',
        'trading.kill_switch',
        'trading.emergency_modes_v1',
        'trading.settings_presets_history_v1',
        'trading.settings_risk_preview_v1',
        'trading.shadow_evaluation_v1',
        'trading.strategy_lab_v1',
        'trading.trade_replay_v1',
        'trading.tradingview_signals',
        'trading.dust_conversion_v1',
        'analytics.performance_v1',
        'analytics.trade_timeline_v1',
        'analytics.command_center_v1',
        'analytics.market_radar_v1',
        'analytics.risk_heatmap_v1',
        'analytics.equity_curve_v1',
        'notifications.center_v1',
        'notifications.rules_v1',
        'observability.host_health_v1',
        'observability.sensitive_error_alerting_v1',
        'intelligence.cross_exchange_market_context_v1',
        'intelligence.crypto_news_monitor_v1',
        'ui.live_admin_stats_v1',
        'ui.command_center_v1',
        'ui.light_dark_theme_v1',
        'updates.coordinated_backend_android',
        'updates.sha256_verified_apk',
        'updates.permanent_android_signing',
    ];

    public static function payload(): array
    {
        return ['api_contract'=>self::API_CONTRACT,'capabilities'=>self::CAPABILITIES];
    }
}
