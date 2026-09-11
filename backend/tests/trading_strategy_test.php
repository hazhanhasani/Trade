<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexInternalSignalEngine;
use Trade\Trading\NobitexPortfolioRotation;
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
assertTrue(($signal['source'] ?? '') === 'nobitex_profit_first_v5_shadow_multi_strategy_v1', 'restored profit-first source marker missing');
assertTrue(($signal['decision_model'] ?? '') === 'profit_first_net_edge_v5_restored', 'restored profit-first decision model missing');
assertTrue(($signal['strategy_key'] ?? '') === 'profit_first_v5', 'profit-first primary strategy marker missing');
assertTrue(isset($signal['market_regime']['regime']), 'shadow market regime missing');
assertTrue(isset($signal['selected_strategy']['key']), 'primary strategy diagnostics missing');
assertTrue(isset($signal['shadow_multi_strategy']) && is_array($signal['shadow_multi_strategy']), 'shadow multi-strategy diagnostics missing');
assertTrue(isset($signal['strategy_candidates']) && is_array($signal['strategy_candidates']), 'strategy candidate diagnostics missing');
assertTrue(count($signal['strategy_candidates']) === 4, 'all four shadow strategies should still be evaluated');
assertTrue(isset($signal['execution_quality']) && is_array($signal['execution_quality']), 'execution quality diagnostics missing');
assertTrue((float)($signal['execution_quality']['liquidity_multiple'] ?? 0) >= 15.9, 'liquidity multiple was not calculated');
assertTrue(isset($signal['execution_quality_score']), 'execution quality score missing');
assertTrue(isset($signal['cost_model']['liquidity_slippage_reserve_percent']), 'liquidity cost reserve missing');
assertTrue(isset($signal['cost_model']['adverse_flow_reserve_percent']), 'adverse flow reserve missing');
assertTrue(array_key_exists('regime_uncertainty_buffer_percent', $signal['cost_model']), 'shadow regime uncertainty marker missing');
assertTrue(array_key_exists('strategy_uncertainty_buffer_percent', $signal['cost_model']), 'shadow strategy uncertainty marker missing');
assertTrue((float)$signal['cost_model']['regime_uncertainty_buffer_percent'] === 0.0, 'shadow regime must not harden the primary BUY gate');
assertTrue((float)$signal['cost_model']['strategy_uncertainty_buffer_percent'] === 0.0, 'shadow strategy must not harden the primary BUY gate');

$illiquid = $baseMarket;
$illiquid['depth_quote'] = 6_000_000.0;
$illiquidSignal = $engine->analyze($illiquid, $prices);
assertTrue(($illiquidSignal['ready'] ?? true) === false, 'illiquid market should not be executable');
assertTrue(($illiquidSignal['reason'] ?? '') === 'liquidity_not_executable', 'illiquid rejection reason mismatch');

$adverse = $baseMarket;
$adverse['orderbook_imbalance'] = -0.90;
$adverseSignal = $engine->analyze($adverse, $prices);
assertTrue((float)($adverseSignal['cost_model']['adverse_flow_reserve_percent'] ?? 0) > 0.0, 'negative order-book imbalance should reserve adverse-flow cost');

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

$rotation = new NobitexPortfolioRotation();
$now = time();
$rotationConfig = [
    'min_advantage_percent'=>0.75,
    'min_hold_minutes'=>45,
    'max_rotation_loss_percent'=>0.75,
    'friction_margin_percent'=>0.15,
];
$positions = [
    [
        'id'=>1,'symbol'=>'WEAKIRT','asset'=>'WEAK','quote_asset'=>'IRT','status'=>'open','amount'=>2.0,
        'forward_edge_percent'=>0.05,'estimated_exit_cost_percent'=>0.30,'unrealized_net_pnl_percent'=>0.20,
        'opened_at'=>gmdate('Y-m-d H:i:s', $now - 2 * 3600),
    ],
    [
        'id'=>2,'symbol'=>'STRONGIRT','asset'=>'STRONG','quote_asset'=>'IRT','status'=>'open','amount'=>1.0,
        'forward_edge_percent'=>0.70,'estimated_exit_cost_percent'=>0.25,'unrealized_net_pnl_percent'=>0.40,
        'opened_at'=>gmdate('Y-m-d H:i:s', $now - 3 * 3600),
    ],
];
$candidates = [
    ['symbol'=>'NEWIRT','asset'=>'NEW','quote_asset'=>'IRT','signal'=>['ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>1.25,'execution_quality_score'=>84]],
    ['symbol'=>'WEAKUSDT','asset'=>'WEAK','quote_asset'=>'USDT','signal'=>['ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>3.00,'execution_quality_score'=>95]],
];
$plan = $rotation->plan($positions, $candidates, $rotationConfig, $now);
assertTrue(($plan['rotate'] ?? false) === true, 'superior replacement should trigger rotation');
assertTrue((int)($plan['victim']['id'] ?? 0) === 1, 'rotation should release the weakest eligible incumbent');
assertTrue(($plan['candidate']['symbol'] ?? '') === 'NEWIRT', 'rotation should select the best inactive same-quote candidate');
assertTrue((float)($plan['advantage_percent'] ?? 0) >= 1.19, 'rotation advantage was not calculated correctly');

$tooYoung = $positions;
$tooYoung[0]['opened_at'] = gmdate('Y-m-d H:i:s', $now - 10 * 60);
$tooYoung[1]['opened_at'] = gmdate('Y-m-d H:i:s', $now - 10 * 60);
$youngPlan = $rotation->plan($tooYoung, $candidates, $rotationConfig, $now);
assertTrue(($youngPlan['rotate'] ?? true) === false, 'minimum hold guard should block premature rotation');
assertTrue(($youngPlan['reason'] ?? '') === 'no_rotation_eligible_position', 'minimum hold rejection reason mismatch');

$deepLoss = [$positions[0]];
$deepLoss[0]['unrealized_net_pnl_percent'] = -1.20;
$lossPlan = $rotation->plan($deepLoss, $candidates, $rotationConfig, $now);
assertTrue(($lossPlan['rotate'] ?? true) === false, 'rotation must not realize a deep loss just to chase another opportunity');

$weakCandidate = $candidates;
$weakCandidate[0]['signal']['tradable_net_edge_percent'] = 0.55;
$weakPlan = $rotation->plan($positions, $weakCandidate, $rotationConfig, $now);
assertTrue(($weakPlan['rotate'] ?? true) === false, 'insufficient replacement edge should not churn the portfolio');
assertTrue(($weakPlan['reason'] ?? '') === 'replacement_advantage_insufficient', 'insufficient advantage rejection reason mismatch');

echo "Nobitex restored profit-first entry + shadow multi-strategy + execution quality + portfolio rotation regression tests passed.\n";
