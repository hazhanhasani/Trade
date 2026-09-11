<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Legacy compatibility entry point for the former Bitpin trading engine.
 *
 * Execution moved exclusively to Nobitex. Keeping this class prevents old cron,
 * API and dashboard references from fataling while making Bitpin trading a
 * deterministic no-op.
 */
final class AutoTraderEngine
{
    public function __construct(
        private readonly OrderService $orders = new OrderService(),
        private readonly MarketScanner $scanner = new MarketScanner(),
        private readonly RiskManager $risk = new RiskManager(),
    ) {}

    public function run(): array
    {
        return [
            'status'=>'market_data_only',
            'exchange'=>'bitpin',
            'execution_allowed'=>false,
            'analysis_role'=>'external_price_source',
            'reason'=>'bitpin_removed_from_trading_engine',
            'decision_model'=>'none',
        ];
    }
}
