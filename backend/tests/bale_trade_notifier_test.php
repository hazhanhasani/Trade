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
assertTrue(str_contains($buy, 'زمان ایران:'), 'Iran time label missing.');
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

$lowPriceSell = BaleTradeNotifier::formatTradeMessage('sell', [
    'symbol'=>'XIRT',
    'asset'=>'X',
    'quote_asset'=>'IRT',
    'amount'=>83907,
    'entry_price'=>14.6800, // RLS => 1.468 Toman per token.
    'exit_price'=>14.6765,  // RLS => 1.46765 Toman per token.
    'net_pnl'=>-520.0,
    'pnl_percent'=>-0.042,
    'exit_reason'=>'trailing profit lock',
    'time_iran'=>'2026-09-11 01:51:19',
]);
assertTrue(str_contains($lowPriceSell, 'قیمت خرید: 1.468 تومان'), 'Low-price IRT entry must keep meaningful decimals.');
assertTrue(str_contains($lowPriceSell, 'قیمت فروش: 1.4677 تومان'), 'Low-price IRT exit must keep meaningful decimals.');
assertTrue(str_contains($lowPriceSell, 'سود/زیان: -52 تومان (-0.042٪)'), 'Low-price PnL display is wrong.');
assertTrue(str_contains($lowPriceSell, 'قفل سود متحرک / برگشت از اوج'), 'Trailing profit lock must be Persian.');
assertTrue(!str_contains($lowPriceSell, 'قیمت خرید: 1 تومان'), 'Low-price entry was misleadingly rounded to one Toman.');

$usdt = BaleTradeNotifier::formatTradeMessage('buy', [
    'symbol'=>'BTCUSDT','asset'=>'BTC','quote_asset'=>'USDT','amount'=>0.01,'entry_price'=>60000.25,
]);
assertTrue(str_contains($usdt, '60,000.25 USDT'), 'USDT must not be divided by ten and trailing zeros should be trimmed.');

echo "Bale trade notifier regression tests passed.\n";
