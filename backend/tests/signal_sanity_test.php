<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexMarketRegimeDetector;
use Trade\Trading\Strategy\BreakoutStrategy;

function expectSignalSanity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$strategy = new BreakoutStrategy();
$prices = array_fill(0, 80, 100.0);
$prices[] = 1000.0; // classic Rial/Toman x10 unit discontinuity

$context = [
    'prices'=>['1m'=>$prices],
    'market'=>['orderbook_imbalance'=>0.4],
    'indicators'=>[
        '1m'=>['momentum_5_percent'=>2,'rsi14'=>60],
        '5m'=>['momentum_5_percent'=>2],
        '15m'=>['momentum_5_percent'=>1],
    ],
];
$regime = [
    'regime'=>NobitexMarketRegimeDetector::BREAKOUT_UP,
    'confidence'=>90,
    'metrics'=>[
        'breakout_up_percent'=>900.0,
        'breakout_down_percent'=>0.0,
        'breakout_threshold_percent'=>0.08,
    ],
];

$result = $strategy->evaluate($context, $regime);
expectSignalSanity(($result['eligible'] ?? true) === false, 'x10 unit discontinuity must never be eligible.');
expectSignalSanity(($result['entry_allowed'] ?? true) === false, 'x10 unit discontinuity must never allow BUY.');
expectSignalSanity((float)($result['gross_edge_percent'] ?? 1) === 0.0, 'invalid price history must not expose a huge edge.');
expectSignalSanity(($result['reason'] ?? '') === 'price_series_unit_discontinuity', 'invalid history should have an explicit reason.');

$clean = array_map(static fn(int $i): float => 100.0 + ($i * 0.02), range(0, 80));
$cleanContext = $context;
$cleanContext['prices']['1m'] = $clean;
$cleanRegime = $regime;
$cleanRegime['metrics']['breakout_up_percent'] = 0.5;
$cleanResult = $strategy->evaluate($cleanContext, $cleanRegime);
expectSignalSanity(abs((float)($cleanResult['gross_edge_percent'] ?? 0)) <= 25.0, 'normal signal edge must remain bounded.');

echo "Signal sanity regression tests passed.\n";
