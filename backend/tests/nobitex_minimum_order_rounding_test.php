<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexOrderSizing;

function assertMinimumSizing(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$recoverable = NobitexOrderSizing::forEntry(51_500.0, 60_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($recoverable['allowed'] ?? false) === true, 'one-step minimum-order rounding should be recoverable');
assertMinimumSizing(($recoverable['rounded_up'] ?? false) === true, 'recoverable sizing should report the upward precision step');
assertMinimumSizing(abs((float)($recoverable['amount'] ?? 0.0) - 10.0) < 1e-9, 'amount should rise exactly one integer precision step');
assertMinimumSizing((float)($recoverable['order_value'] ?? 0.0) >= 50_000.0, 'rounded order must satisfy the exchange minimum');

$capBlocked = NobitexOrderSizing::forEntry(51_500.0, 53_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($capBlocked['allowed'] ?? true) === false, 'round-up must remain blocked when it would exceed the hard cap');
assertMinimumSizing(($capBlocked['reason'] ?? '') === 'minimum_order_rounding_exceeds_hard_cap', 'hard-cap rejection reason should be explicit');

$alreadyValid = NobitexOrderSizing::forEntry(55_000.0, 60_000.0, 5_500.0, 50_000.0, 1);
assertMinimumSizing(($alreadyValid['allowed'] ?? false) === true, 'already-valid floor sizing should remain valid');
assertMinimumSizing(($alreadyValid['rounded_up'] ?? true) === false, 'already-valid sizing must not be rounded upward');

$multiStep = NobitexOrderSizing::forEntry(10_000.0, 60_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($multiStep['allowed'] ?? true) === false, 'helper must not make a multi-step sizing jump');

$engine = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
assertMinimumSizing(is_string($engine) && substr_count($engine, 'NobitexOrderSizing::forEntry(') >= 2, 'portfolio and bootstrap paths must both use safe minimum-order sizing');
assertMinimumSizing(str_contains((string)$engine, "'hard_entry_cap'"), 'entry budget must expose the hard entry cap');

echo "Nobitex minimum-order rounding regression tests passed.\n";
