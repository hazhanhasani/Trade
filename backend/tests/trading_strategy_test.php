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
assertTrue(($signal['source'] ?? '') === 'nobitex_live_multistrategy_v1', 'live multi-strategy source marker missing');
assertTrue(($signal['decision_model'] ?? '') === 'cost_aware_live_multistrategy_v1', 'cost-aware live decision model missing');
assertTrue(in_array(($signal['strategy_key'] ?? ''), ['profit_first_v5','trend_momentum_v1','breakout_v1','mean_reversion_v1','high_volatility_momentum_v3'], true), 'live strategy marker is invalid');
assertTrue(isset($signal['market_regime']['regime']), 'market regime missing');
assertTrue(isset($signal['selected_strategy']['key']), 'selected live strategy diagnostics missing');
assertTrue(isset($signal['live_multi_strategy']) && is_array($signal['live_multi_strategy']), 'live multi-strategy diagnostics missing');
assertTrue(($signal['live_multi_strategy']['enabled'] ?? false) === true, 'live multi-strategy execution must be enabled');
assertTrue(($signal['live_multi_strategy']['mode'] ?? '') === 'cost_aware_regime_router', 'live multi-strategy mode mismatch');
assertTrue(isset($signal['shadow_multi_strategy']) && is_array($signal['shadow_multi_strategy']), 'legacy multi-strategy compatibility diagnostics missing');
assertTrue(($signal['shadow_multi_strategy']['execution_mode'] ?? '') === 'live_cost_aware', 'legacy block must disclose live execution mode');
assertTrue(isset($signal['strategy_candidates']) && is_array($signal['strategy_candidates']), 'strategy candidate diagnostics missing');
assertTrue(count($signal['strategy_candidates']) === 4, 'all four routed strategies should be evaluated');
foreach ($signal['strategy_candidates'] as $candidate) {
    assertTrue(array_key_exists('net_edge_after_execution_cost_percent', $candidate), 'strategy candidate must expose post-cost edge');
    assertTrue(array_key_exists('tradable_net_edge_percent', $candidate), 'strategy candidate must expose tradable post-buffer edge');
    assertTrue(array_key_exists('live_cost_gate_passed', $candidate), 'strategy candidate must expose the live cost gate');
}
assertTrue(isset($signal['execution_quality']) && is_array($signal['execution_quality']), 'execution quality diagnostics missing');
assertTrue((float)($signal['execution_quality']['liquidity_multiple'] ?? 0) >= 15.9, 'liquidity multiple was not calculated');
assertTrue(isset($signal['execution_quality_score']), 'execution quality score missing');
assertTrue(isset($signal['cost_model']['liquidity_slippage_reserve_percent']), 'liquidity cost reserve missing');
assertTrue(isset($signal['cost_model']['adverse_flow_reserve_percent']), 'adverse flow reserve missing');
assertTrue(($signal['cost_model']['explicit_costs_untouched_by_starvation'] ?? false) === true, 'inactivity policy must never relax explicit execution costs');
assertTrue(isset($signal['entry_starvation_policy']) && is_array($signal['entry_starvation_policy']), 'bounded inactivity policy diagnostics missing');

$noStarvation = NobitexInternalSignalEngine::starvationPolicy(['seconds_since_last_trade'=>3600], 0.20);
assertTrue(abs((float)$noStarvation['effective_buffer_percent'] - 0.20) < 0.000001, 'recent activity must keep uncertainty buffer unchanged');
$starved = NobitexInternalSignalEngine::starvationPolicy(['seconds_since_last_trade'=>86400], 0.20);
assertTrue(abs((float)$starved['relaxation_percent'] - 0.06) < 0.000001, '24h inactivity relaxation must remain capped at 0.06%');
assertTrue(abs((float)$starved['effective_buffer_percent'] - 0.14) < 0.000001, '24h inactivity should relax residual uncertainty only');
assertTrue(($starved['explicit_execution_costs_untouched'] ?? false) === true, 'starvation policy must preserve explicit costs');
$smallBuffer = NobitexInternalSignalEngine::starvationPolicy(['seconds_since_last_trade'=>172800], 0.08);
assertTrue((float)$smallBuffer['effective_buffer_percent'] >= 0.06, 'starvation must preserve its uncertainty floor');

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
$balancedSettings = $risk->normalizeSettings([
    'risk_profile'=>'balanced','position_percent'=>3,'max_position_percent'=>8,'stop_loss_percent'=>3,
    'take_profit_percent'=>6,'daily_loss_limit_percent'=>3,'min_signal_score'=>78,'cooldown_minutes'=>45,
]);
$aggressiveSettings = $risk->normalizeSettings([
    'risk_profile'=>'aggressive','position_percent'=>5,'max_position_percent'=>12,'stop_loss_percent'=>4,
    'take_profit_percent'=>8,'daily_loss_limit_percent'=>5,'min_signal_score'=>72,'cooldown_minutes'=>30,
]);
$safeSettings = $risk->normalizeSettings([
    'risk_profile'=>'safe','position_percent'=>2,'max_position_percent'=>6,'stop_loss_percent'=>3,
    'take_profit_percent'=>5,'daily_loss_limit_percent'=>2,'min_signal_score'=>82,'cooldown_minutes'=>3,
]);
assertTrue((int)$balancedSettings['cooldown_minutes'] <= 3, 'balanced profile must not restore legacy 45-minute cooldown');
assertTrue((int)$aggressiveSettings['cooldown_minutes'] <= 3, 'aggressive profile must not restore legacy 30-minute cooldown');
assertTrue((int)$safeSettings['cooldown_minutes'] >= 30, 'safe profile must preserve its conservative cooldown floor');

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

$recyclePosition = [
    'entry_price' => 100.0,
    'amount' => 1.0,
    'quote_asset' => 'USDT',
    'stop_loss' => 95.0,
    'take_profit' => 108.0,
    'trailing_stop' => 0.0,
    'peak_price' => 100.4,
    'opened_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600),
];
$recycleReason = $risk->exitReason(100.26, $recyclePosition, 'hold');
assertTrue($recycleReason === 'time_based_capital_recycle', '24h near-flat capital must recycle without waiting for a SELL forecast');
$youngRecycle = $recyclePosition;
$youngRecycle['opened_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
assertTrue($risk->exitReason(100.26, $youngRecycle, 'hold') === null, 'young near-flat position must not be force-recycled');
$deepLossRecycle = $recyclePosition;
assertTrue($risk->exitReason(99.0, $deepLossRecycle, 'hold') === null, 'time-based recycler must not force-close a material loss');

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

echo "Nobitex cost-aware live multi-strategy + bounded anti-starvation + capital recycling regression tests passed.\n";
