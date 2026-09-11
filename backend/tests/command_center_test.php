<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\ReleaseContract;
use Trade\Trading\TradeCommandCenter;

function expectCommandCenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$center = new TradeCommandCenter();
$presets = $center->presets();

expectCommandCenter(isset($presets['safe'], $presets['balanced'], $presets['aggressive']), 'All three risk presets must exist.');
expectCommandCenter((float)$presets['safe']['position_percent'] === 2.0, 'Safe preset must keep each buy at 2%.');
expectCommandCenter((float)$presets['safe']['nobitex_portfolio_exposure_percent'] === 35.0, 'Safe preset must cap portfolio exposure at 35%.');
expectCommandCenter((int)$presets['safe']['nobitex_max_positions'] === 6, 'Safe preset must cap live positions at 6.');
expectCommandCenter((float)$presets['balanced']['nobitex_portfolio_exposure_percent'] < (float)$presets['aggressive']['nobitex_portfolio_exposure_percent'], 'Balanced exposure must be below aggressive exposure.');
expectCommandCenter((float)$presets['safe']['daily_loss_limit_percent'] < (float)$presets['aggressive']['daily_loss_limit_percent'], 'Safe daily loss limit must be below aggressive limit.');

$required = [
    'trading.emergency_modes_v1',
    'trading.settings_presets_history_v1',
    'trading.settings_risk_preview_v1',
    'trading.shadow_evaluation_v1',
    'trading.strategy_lab_v1',
    'trading.trade_replay_v1',
    'analytics.command_center_v1',
    'analytics.market_radar_v1',
    'analytics.risk_heatmap_v1',
    'analytics.equity_curve_v1',
    'notifications.rules_v1',
    'ui.command_center_v1',
];
foreach ($required as $capability) {
    expectCommandCenter(in_array($capability, ReleaseContract::CAPABILITIES, true), "Missing release capability: {$capability}");
}

$apiSource = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
foreach ([
    '/api/command-center',
    '/api/settings/history',
    '/api/settings/preview',
    '/api/settings/preset',
    '/api/settings/rollback',
    '/api/emergency',
    '/api/notification-rules',
    '/api/shadow-mode',
    '/api/strategy-lab',
    '/api/trade-replay/',
] as $route) {
    expectCommandCenter(str_contains($apiSource, $route), "API route is missing: {$route}");
}

$emergencySource = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexEmergencyController.php') ?: '';
expectCommandCenter(str_contains($emergencySource, "mode!=='graceful_close'"), 'Emergency executor must be reduction-only outside graceful_close mode.');
expectCommandCenter(!str_contains($emergencySource, "'type'=>'buy'"), 'Emergency executor must never submit BUY.');
expectCommandCenter(str_contains($emergencySource, "'type'=>'sell'"), 'Emergency executor must submit SELL for graceful close.');

fwrite(STDOUT, "Trading command center regression tests passed.\n");
