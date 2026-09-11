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
if (!nearCurrency((float)($snapshot['available_cash_by_quote']['IRT'] ?? 0), 4947776.0)) failCurrency('available IRT cash must use active RLS balance');

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

// Nobitex returns official rialBalance/rialBalanceSell fields for wallets. The
// dashboard must use those exchange-native Rial values instead of recomputing a
// slightly different value from a local order-book mark.
$wallets3 = [
    'wallets'=>[
        [
            'currency'=>'rls',
            'balance'=>'10000000',
            'activeBalance'=>'9000000',
            'blockedBalance'=>'1000000',
            'rialBalance'=>10000000,
            'rialBalanceSell'=>10000000,
        ],
        [
            'currency'=>'btc',
            'balance'=>'0.001',
            'activeBalance'=>'0.0008',
            'blockedBalance'=>'0.0002',
            'rialBalance'=>60000000,
            'rialBalanceSell'=>59000000,
        ],
    ],
];
$snapshot3 = $valuation->snapshot($client, $wallets3, []);
if (($snapshot3['model'] ?? '') !== 'nobitex_full_spot_wallet_valuation_v4') failCurrency('wallet valuation v4 model marker missing');
if (($snapshot3['valuation_source'] ?? '') !== 'nobitex_wallet_rialBalance') failCurrency('official Nobitex rialBalance must be the primary wallet valuation source');
if (!nearCurrency((float)($snapshot3['wallet_total_rls'] ?? 0), 70000000.0)) failCurrency('official wallet Rial values were not summed exactly');
if (!nearCurrency((float)($snapshot3['wallet_total_toman'] ?? 0), 7000000.0)) failCurrency('official wallet total was not normalized to Toman');
if (!nearCurrency((float)($snapshot3['wallet_total_sell_toman'] ?? 0), 6900000.0)) failCurrency('official rialBalanceSell total was not retained');
if (!nearCurrency((float)($snapshot3['available_cash_by_quote']['IRT'] ?? 0), 900000.0)) failCurrency('available cash must use active RLS balance rather than total balance');
$btc = array_values(array_filter((array)($snapshot3['wallet_assets'] ?? []), static fn(array $row): bool => ($row['asset'] ?? '') === 'BTC'))[0] ?? [];
if (($btc['price_source'] ?? '') !== 'nobitex_wallet_rialBalance') failCurrency('crypto wallet must retain official Nobitex valuation source');
if (!nearCurrency((float)($btc['value_toman'] ?? 0), 6000000.0)) failCurrency('BTC official rialBalance was not used for dashboard value');
if (!nearCurrency((float)($btc['available_value_toman'] ?? 0), 4800000.0)) failCurrency('available crypto value must preserve the official wallet price basis');

echo "Nobitex currency unit regression tests passed.\n";
