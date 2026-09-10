<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Exchange\NobitexClient;
use Trade\Trading\NobitexPortfolioValuation;

function failCurrency(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function nearCurrency(float $actual, float $expected, float $epsilon = 0.0001): bool
{
    return abs($actual - $expected) <= $epsilon;
}

$client = new NobitexClient([]);
$valuation = new NobitexPortfolioValuation();

$wallets = [
    'wallets'=>[
        ['currency'=>'rls','activeBalance'=>'49477760'],
    ],
];

$snapshot = $valuation->snapshot($client, $wallets, []);

if (($snapshot['internal_numeraire'] ?? null) !== 'RLS') failCurrency('internal numeraire must remain RLS');
if (($snapshot['display_unit'] ?? null) !== 'TOMAN') failCurrency('display unit must be TOMAN');
if (!nearCurrency((float)($snapshot['portfolio_value_rls'] ?? 0), 49477760.0)) failCurrency('raw RLS portfolio value changed');
if (!nearCurrency((float)($snapshot['portfolio_value_irt'] ?? 0), 4947776.0)) failCurrency('RLS was not divided by 10 for IRT/Toman display');
if (!nearCurrency((float)($snapshot['cash_by_quote']['IRT'] ?? 0), 4947776.0)) failCurrency('cash_by_quote IRT is not Toman-normalized');

$wallets2 = [
    'wallets'=>[
        ['currency'=>'rls','activeBalance'=>'10000000'],
    ],
];
$positions = [[
    'quote_asset'=>'IRT',
    'amount'=>2,
    'entry_price'=>1000000,
    'mark_price'=>1000000,
]];
$snapshot2 = $valuation->snapshot($client, $wallets2, $positions);

if (!nearCurrency((float)($snapshot2['exposure_rls'] ?? 0), 2000000.0)) failCurrency('risk exposure must remain raw RLS');
if (!nearCurrency((float)($snapshot2['exposure_irt'] ?? 0), 200000.0)) failCurrency('display exposure must be Toman-normalized');
if (!nearCurrency((float)($snapshot2['portfolio_value_irt'] ?? 0), 1200000.0)) failCurrency('combined Toman portfolio value is incorrect');
if (!nearCurrency((float)($snapshot2['exposure_percent'] ?? 0), 16.6667, 0.001)) failCurrency('unit conversion changed exposure percentage');

echo "Nobitex currency unit regression tests passed.\n";
