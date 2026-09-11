<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexAdaptivePolicyLearner;

function expect(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$warmup = NobitexAdaptivePolicyLearner::policyFromStats(2, 1.2, 1.0, 9.0, 8, 1.1, 0.9);
expect(($warmup['learning_ready'] ?? true) === false, 'Small samples must remain warmup even when early returns look excellent.');
expect(abs((float)($warmup['entry_buffer_delta_percent'] ?? 99)) < 0.000001, 'Warmup must not relax entry uncertainty.');

$positive = NobitexAdaptivePolicyLearner::policyFromStats(12, 0.72, 0.67, 1.9, 48, 0.58, 0.64);
expect(($positive['learning_ready'] ?? false) === true, 'Mature evidence must activate policy learning.');
expect(($positive['strong_evidence'] ?? false) === true, 'Large realized + shadow evidence should become strong evidence.');
expect((float)$positive['entry_buffer_delta_percent'] < 0.0, 'Strong profitable evidence may relax only residual forecast uncertainty.');
expect((float)$positive['entry_buffer_delta_percent'] >= -0.060001, 'Buffer relaxation must remain tightly bounded.');
expect((float)$positive['position_size_multiplier'] > 1.0 && (float)$positive['position_size_multiplier'] <= 1.150001, 'Strong evidence may recommend a modest size increase inside later hard risk caps.');

$defensive = NobitexAdaptivePolicyLearner::policyFromStats(14, -0.48, 0.29, 0.55, 36, -0.42, 0.33);
expect((float)$defensive['entry_buffer_delta_percent'] > 0.0, 'Persistently weak evidence must tighten the residual margin.');
expect((float)$defensive['position_size_multiplier'] < 1.0, 'Persistently weak evidence must reduce entry size.');
expect((float)$defensive['stop_loss_multiplier'] <= 1.0, 'Weak profiles must not widen stop-loss risk.');

$reflection = new ReflectionClass(NobitexAdaptivePolicyLearner::class);
$runtime = $reflection->getProperty('runtimeSnapshot');
$runtime->setAccessible(true);
$runtime->setValue(null, [
    'model'=>NobitexAdaptivePolicyLearner::MODEL,
    'profiles'=>[[
        'regime'=>'high_volatility','quote_asset'=>'IRT','realized_samples'=>12,'shadow_samples'=>48,
    ] + $positive],
]);

$signal = [
    'ready'=>true,'action'=>'hold','reason'=>'edge_below_adaptive_safety_buffer','strategy_key'=>'profit_first_v5',
    'expected_net_edge_percent'=>0.18,'required_edge_buffer_percent'=>0.20,'tradable_net_edge_percent'=>-0.02,
    'expected_net_profit'=>false,'estimated_roundtrip_cost_percent'=>0.74,'estimated_exit_cost_percent'=>0.30,
    'expected_gross_move_percent'=>0.50,
    'market_regime'=>['regime'=>'high_volatility'],
    'selected_strategy'=>['key'=>'profit_first_v5','entry_allowed'=>false,'exit_bias'=>false,'reason'=>'edge_below_adaptive_safety_buffer'],
    'cost_model'=>['adaptive_forecast_buffer_percent'=>0.20],
];
$adapted = NobitexAdaptivePolicyLearner::applyToSignal($signal, ['quote_asset'=>'IRT']);
expect(($adapted['action'] ?? '') === 'buy', 'Mature positive evidence must be able to promote only a near-miss Profit-First margin HOLD.');
expect((float)$adapted['tradable_net_edge_percent'] > 0.0, 'Promoted BUY must still have positive tradable edge after the learned residual buffer.');
expect(abs((float)$adapted['estimated_roundtrip_cost_percent'] - 0.74) < 0.000001, 'Adaptive learning must never reduce or rewrite explicit exchange costs.');
expect((float)$adapted['required_edge_buffer_percent'] >= 0.06, 'Effective uncertainty buffer must retain its hard floor.');

$notReady = $signal;
$notReady['ready'] = false;
$notReady['reason'] = 'liquidity_not_executable';
$notReadyAdapted = NobitexAdaptivePolicyLearner::applyToSignal($notReady, ['quote_asset'=>'IRT']);
expect(($notReadyAdapted['action'] ?? '') !== 'buy', 'Learning must never promote a structurally non-executable market.');

// A profitable mature profile may hold through a marginal forward SELL signal
// slightly longer, but it cannot touch hard RiskManager stop-loss/take-profit.
$positiveSell = $signal;
$positiveSell['action'] = 'sell';
$positiveSell['reason'] = 'expected_forward_move_negative_after_exit_cost';
$positiveSell['expected_net_edge_percent'] = -0.20;
$positiveSell['required_edge_buffer_percent'] = 0.12;
$positiveSell['tradable_net_edge_percent'] = -0.32;
$positiveSell['expected_gross_move_percent'] = -0.31;
$positiveSell['estimated_exit_cost_percent'] = 0.30;
$heldSell = NobitexAdaptivePolicyLearner::applyToSignal($positiveSell, ['quote_asset'=>'IRT']);
expect(($heldSell['action'] ?? '') === 'hold', 'A mature profitable profile may defer a marginal model SELL inside the bounded continuation window.');
expect(($heldSell['reason'] ?? '') === 'adaptive_policy_holds_forward_sell_bias', 'Deferred SELL must be explicit in diagnostics.');

$runtime->setValue(null, [
    'model'=>NobitexAdaptivePolicyLearner::MODEL,
    'profiles'=>[[
        'regime'=>'high_volatility','quote_asset'=>'IRT','realized_samples'=>14,'shadow_samples'=>36,
    ] + $defensive],
]);
$buy = $signal;
$buy['action'] = 'buy';
$buy['reason'] = 'positive_tradable_net_edge_after_costs_and_buffer';
$buy['expected_net_edge_percent'] = 0.22;
$buy['required_edge_buffer_percent'] = 0.12;
$buy['tradable_net_edge_percent'] = 0.10;
$buy['expected_net_profit'] = true;
$tightened = NobitexAdaptivePolicyLearner::applyToSignal($buy, ['quote_asset'=>'IRT']);
expect(($tightened['action'] ?? '') === 'hold', 'Defensive learned evidence may demote a marginal BUY after adding uncertainty margin.');
expect((float)$tightened['expected_net_edge_percent'] > 0.0, 'Demotion must come from learned uncertainty, not fabricated negative economics.');

$defensiveSell = $signal;
$defensiveSell['action'] = 'hold';
$defensiveSell['reason'] = 'edge_below_adaptive_safety_buffer';
$defensiveSell['expected_net_edge_percent'] = -0.20;
$defensiveSell['required_edge_buffer_percent'] = 0.12;
$defensiveSell['tradable_net_edge_percent'] = -0.32;
$defensiveSell['expected_gross_move_percent'] = -0.27;
$defensiveSell['estimated_exit_cost_percent'] = 0.30;
$learnedSell = NobitexAdaptivePolicyLearner::applyToSignal($defensiveSell, ['quote_asset'=>'IRT']);
expect(($learnedSell['action'] ?? '') === 'sell', 'A weak mature profile may accept a forward SELL bias earlier than the neutral profile.');
expect(($learnedSell['reason'] ?? '') === 'adaptive_policy_forward_sell_bias', 'Learned SELL timing must be explicit in diagnostics.');

NobitexAdaptivePolicyLearner::clearRuntime();
fwrite(STDOUT, "Adaptive Policy Learner v1 regression tests passed.\n");
