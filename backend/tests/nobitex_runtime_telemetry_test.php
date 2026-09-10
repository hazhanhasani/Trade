<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\ReleaseContract;
use Trade\Trading\NobitexRuntimeModels;

function telemetryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

telemetryAssert(NobitexRuntimeModels::DECISION === 'multi_strategy_regime_router_net_edge_v1', 'Decision model must identify the current multi-strategy router.');
telemetryAssert(NobitexRuntimeModels::GLOBAL_PORTFOLIO === 'global_quote_normalization_v2_toman_display', 'Global portfolio model must identify the Toman-display v2 normalizer.');
telemetryAssert(NobitexRuntimeModels::STRATEGY_LEARNING === 'strategy_learning_v2', 'Strategy Learning model mismatch.');
telemetryAssert(NobitexRuntimeModels::EDGE_CALIBRATION === 'adaptive_edge_calibration_v1', 'Adaptive Edge Calibration model mismatch.');
telemetryAssert(NobitexRuntimeModels::EXECUTION_LEARNING === 'execution_learning_v1', 'Execution Learning model mismatch.');
telemetryAssert(NobitexRuntimeModels::ADAPTIVE_EXECUTION === 'adaptive_execution_policy_v1', 'Adaptive Execution Policy model mismatch.');
telemetryAssert(NobitexRuntimeModels::ORDER_VALUE_GUARD === 'resolved_order_value_guard_v2', 'Order value guard model mismatch.');
telemetryAssert(in_array('trading.execution_learning_v1', ReleaseContract::CAPABILITIES, true), 'Release contract is missing Execution Learning capability.');
telemetryAssert(in_array('trading.adaptive_execution_policy_v1', ReleaseContract::CAPABILITIES, true), 'Release contract is missing Adaptive Execution capability.');

$signal = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexInternalSignalEngine.php');
$engine = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexAutoTraderEngine.php');
$orderService = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexOrderService.php');
$cron = file_get_contents(dirname(__DIR__) . '/cron/tick.php');
telemetryAssert(is_string($signal) && is_string($engine) && is_string($orderService) && is_string($cron), 'Unable to inspect runtime telemetry sources.');
telemetryAssert(str_contains($signal, "'decision_model'=>'" . NobitexRuntimeModels::DECISION . "'"), 'Signal engine decision model drifted from the runtime contract.');
telemetryAssert(!str_contains($engine, 'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5'), 'Legacy v5 decision model leaked into NobitexAutoTraderEngine telemetry.');
telemetryAssert(!str_contains($engine, "'global_portfolio_model'=>'global_quote_normalization_v1'"), 'Legacy global portfolio v1 label leaked into runtime telemetry.');
telemetryAssert(str_contains($engine, 'NobitexRuntimeModels::DECISION'), 'AutoTrader is not using canonical decision telemetry.');
telemetryAssert(str_contains($engine, 'NobitexRuntimeModels::GLOBAL_PORTFOLIO'), 'AutoTrader is not using canonical global portfolio telemetry.');
telemetryAssert(str_contains($engine, 'NobitexRuntimeModels::EXECUTION_LEARNING'), 'AutoTrader is missing Execution Learning runtime telemetry.');
telemetryAssert(str_contains($engine, 'NobitexRuntimeModels::ADAPTIVE_EXECUTION'), 'AutoTrader is missing Adaptive Execution runtime telemetry.');
telemetryAssert(str_contains($orderService, 'NobitexAdaptiveExecutionPolicy'), 'Live Nobitex BUY path is not using Adaptive Execution Policy.');
telemetryAssert(str_contains($orderService, "'_trade_adaptive_execution'"), 'Adaptive execution assessment is not persisted in the order request log.');
telemetryAssert(str_contains($cron, 'NobitexRuntimeModels::SELECTION'), 'Cron summary is not using canonical selection telemetry.');
telemetryAssert(str_contains($cron, 'NobitexRuntimeModels::EXECUTION_LEARNING'), 'Cron summary is missing Execution Learning telemetry.');
telemetryAssert(str_contains($cron, 'NobitexRuntimeModels::ADAPTIVE_EXECUTION'), 'Cron summary is missing Adaptive Execution telemetry.');
telemetryAssert(str_contains($cron, 'NobitexRuntimeModels::ORDER_VALUE_GUARD'), 'Cron summary is missing the active order-value guard model.');

echo "Nobitex runtime telemetry regression tests passed.\n";
