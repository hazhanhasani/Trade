<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexOrderFill;
use Trade\Trading\NobitexPositionReconciler;

function expect(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function near(float $a, float $b, float $epsilon = 1e-9): bool
{
    return abs($a - $b) <= $epsilon;
}

$wallets = NobitexPositionReconciler::walletTotals([
    'wallets'=>[
        ['currency'=>'X','balance'=>'100','activeBalance'=>'20','blockedBalance'=>'80'],
        ['currency'=>'Y','activeBalance'=>'7.5','blockedBalance'=>'2.5'],
        ['currency'=>'Z','available'=>'4','blocked'=>'1'],
    ],
]);
expect(near((float)$wallets['X'], 100.0), 'Total balance must be preferred over activeBalance so blocked funds do not mimic a manual sale.');
expect(near((float)$wallets['Y'], 10.0), 'activeBalance + blockedBalance fallback must preserve total inventory.');
expect(near((float)$wallets['Z'], 5.0), 'available + blocked fallback must preserve total inventory.');

$aliases = NobitexPositionReconciler::walletTotals([
    'wallets'=>[
        ['currency'=>'TON','balance'=>'10'],['currency'=>'GRAM','balance'=>'2'],['currency'=>'TONCOIN','balance'=>'3'],
    ],
]);
expect(NobitexPositionReconciler::canonicalAsset('GRAM') === 'TON', 'GRAM must canonicalize to TON for Nobitex wallet reconciliation.');
expect(NobitexPositionReconciler::canonicalAsset('TONCOIN') === 'TON', 'TONCOIN must canonicalize to TON for Nobitex wallet reconciliation.');
expect(near((float)($aliases['TON'] ?? 0), 15.0), 'TON/GRAM/TONCOIN wallet aliases must accumulate into one canonical inventory total.');

$snxGross = 1.49;
$snxNet = 1.486275;
$snxEntryPrice = 10_000.0;
$snxFeeQuote = ($snxGross - $snxNet) * $snxEntryPrice;
expect(NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>$snxGross,'entry_price'=>$snxEntryPrice,'entry_fee_quote'=>$snxFeeQuote,'quote_asset'=>'IRT',
], $snxNet), 'SNX 1.49 -> 1.486275 must be classified as BUY fee alignment, not an external sale.');

$bananaGross = 0.0784;
$bananaNet = 0.078204;
$bananaEntryPrice = 20_000_000.0;
$bananaFeeQuote = ($bananaGross - $bananaNet) * $bananaEntryPrice;
expect(NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>$bananaGross,'entry_price'=>$bananaEntryPrice,'entry_fee_quote'=>$bananaFeeQuote,'quote_asset'=>'IRT',
], $bananaNet), 'BANANA 0.0784 -> 0.078204 must be classified as BUY fee alignment, not an external sale.');

// Live 1.4.23 WLD/TRX/BICO warnings were exactly the base-deducted 0.25%
// Nobitex IRT BUY fee but their legacy rows lacked entry_fee_quote metadata.
expect(NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>0.939,'entry_price'=>100_000.0,'entry_fee_quote'=>0.0,'quote_asset'=>'IRT',
], 0.9366525), 'Legacy IRT 0.25% base deduction must be recognized even when fee metadata is missing.');
expect(NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>10.0,'entry_price'=>2.0,'entry_fee_quote'=>0.0,'quote_asset'=>'USDT',
], 9.987), 'Legacy USDT 0.13% base deduction must be recognized when fee metadata is missing.');
expect(!NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>10.0,'entry_price'=>100_000.0,'entry_fee_quote'=>0.0,'quote_asset'=>'IRT',
], 9.97), 'An arbitrary 0.30% reduction must not be hidden as the known 0.25% IRT BUY fee.');

expect(!NobitexPositionReconciler::isBuyFeeAlignment([
    'amount'=>$snxGross,'entry_price'=>$snxEntryPrice,'entry_fee_quote'=>$snxFeeQuote,'quote_asset'=>'IRT',
], 1.40), 'A material wallet reduction must not be hidden as a fee alignment.');

$buyOrder=['type'=>'buy','status'=>'Done','amount'=>'1.49','matchedAmount'=>'1.49','fee'=>'0.003725'];
expect(near(NobitexOrderFill::netReceivedBase($buyOrder), $snxNet, 1e-12), 'Canonical BUY fill must expose wallet-net base quantity after actual Nobitex fee.');
$noFeeOrder=['type'=>'buy','status'=>'Done','amount'=>'2','matchedAmount'=>'2','fee'=>'0'];
expect(near(NobitexOrderFill::netReceivedBase($noFeeOrder), 2.0), 'BUY fill without an actual fee must keep matchedAmount unchanged.');

$position = [['id'=>1,'symbol'=>'XIRT','amount'=>100.0]];
$none = NobitexPositionReconciler::plan($position, 100.0);
expect(($none['action'] ?? '') === 'none', 'Equal wallet inventory must not alter a position.');
$extra = NobitexPositionReconciler::plan($position, 130.0);
expect(($extra['action'] ?? '') === 'none', 'External extra holdings must never increase or resize a managed position.');
$tolerated = NobitexPositionReconciler::plan($position, 99.96);
expect(($tolerated['action'] ?? '') === 'none', 'Tiny wallet rounding differences inside tolerance must not resize a position.');
$partial = NobitexPositionReconciler::plan($position, 40.0);
expect(($partial['action'] ?? '') === 'resize', 'A material manual partial sale must be detected.');
expect(count($partial['positions'] ?? []) === 1, 'Partial sale should produce one position change.');
expect(($partial['positions'][0]['action'] ?? '') === 'resize', 'Remaining inventory above dust must resize, not close.');
expect(near((float)($partial['positions'][0]['after_amount'] ?? -1), 40.0), 'Managed amount must shrink to the real remaining wallet inventory.');
$dust = NobitexPositionReconciler::plan($position, 0.4);
expect(($dust['action'] ?? '') === 'close', 'Near-zero post-sale dust must close the ghost position.');
expect(($dust['positions'][0]['action'] ?? '') === 'close', 'Dust position change must be close.');
$zero = NobitexPositionReconciler::plan($position, 0.0);
expect(($zero['action'] ?? '') === 'close', 'A fully sold wallet position must close.');

$legacy = [['id'=>1,'symbol'=>'XIRT','amount'=>60.0],['id'=>2,'symbol'=>'XUSDT','amount'=>40.0]];
$legacyPlan = NobitexPositionReconciler::plan($legacy, 75.0);
expect(($legacyPlan['action'] ?? '') === 'resize', 'Legacy duplicate asset positions must reconcile deterministically.');
expect(count($legacyPlan['positions'] ?? []) === 1, 'Oldest position should retain inventory first and only deficit position should change.');
expect((int)($legacyPlan['positions'][0]['id'] ?? 0) === 2, 'Remaining wallet inventory must be allocated oldest position first.');
expect(near((float)($legacyPlan['positions'][0]['after_amount'] ?? -1), 15.0), 'Second legacy position should be reduced to remaining inventory.');

fwrite(STDOUT, "Nobitex manual position and BUY-fee reconciliation regression tests passed.\n");
