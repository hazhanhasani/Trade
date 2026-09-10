<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Integrations\BaleTradeNotifier;

function assertTrue(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$buy = BaleTradeNotifier::formatTradeMessage('buy', [
    'symbol'=>'GRAMIRT',
    'asset'=>'GRAM',
    'quote_asset'=>'IRT',
    'amount'=>2.5,
    'entry_price'=>19_791_104.0, // RLS internally => 1,979,110 Toman in channel.
    'strategy_key'=>'breakout_v1',
    'market_regime'=>'breakout_up',
    'tradable_net_edge_percent'=>0.742,
    'time_iran'=>'2026-09-11 01:30:00',
]);
assertTrue(str_contains($buy, '🟢 خرید انجام شد'), 'BUY title missing.');
assertTrue(str_contains($buy, 'GRAM / تومان'), 'IRT should be shown as Toman.');
assertTrue(str_contains($buy, '1,979,110 تومان'), 'RLS->Toman price conversion is wrong.');
assertTrue(str_contains($buy, 'شکست محدوده'), 'Strategy Persian label missing.');
assertTrue(str_contains($buy, 'شکست صعودی'), 'Regime Persian label missing.');
assertTrue(!str_contains($buy, '19,791,104 تومان'), 'Raw RLS leaked as Toman.');

$sell = BaleTradeNotifier::formatTradeMessage('sell', [
    'symbol'=>'GRAMIRT',
    'asset'=>'GRAM',
    'quote_asset'=>'IRT',
    'amount'=>2.5,
    'entry_price'=>19_000_000.0,
    'exit_price'=>20_000_000.0,
    'net_pnl'=>2_500_000.0,
    'pnl_percent'=>5.263,
    'strategy_key'=>'trend_momentum_v1',
    'exit_reason'=>'profit_giveback',
    'time_iran'=>'2026-09-11 01:40:00',
]);
assertTrue(str_contains($sell, '🔴 فروش انجام شد'), 'SELL title missing.');
assertTrue(str_contains($sell, '2,000,000 تومان'), 'SELL exit price conversion is wrong.');
assertTrue(str_contains($sell, '+250,000 تومان'), 'PnL RLS->Toman conversion is wrong.');
assertTrue(str_contains($sell, '+5.263٪'), 'PnL percent missing.');
assertTrue(str_contains($sell, 'قفل سود / برگشت از اوج'), 'Exit reason Persian label missing.');

$usdt = BaleTradeNotifier::formatTradeMessage('buy', [
    'symbol'=>'BTCUSDT','asset'=>'BTC','quote_asset'=>'USDT','amount'=>0.01,'entry_price'=>60000.25,
]);
assertTrue(str_contains($usdt, '60,000.2500 USDT'), 'USDT must not be divided by ten.');

echo "Bale trade notifier regression tests passed.\n";
