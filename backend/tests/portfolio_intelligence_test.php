<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexCandidateRejectedException;
use Trade\Trading\NobitexPortfolioIntelligence;

function intelAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$healthy = NobitexPortfolioIntelligence::drawdownPolicyFromReturns([0.8, 0.5, -0.2, 0.6]);
intelAssert((float)$healthy['size_multiplier'] === 1.0, 'healthy equity should keep full configured size');
intelAssert((int)$healthy['samples'] === 4, 'drawdown sample count mismatch');

$losingStreak = NobitexPortfolioIntelligence::drawdownPolicyFromReturns([1.0, 0.5, -1.0, -1.0, -1.0]);
intelAssert((int)$losingStreak['losing_streak'] === 3, 'three-loss streak was not detected');
intelAssert((float)$losingStreak['size_multiplier'] <= 0.80, 'three-loss streak should cap next-entry size at 80%');

$deepDrawdown = NobitexPortfolioIntelligence::drawdownPolicyFromReturns([2.0, 2.0, -5.0, -5.0]);
intelAssert((float)$deepDrawdown['current_drawdown_percent'] >= 8.0, 'deep drawdown test did not reach the intended band');
intelAssert((float)$deepDrawdown['size_multiplier'] === 0.50, '8%+ drawdown must halve new-entry sizing');

$smallSample = NobitexPortfolioIntelligence::strategyMultiplierFromStats(4, 0.10, -3.0, 0.10);
intelAssert($smallSample === 1.0, 'tiny strategy samples must remain neutral');

$badMature = NobitexPortfolioIntelligence::strategyMultiplierFromStats(12, 0.20, -1.5, 0.40);
intelAssert($badMature <= 0.65, 'persistent mature underperformance should receive the minimum learning weight');

$goodMature = NobitexPortfolioIntelligence::strategyMultiplierFromStats(12, 0.70, 0.8, 2.0);
intelAssert($goodMature === 1.0, 'profitable strategy evidence must not increase risk above configured size');

$identical = NobitexPortfolioIntelligence::pearson([1.0, 2.0, 3.0, 4.0], [1.0, 2.0, 3.0, 4.0]);
intelAssert($identical !== null && abs($identical - 1.0) < 0.000001, 'identical return series should correlate at +1');

$inverse = NobitexPortfolioIntelligence::pearson([1.0, 2.0, 3.0, 4.0], [4.0, 3.0, 2.0, 1.0]);
intelAssert($inverse !== null && abs($inverse + 1.0) < 0.000001, 'inverse return series should correlate at -1');

$flat = NobitexPortfolioIntelligence::pearson([1.0, 1.0, 1.0], [2.0, 3.0, 4.0]);
intelAssert($flat === null, 'flat series must not produce a misleading correlation');

$rejection = new NobitexCandidateRejectedException(
    'ETHIRT',
    'portfolio_correlation_cluster_limit',
    ['allowed'=>false,'correlation'=>['count'=>3]],
);
intelAssert($rejection->symbol() === 'ETHIRT', 'candidate rejection must retain the rejected symbol');
intelAssert($rejection->reasonCode() === 'portfolio_correlation_cluster_limit', 'candidate rejection reason code mismatch');
intelAssert(($rejection->assessment()['allowed'] ?? true) === false, 'candidate rejection assessment should be preserved for fallback diagnostics');
intelAssert(str_contains($rejection->getMessage(), 'ETHIRT'), 'candidate rejection message should identify the rejected symbol');

echo "Portfolio Intelligence v2 + Smart Candidate Fallback regression tests passed.\n";
