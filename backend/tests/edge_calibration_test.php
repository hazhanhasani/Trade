<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexEdgeCalibration;

function assertEdge(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$warmup = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>7,
    'average_entry_edge_percent'=>0.90,
    'average_return_percent'=>-0.40,
    'edge_capture_ratio'=>-0.44,
]);
assertEdge($warmup === 0.0, 'fewer than eight trades must remain neutral');

$accurate = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>30,
    'average_entry_edge_percent'=>0.80,
    'average_return_percent'=>0.74,
    'edge_capture_ratio'=>0.925,
]);
assertEdge($accurate === 0.0, 'small prediction error inside tolerance must not be penalized');

$overestimated = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>24,
    'average_entry_edge_percent'=>0.90,
    'average_return_percent'=>0.20,
    'edge_capture_ratio'=>0.2222,
]);
assertEdge($overestimated > 0.30 && $overestimated < 0.50, 'persistent positive overestimation should add a meaningful margin');

$negativeRealized = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>24,
    'average_entry_edge_percent'=>0.80,
    'average_return_percent'=>-0.20,
    'edge_capture_ratio'=>-0.25,
]);
assertEdge($negativeRealized > $overestimated, 'negative realized returns should be calibrated more aggressively');

$smallSample = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>8,
    'average_entry_edge_percent'=>0.90,
    'average_return_percent'=>0.20,
    'edge_capture_ratio'=>0.2222,
]);
assertEdge($smallSample > 0.0 && $smallSample < $overestimated, 'small mature samples must be shrinkage-limited');

$extreme = NobitexEdgeCalibration::penaltyFromProfile([
    'trades'=>200,
    'average_entry_edge_percent'=>4.0,
    'average_return_percent'=>-3.0,
    'edge_capture_ratio'=>-0.75,
]);
assertEdge(abs($extreme - 0.85) < 0.0001, 'calibration penalty must be hard-capped at 0.85%');

echo "Adaptive edge calibration regression tests passed.\n";
