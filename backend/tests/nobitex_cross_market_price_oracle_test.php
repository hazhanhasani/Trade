<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexCrossMarketPriceOracle;

function expectCrossMarket(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$response = [
    'data' => [
        'BTCIRT' => [
            'asks' => [[101.0, 1.0]],
            'bids' => [[99.0, 1.0]],
        ],
        'BTCUSDT' => [
            'asks' => [[2.01, 1.0]],
            'bids' => [[1.99, 1.0]],
        ],
        'USDTIRT' => [
            'asks' => [[48.1, 100.0]],
            'bids' => [[47.9, 100.0]],
        ],
    ],
];

$catalog = NobitexCrossMarketPriceOracle::catalogFromResponse($response);
expectCrossMarket(isset($catalog['BTCIRT'], $catalog['BTCUSDT'], $catalog['USDTIRT']), 'IRT, USDT and USDT/IRT books must all be normalized.');

$reference = NobitexCrossMarketPriceOracle::fromCatalog([
    'asset'=>'BTC',
    'quote_asset'=>'IRT',
    'price'=>100.0,
    'best_ask'=>100.0,
    'best_bid'=>100.0,
], $catalog);

expectCrossMarket(($reference['available'] ?? false) === true, 'Complete paired Nobitex markets must produce a cross-market reference.');
expectCrossMarket(($reference['source'] ?? '') === NobitexCrossMarketPriceOracle::MODEL, 'Cross-market source/model mismatch.');
expectCrossMarket(abs((float)$reference['local_spot_price_irt'] - 100.0) < 0.000001, 'Local IRT spot midpoint is wrong.');
expectCrossMarket(abs((float)$reference['global_spot_price_usdt'] - 2.0) < 0.000001, 'USDT spot/global reference midpoint is wrong.');
expectCrossMarket(abs((float)$reference['usdt_irt_rate'] - 48.0) < 0.000001, 'Nobitex USDT/IRT conversion midpoint is wrong.');
expectCrossMarket(abs((float)$reference['cross_implied_candidate_price'] - 96.0) < 0.000001, 'Cross-implied IRT price is wrong.');
expectCrossMarket((float)$reference['basis_percent'] > 4.1 && (float)$reference['basis_percent'] < 4.2, 'Expected BTC IRT premium basis was not detected.');
expectCrossMarket((float)$reference['directional_adjustment_percent'] < 0.0, 'Premium local spot should create a bounded negative directional adjustment.');
expectCrossMarket((float)$reference['directional_adjustment_percent'] >= -0.150001, 'Directional adjustment exceeded its hard bound.');
expectCrossMarket((float)$reference['uncertainty_percent'] > 0.0 && (float)$reference['uncertainty_percent'] <= 0.120001, 'Large basis must add bounded uncertainty.');
expectCrossMarket(($reference['quality_ready'] ?? false) === true, 'Tight synthetic reference books should pass quality.');

$missing = NobitexCrossMarketPriceOracle::fromCatalog(['asset'=>'ETH','quote_asset'=>'IRT','price'=>100.0], $catalog);
expectCrossMarket(($missing['available'] ?? true) === false, 'Missing paired spot market must stay unavailable/neutral.');
expectCrossMarket(($missing['reason'] ?? '') === 'paired_market_missing', 'Missing paired-market reason mismatch.');
expectCrossMarket(abs((float)($missing['directional_adjustment_percent'] ?? 99.0)) < 0.000001, 'Unavailable reference must never invent directional edge.');

$adaptiveSource = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexAdaptivePolicyLearner.php');
expectCrossMarket(is_string($adaptiveSource) && str_contains($adaptiveSource, 'NobitexCrossMarketPriceOracle::applyToSignal($signal, $market)'), 'Cross-market reference must be wired into the final Profit-First/adaptive signal path.');

fwrite(STDOUT, "Nobitex cross-market price oracle regression tests passed.\n");
