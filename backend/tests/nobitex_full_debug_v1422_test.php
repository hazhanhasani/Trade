<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexInternalSignalEngine;
use Trade\Trading\NobitexStrategyLearning;

function assertDebug1422(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$low = NobitexInternalSignalEngine::forecastUncertaintyBuffer(0.05, 0.02, 0.0, 0.20, 0.0, 0.0);
$high = NobitexInternalSignalEngine::forecastUncertaintyBuffer(1.50, 0.35, 0.20, 8.0, 0.40, 0.30);
assertDebug1422(($low['model'] ?? '') === 'uncertainty_only_v2', 'forecast buffer model id mismatch');
assertDebug1422((float)$low['total_percent'] >= 0.12, 'low uncertainty buffer must retain a positive floor');
assertDebug1422((float)$high['total_percent'] > (float)$low['total_percent'], 'higher uncertainty should require a larger buffer');
assertDebug1422((float)$high['total_percent'] <= 0.55, 'forecast-only buffer must not return to the old 0.85% saturation');

assertDebug1422(NobitexStrategyLearning::supportsStrategy('profit_first_v5'), 'live Profit-First strategy must participate in learning/calibration');
assertDebug1422(NobitexStrategyLearning::supportsStrategy('breakout_v1'), 'legacy strategy learning must remain compatible');

$global = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexGlobalRiskRuntime.php');
assertDebug1422(is_string($global) && str_contains($global, "return'configured_position_capacity_reached'"), 'configured hard-cap diagnostics must use the configured-cap reason');

$portfolio = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
foreach (['expected_gross_move_percent','estimated_roundtrip_cost_percent','forecast_uncertainty_buffer','volatility_percent','liquidity_multiple'] as $needle) {
    assertDebug1422(is_string($portfolio) && str_contains($portfolio, "'{$needle}'"), 'candidate diagnostics missing ' . $needle);
}

$reporter = file_get_contents(dirname(__DIR__) . '/src/Observability/NobitexDecisionReporter.php');
assertDebug1422(is_string($reporter) && str_contains($reporter, 'BufferParts='), 'Bale forensic report must expose buffer decomposition');
assertDebug1422(str_contains((string)$reporter, "'configured_position_capacity_reached'"), 'Bale report must explain configured hard-cap reason');

echo "Trade 1.4.22 full debug regression tests passed.\n";
