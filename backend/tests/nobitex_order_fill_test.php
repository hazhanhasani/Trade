<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexOrderFill;
use Trade\Trading\NobitexOrderService;

function fillAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$active = [
    'status'=>'Active',
    'amount'=>'10',
    'matchedAmount'=>'0',
    'unmatchedAmount'=>'10',
    'price'=>'100',
];
fillAssert(NobitexOrderFill::requestedAmount($active) === 10.0, 'Requested amount should read order amount.');
fillAssert(NobitexOrderFill::matchedAmount($active, 10.0) === 0.0, 'Active order amount must never be treated as a fill.');
fillAssert(NobitexOrderFill::fillRatio($active, 10.0, 10.0) === 0.0, 'Unfilled active order must report zero fill ratio.');

$normalized=(new NobitexOrderService())->normalizedOrder(['order'=>$active]);
fillAssert((float)($normalized['amount']??-1)===0.0, 'Normalized active order must zero requested amount in the legacy fill slot.');
fillAssert((float)($normalized['requestedAmount']??0)===10.0, 'Normalized active order must preserve requested amount separately.');

$partial = [
    'status'=>'Canceled',
    'amount'=>'10',
    'matchedAmount'=>'2.5',
    'unmatchedAmount'=>'7.5',
    'averagePrice'=>'101.2',
];
fillAssert(abs(NobitexOrderFill::matchedAmount($partial) - 2.5) < 0.000001, 'Canceled partial order must preserve matchedAmount.');
fillAssert(abs(NobitexOrderFill::fillRatio($partial) - 0.25) < 0.000001, 'Partial fill ratio mismatch.');
fillAssert(abs(NobitexOrderFill::averagePrice($partial) - 101.2) < 0.000001, 'Partial average fill price mismatch.');
$normalizedPartial=(new NobitexOrderService())->normalizedOrder(['order'=>$partial]);
fillAssert((float)($normalizedPartial['amount']??-1)===0.0, 'Normalized canceled partial must not leak requested amount as fill.');
fillAssert(abs((float)($normalizedPartial['matchedAmount']??0)-2.5)<0.000001, 'Normalized canceled partial must keep actual matched amount.');

$done = ['status'=>'Done','amount'=>'3','price'=>'99.5'];
fillAssert(NobitexOrderFill::matchedAmount($done, 3.0) === 3.0, 'Done order may fall back to requested amount only when matched field is absent.');
fillAssert(abs(NobitexOrderFill::averagePrice($done) - 99.5) < 0.000001, 'Done limit order should fall back to persisted order price.');

$doneExplicitZero = ['status'=>'Done','amount'=>'3','matchedAmount'=>'0','price'=>'99.5'];
fillAssert(NobitexOrderFill::matchedAmount($doneExplicitZero, 3.0) === 0.0, 'Explicit matchedAmount=0 must not be overwritten by requested amount.');

$failed = ['status'=>'Canceled','amount'=>'5','matchedAmount'=>'0'];
fillAssert(NobitexOrderFill::matchedAmount($failed, 5.0) === 0.0, 'Canceled zero-fill order must stay zero even with fallback.');
fillAssert(NobitexOrderFill::isTerminal($failed), 'Canceled order should be terminal.');

echo "Nobitex canonical fill semantics regression tests passed.\n";
