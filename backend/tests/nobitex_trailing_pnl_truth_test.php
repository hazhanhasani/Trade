<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Integrations\BaleTradeNotifier;
use Trade\Trading\RiskManager;

function expectTrailingTruth(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$risk = new RiskManager();
$base = [
    'entry_price'=>100.0,
    'amount'=>1.0,
    'quote_asset'=>'IRT',
    'stop_loss'=>97.0,
    'take_profit'=>106.0,
    'peak_price'=>102.0,
    'trailing_stop'=>101.0,
    'entry_fee_quote'=>0.25,
];

$negative = $base + ['estimated_exit_fee_quote'=>0.25];
$negativeReason = $risk->exitReason(99.90, $negative, 'hold');
expectTrailingTruth(
    $negativeReason === 'trailing_reversal_protection_after_costs',
    'A crossed trail with negative after-cost PnL must remain protective but must not be labelled as profit lock.'
);

$positive = $base + ['estimated_exit_fee_quote'=>0.252];
$positiveReason = $risk->exitReason(100.80, $positive, 'hold');
expectTrailingTruth(
    $positiveReason === 'trailing_profit_lock',
    'A crossed trail with positive after-cost PnL should keep the trailing profit-lock reason.'
);

$baleSource = file_get_contents(dirname(__DIR__) . '/src/Integrations/BaleTradeNotifier.php') ?: '';
expectTrailingTruth(
    str_contains($baleSource, 'AND r.accounted_at IS NOT NULL') && str_contains($baleSource, 'AND r.net_pnl IS NOT NULL'),
    'Bale SELL discovery must wait for finalized fee accounting instead of sending provisional gross PnL.'
);

$message = BaleTradeNotifier::formatTradeMessage('sell', [
    'asset'=>'CATI',
    'quote_asset'=>'IRT',
    'amount'=>8.52,
    'entry_price'=>161250.0,
    'exit_price'=>160980.0,
    'net_pnl'=>-7300.0,
    'pnl_percent'=>-0.53,
    'exit_reason'=>'trailing_reversal_protection_after_costs',
    'time_iran'=>'1405/06/22 13:37:21',
]);
expectTrailingTruth(
    str_contains($message, 'خروج حفاظتی پس از برگشت از اوج (پس از هزینه‌ها)'),
    'Bale must describe a negative crossed-trail exit truthfully.'
);

fwrite(STDOUT, "Trailing exit and confirmed PnL truth regression tests passed.\n");
