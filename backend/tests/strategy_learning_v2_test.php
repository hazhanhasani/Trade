<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexStrategyLearning;

function assertLearning(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$warmup = NobitexStrategyLearning::sizeMultiplierFromStats(4, 0.0, -4.0, 0.1, 4);
assertLearning(abs($warmup - 1.0) < 0.000001, 'fewer than five trades must stay neutral');

$healthy = NobitexStrategyLearning::sizeMultiplierFromStats(24, 0.58, 0.42, 1.75, 0);
assertLearning(abs($healthy - 1.0) < 0.000001, 'profitable strategy must never increase configured risk');

$soft = NobitexStrategyLearning::sizeMultiplierFromStats(6, 0.40, -0.08, 0.88, 2);
assertLearning($soft >= 0.80 && $soft < 1.0, 'young underperforming sample should be reduced softly only');

$weak = NobitexStrategyLearning::sizeMultiplierFromStats(18, 0.31, -0.34, 0.55, 4);
assertLearning($weak <= 0.78 && $weak >= 0.55, 'mature weak profile should receive a strong reduction');

assertLearning(
    NobitexStrategyLearning::shouldBlockProfile(11, 0.20, -1.0, 0.20) === false,
    'profile must not be hard-blocked before twelve completed trades'
);
assertLearning(
    NobitexStrategyLearning::shouldBlockProfile(16, 0.30, -0.30, 0.60) === true,
    'persistently unprofitable mature profile should be blockable'
);
assertLearning(
    NobitexStrategyLearning::shouldBlockProfile(30, 0.48, 0.12, 1.05) === false,
    'non-negative mature profile must remain available'
);

$stats = NobitexStrategyLearning::statistics([1.2, -0.5, 0.8, -0.2, -0.3]);
assertLearning($stats['trades'] === 5, 'statistics trade count mismatch');
assertLearning($stats['wins'] === 2 && $stats['losses'] === 3, 'statistics win/loss count mismatch');
assertLearning($stats['profit_factor'] > 1.0, 'profit factor should be derived from realized returns');
assertLearning($stats['recent_loss_streak'] === 2, 'recent loss streak mismatch');

$trend = [
    'strategy_key'=>'trend_momentum_v1',
    'market_regime'=>['regime'=>'trending_up'],
];
assertLearning(NobitexStrategyLearning::strategyKey($trend) === 'trend_momentum_v1', 'strategy key extraction mismatch');
assertLearning(NobitexStrategyLearning::regimeKey($trend) === 'trending_up', 'regime extraction mismatch');

$breakout = [
    'selected_strategy'=>['key'=>'breakout_v1'],
    'market_regime'=>['regime'=>'breakout_up'],
];
assertLearning(NobitexStrategyLearning::strategyKey($breakout) === 'breakout_v1', 'selected strategy fallback mismatch');
assertLearning(NobitexStrategyLearning::supportsStrategy('high_volatility_momentum_v3'), 'high-volatility live strategy must participate in learning');

$mean = [
    'strategy_key'=>'mean_reversion_v1',
    'market_regime'=>['regime'=>'ranging'],
];
assertLearning(NobitexStrategyLearning::strategyKey($mean) !== NobitexStrategyLearning::strategyKey($trend), 'strategies must stay isolated');
assertLearning(NobitexStrategyLearning::regimeKey($mean) !== NobitexStrategyLearning::regimeKey($trend), 'regime profiles must stay isolated');

echo "Strategy Learning v2 regression tests passed.\n";
