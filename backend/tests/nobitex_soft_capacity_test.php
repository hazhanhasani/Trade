<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexRuntimeSafety;

function assertSoftCapacity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// Defensive history should still produce an adaptive advisory below the user's
// configured 8 slots, proving the adaptive signal itself is still alive.
$adaptive = NobitexRuntimeSafety::adaptiveMaxPositions(8, [-1.0, -0.9, -0.8, -0.7, -0.6]);
assertSoftCapacity((int)($adaptive['effective'] ?? 0) === 4, 'defensive adaptive capacity should remain 4/8 for sizing guidance');

$global = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexGlobalRiskRuntime.php');
assertSoftCapacity(is_string($global), 'global risk source should be readable');
assertSoftCapacity(str_contains($global, 'if(count($positions)>=$configuredMax)$multiplier=0.0;'), 'global activation must hard-block only at configured max');
assertSoftCapacity(str_contains($global, 'if(count($positionRows)>=$configuredMax){'), 'fresh BUY guard must hard-block only at configured max');
assertSoftCapacity(str_contains($global, "'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing'"), 'global diagnostics must expose soft-cap mode');

$capacity = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexCapacityManager.php');
assertSoftCapacity(is_string($capacity), 'capacity manager source should be readable');
assertSoftCapacity(str_contains($capacity, '$effective = $configured;'), 'forced reductions must target configured hard max, not adaptive soft max');
assertSoftCapacity(str_contains($capacity, "'within_configured_limit'"), 'within configured max must not trigger adaptive reduction');

$portfolio = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
assertSoftCapacity(is_string($portfolio), 'portfolio engine source should be readable');
assertSoftCapacity(str_contains($portfolio, '$adaptivePositionMultiplier = max(0.25, min(1.0, $adaptiveSoftMax / max(1, $maxPositions)));'), 'adaptive capacity must produce a soft size multiplier');
assertSoftCapacity(str_contains($portfolio, '$effectivePerPositionPct = $baseEffectivePerPositionPct * $learningMultiplier * $adaptivePositionMultiplier;'), 'adaptive multiplier must scale future BUY size');

$auto = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexAutoTraderEngine.php');
assertSoftCapacity(is_string($auto) && str_contains($auto, "'configured_position_capacity_reached'"), 'orchestrator must recognize configured hard-cap rejection');
assertSoftCapacity(str_contains($auto, "'adaptive_soft_max_positions'"), 'hard-cap diagnostics must include adaptive advisory');

echo "Nobitex soft adaptive capacity regression tests passed.\n";
