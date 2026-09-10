package ir.trade.app.data

object ReleaseContract {
    const val API_CONTRACT = 1

    val REQUIRED_RUNTIME_CAPABILITIES: Set<String> = setOf(
        "api.capability_contract",
        "trading.live_only",
        "trading.live_execution_controls",
        "trading.nobitex_spot",
        "trading.irt_usdt_markets",
        "trading.manual_orders",
        "trading.auto_trading",
        "trading.dynamic_position_sizing",
        "trading.execution_quality_v2",
        "trading.global_portfolio_exposure_v1",
        "trading.portfolio_rotation_v1",
        "trading.portfolio_rotation_monitor_v1",
        "trading.portfolio_intelligence_v2",
        "trading.smart_candidate_fallback_v1",
        "trading.multi_pending",
        "trading.pending_watchdog",
        "trading.kill_switch",
        "analytics.performance_v1",
        "notifications.center_v1",
        "updates.coordinated_backend_android",
        "updates.sha256_verified_apk",
        "updates.permanent_android_signing",
    )

    fun missingCapabilities(available: Set<String>): Set<String> =
        REQUIRED_RUNTIME_CAPABILITIES - available
}
