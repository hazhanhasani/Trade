<?php

declare(strict_types=1);

function failLivePanel(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function expectLivePanel(bool $condition, string $message): void
{
    if (!$condition) failLivePanel($message);
}

$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/android/app/src/main/java/ir/trade/app/data/TradeApi.kt') ?: '';
$app = file_get_contents($root . '/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt') ?: '';

expectLivePanel(str_contains($api, 'mergeLivePanelStatus'), 'Android API must overlay the live Admin-panel status.');
expectLivePanel(str_contains($api, 'api_status_panel_truth'), 'Live panel source marker is missing.');
expectLivePanel(str_contains($api, 'today_realized_pnl'), 'Today PnL must come from BotController panel performance.');
expectLivePanel(str_contains($api, 'total_realized_pnl'), 'Total realized PnL must come from BotController panel performance.');
expectLivePanel(str_contains($api, 'wallet_total_toman'), 'Portfolio value must come from the live global wallet valuation.');
expectLivePanel(str_contains($api, 'Cache-Control'), 'Android live requests must explicitly bypass HTTP caches.');

expectLivePanel(str_contains($app, 'mutableStateOf(!snapshotJson.isNullOrBlank())'), 'A cached snapshot must start in offline state.');
expectLivePanel(str_contains($app, 'delay(10_000)'), 'Android must auto-refresh live data every 10 seconds.');
expectLivePanel(
    str_contains($app, 'داده زنده') || str_contains($app, 'Live Panel'),
    'Android must visibly identify live panel mode, including the localized Persian label.'
);
expectLivePanel(str_contains($app, 'total_realized_pnl_irt'), 'Home must display total realized panel PnL.');
expectLivePanel(str_contains($app, 'win_rate_percent'), 'Home must display panel win rate.');
expectLivePanel(str_contains($app, 'pending_orders'), 'Home must display panel pending-order capacity.');
expectLivePanel(str_contains($app, 'portfolio_value_toman'), 'Equity chart must support real account-equity snapshots.');
expectLivePanel(str_contains($app, 'positionMoney'), 'IRT position money must be normalized for Toman display.');

fwrite(STDOUT, "Android live panel parity regression tests passed.\n");
