<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexInternalSignalEngine;
use Trade\Trading\RiskManager;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$prices = [];
$price = 100.0;
for ($i = 0; $i < 480; $i++) {
    // A deterministic, mildly trending series with enough movement to exercise
    // the full 1m/5m/15m indicator pipeline without random test flakiness.
    $wave = sin($i / 7.0) * 0.00045;
    $price *= 1.00035 + $wave;
    $prices[] = $price;
}

$baseMarket = [
    'quote_asset' => 'IRT',
    'spread_percent' => 0.20,
    'orderbook_imbalance' => 0.20,
    'depth_quote' => 32_000_000.0,
    'min_order_quote' => 2_000_000.0,
    'change_percent' => 2.0,
];

$engine = new NobitexInternalSignalEngine();
$signal = $engine->analyze($baseMarket, $prices);
assertTrue(($signal['source'] ?? '') === 'nobitex_internal_profit_first_full_universe_v5', 'v5 source marker missing');
assertTrue(($signal['decision_model'] ?? '') === 'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5', 'v5 decision model missing');
assertTrue(isset($signal['execution_quality']) && is_array($signal['execution_quality']), 'execution quality diagnostics missing');
assertTrue((float)($signal['execution_quality']['liquidity_multiple'] ?? 0) >= 15.9, 'liquidity multiple was not calculated');
assertTrue(isset($signal['execution_quality_score']), 'execution quality score missing');
assertTrue(isset($signal['cost_model']['liquidity_slippage_reserve_percent']), 'liquidity cost reserve missing');
assertTrue(isset($signal['cost_model']['adverse_flow_reserve_percent']), 'adverse flow reserve missing');

$illiquid = $baseMarket;
$illiquid['depth_quote'] = 6_000_000.0; // only 3x minimum order, below the v5 4x floor
$illiquidSignal = $engine->analyze($illiquid, $prices);
assertTrue(($illiquidSignal['ready'] ?? true) === false, 'illiquid market should not be executable');
assertTrue(($illiquidSignal['reason'] ?? '') === 'liquidity_not_executable', 'illiquid rejection reason mismatch');

$adverse = $baseMarket;
$adverse['orderbook_imbalance'] = -0.90;
$adverseSignal = $engine->analyze($adverse, $prices);
assertTrue(
    (float)($adverseSignal['cost_model']['adverse_flow_reserve_percent'] ?? 0) > 0.0,
    'negative order-book imbalance should reserve adverse-flow cost'
);

$risk = new RiskManager();
$stalePosition = [
    'entry_price' => 100.0,
    'amount' => 1.0,
    'quote_asset' => 'IRT',
    'stop_loss' => 97.0,
    'take_profit' => 106.0,
    'trailing_stop' => 0.0,
    'peak_price' => 100.4,
    'highest_net_pnl_percent' => 0.10,
    'opened_at' => gmdate('Y-m-d H:i:s', time() - 7 * 3600),
];
$staleReason = $risk->exitReason(100.1, $stalePosition, 'sell');
assertTrue($staleReason === 'strategy_stale_capital_release', 'stale near-flat capital should be released on a sell reversal');

$givebackPosition = $stalePosition;
$givebackPosition['opened_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
$givebackPosition['highest_net_pnl_percent'] = 1.20;
$givebackReason = $risk->exitReason(100.3, $givebackPosition, 'sell');
assertTrue($givebackReason === 'strategy_profit_giveback_exit', 'profit giveback exit should protect a previously meaningful net gain');

echo "Nobitex strategy v5 regression tests passed.\n";
