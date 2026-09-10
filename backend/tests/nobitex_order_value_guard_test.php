<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexOrderValueGuard;

function orderGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

orderGuardAssert(NobitexOrderValueGuard::amountFromInput(['amount'=>2.5]) === 2.5, '`amount` alias was not normalized.');
orderGuardAssert(NobitexOrderValueGuard::amountFromInput(['amount1'=>3.5]) === 3.5, '`amount1` alias was not normalized.');

$disabled = NobitexOrderValueGuard::assess(10.0, null, 0.0);
orderGuardAssert(($disabled['allowed'] ?? false) === true, 'Disabled max_order_value must not block an unpriced order.');
orderGuardAssert(($disabled['enabled'] ?? true) === false, 'Disabled max_order_value must report enabled=false.');

$inside = NobitexOrderValueGuard::assess(4.0, 250.0, 1000.0);
orderGuardAssert(($inside['allowed'] ?? false) === true, 'Order exactly at max_order_value should be allowed.');
orderGuardAssert(abs((float)$inside['order_value'] - 1000.0) < 0.000001, 'Order value calculation mismatch.');

$outside = NobitexOrderValueGuard::assess(4.01, 250.0, 1000.0);
orderGuardAssert(($outside['allowed'] ?? true) === false, 'Order above max_order_value must be rejected.');
orderGuardAssert(($outside['reason'] ?? '') === 'max_order_value_exceeded', 'Exceeded order value reason mismatch.');

$missingPrice = NobitexOrderValueGuard::assess(5.0, null, 1000.0);
orderGuardAssert(($missingPrice['allowed'] ?? true) === false, 'Configured cap must fail closed when no deterministic price bound exists.');
orderGuardAssert(($missingPrice['reason'] ?? '') === 'max_order_value_price_bound_unavailable', 'Missing price bound reason mismatch.');

$marketBound = NobitexOrderValueGuard::priceBound('market', 125.5, []);
orderGuardAssert($marketBound === 125.5, 'Market hard price limit should be used as the guard bound.');

$stopMarketBound = NobitexOrderValueGuard::priceBound('stop_market', 125.5, ['stopPrice'=>120]);
orderGuardAssert($stopMarketBound === null, 'Stop-market must fail closed because actual fill has no deterministic price ceiling.');

$ocoBound = NobitexOrderValueGuard::priceBound('oco', 100.0, ['stopPrice'=>105.0,'stopLimitPrice'=>110.0]);
orderGuardAssert($ocoBound === 110.0, 'OCO guard must use the strongest price bound.');

$exit = NobitexOrderValueGuard::reductionOnlyExitAssessment(10.0, 200.0, 1000.0);
orderGuardAssert(($exit['allowed'] ?? false) === true, 'Automated reduction-only SELL must not be blocked by an entry-size cap.');
orderGuardAssert(($exit['reason'] ?? '') === 'reduction_only_automated_exit_exempt', 'Reduction-only exit exemption reason mismatch.');
orderGuardAssert(abs((float)($exit['order_value'] ?? 0.0) - 2000.0) < 0.000001, 'Reduction-only exit should still report its diagnostic order value.');

$service = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexOrderService.php');
orderGuardAssert(is_string($service), 'Unable to inspect NobitexOrderService source.');
orderGuardAssert(str_contains($service, 'NobitexOrderValueGuard::amountFromInput($input)'), 'Order service is not using the canonical amount alias normalizer.');
orderGuardAssert(str_contains($service, 'NobitexOrderValueGuard::priceBound($mode,$price,$input)'), 'Order service does not resolve the final guard price.');
orderGuardAssert(str_contains($service, 'NobitexOrderValueGuard::assertWithinLimit($amount,$guardPrice,$maxOrderValue)'), 'Order service does not enforce max_order_value after price resolution.');
orderGuardAssert(str_contains($service, 'NobitexOrderValueGuard::reductionOnlyExitAssessment($amount,$guardPrice,$maxOrderValue)'), 'Automated reduction-only SELL exemption is not wired into the order service.');
orderGuardAssert(str_contains($service, "$reductionOnlyExit=\$side==='sell'&&str_starts_with(\$source,'autotrade_nobitex')"), 'Order service does not explicitly scope the cap exemption to automated SELLs.');
orderGuardAssert(str_contains($service, "if(\$kill==='1'&&\$side==='buy')"), 'Kill switch must block new BUY entries without trapping SELL exits.');
orderGuardAssert(!str_contains($service, "if(\$kill==='1') throw"), 'Unconditional kill-switch order blocking still exists.');
orderGuardAssert(strpos($service, 'NobitexExecutionPlanner())->plan') < strpos($service, 'NobitexOrderValueGuard::assertWithinLimit'), 'max_order_value must be checked after the Execution Planner resolves the final price.');
orderGuardAssert(!str_contains($service, "isset(\$order['amount1'])"), 'Legacy amount1-only max_order_value guard still exists.');

echo "Nobitex order value guard + reduction-only exit regression tests passed.\n";
