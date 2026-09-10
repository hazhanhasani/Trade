<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexExecutionLearning;

function execLearnAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$warmup = NobitexExecutionLearning::statistics(
    [0.10, 0.08, 0.12, 0.09, 0.11],
    [1, 1, 1, 1, 1],
    0
);
execLearnAssert(NobitexExecutionLearning::penaltyFromStatistics($warmup) === 0.0, 'Fewer than six fills must remain neutral.');

$clean = NobitexExecutionLearning::statistics(
    [-0.03, 0.00, 0.01, -0.02, 0.02, 0.00, 0.01, -0.01],
    [1, 1, 1, 1, 1, 1, 1, 1],
    0
);
execLearnAssert(NobitexExecutionLearning::penaltyFromStatistics($clean) === 0.0, 'Clean execution around arrival price should not create a penalty.');

$adverse = NobitexExecutionLearning::statistics(
    [0.18, 0.24, 0.20, 0.28, 0.16, 0.31, 0.22, 0.26],
    [1.0, 0.82, 1.0, 0.75, 1.0, 0.90, 1.0, 0.86],
    3
);
$penalty = NobitexExecutionLearning::penaltyFromStatistics($adverse);
execLearnAssert($penalty > 0.10, 'Persistent adverse slippage should tighten the entry margin.');
execLearnAssert($penalty <= 0.45, 'Execution-learning penalty must stay capped.');
execLearnAssert((float)$adverse['partial_fill_rate'] > 0.0, 'Partial fills were not detected.');
execLearnAssert((float)$adverse['reprice_rate'] > 0.0, 'Reprice rate was not detected.');

$extreme = NobitexExecutionLearning::statistics(
    [2.0, 2.5, 3.0, 2.2, 2.8, 3.4, 2.6],
    [0.2, 0.3, 0.4, 0.5, 0.2, 0.3, 0.4],
    7
);
execLearnAssert(NobitexExecutionLearning::penaltyFromStatistics($extreme) === 0.45, 'Extreme execution degradation must stop at the hard penalty cap.');

$improved = NobitexExecutionLearning::statistics(
    [-0.15, -0.10, -0.05, -0.12, -0.08, -0.04, -0.09],
    [1, 1, 1, 1, 1, 1, 1],
    0
);
execLearnAssert(NobitexExecutionLearning::penaltyFromStatistics($improved) === 0.0, 'Price improvement must never create a negative penalty or loosen risk.');

echo "Execution Learning v1 regression tests passed.\n";
