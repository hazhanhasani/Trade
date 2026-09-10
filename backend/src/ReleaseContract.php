<?php

declare(strict_types=1);

namespace Trade;

final class ReleaseContract
{
    public const API_CONTRACT = 1;

    /**
     * Capabilities that are guaranteed by this backend/API contract.
     * Keep identifiers stable; removing or changing semantics requires
     * incrementing API_CONTRACT.
     */
    public const CAPABILITIES = [
        'api.capability_contract',
        'trading.live_only',
        'trading.live_execution_controls',
        'trading.nobitex_spot',
        'trading.irt_usdt_markets',
        'trading.manual_orders',
        'trading.auto_trading',
        'trading.dynamic_position_sizing',
        'trading.execution_quality_v5',
        'trading.adaptive_exit_v2',
        'trading.multi_pending',
        'trading.pending_watchdog',
        'trading.kill_switch',
        'trading.tradingview_signals',
        'updates.coordinated_backend_android',
        'updates.sha256_verified_apk',
        'updates.permanent_android_signing',
    ];

    public static function payload(): array
    {
        return [
            'api_contract' => self::API_CONTRACT,
            'capabilities' => self::CAPABILITIES,
        ];
    }
}
