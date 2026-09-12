<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Exchange\NobitexHttpException;
use Trade\Exchange\NobitexOrderRules;

function nrAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function nrNear(float $actual, float $expected, float $epsilon, string $message): void
{
    nrAssert(abs($actual - $expected) <= $epsilon, $message . " actual={$actual} expected={$expected}");
}

$options = [
    'nobitex'=>[
        'minOrders'=>['rls'=>'3000000','usdt'=>'11'],
        'amountPrecisions'=>[
            'ABCIRT'=>'0.1',
            'MEMEIRT'=>'10',
            'BTCUSDT'=>'0.000001',
        ],
        'pricePrecisions'=>[
            'ABCIRT'=>'10',
            'MEMEIRT'=>'100',
            'BTCUSDT'=>'0.01',
        ],
    ],
];

$normal = NobitexOrderRules::prepare([
    'type'=>'sell',
    'srcCurrency'=>'abc',
    'dstCurrency'=>'rls',
    'amount'=>'2.19',
    'execution'=>'market',
    'price'=>'1500007',
    'clientOrderId'=>'test-normal',
], $options);

nrAssert(($normal['valid'] ?? false) === true, 'Valid order should pass exchange-rule preparation.');
nrAssert(($normal['payload']['amount'] ?? '') === '2.1', 'Amount must be floored to the exact 0.1 step.');
nrAssert(($normal['payload']['price'] ?? '') === '1500000', 'Price must be floored to the exact 10-Rial step.');
nrNear((float)($normal['rules']['amount_step'] ?? 0), 0.1, 1e-12, 'Amount step was not detected.');
nrNear((float)($normal['rules']['price_step'] ?? 0), 10.0, 1e-12, 'Price step was not detected.');
nrAssert(($normal['rules']['below_minimum'] ?? true) === false, 'Order above 3,000,000 RLS must not be marked below minimum.');
nrNear((float)($normal['rules']['estimated_order_value'] ?? 0), 3150000.0, 0.001, 'Estimated normalized order value is wrong.');

$small = NobitexOrderRules::prepare([
    'type'=>'sell',
    'srcCurrency'=>'abc',
    'dstCurrency'=>'rls',
    'amount'=>'1.99',
    'execution'=>'market',
    'price'=>'1500007',
    'clientOrderId'=>'test-small',
], $options);

nrAssert(($small['valid'] ?? false) === true, 'Small order should still normalize before minimum assessment.');
nrAssert(($small['payload']['amount'] ?? '') === '1.9', 'Small order amount normalization is wrong.');
nrAssert(($small['rules']['below_minimum'] ?? false) === true, 'Sub-minimum exit must be detected locally.');
nrNear((float)($small['rules']['estimated_order_value'] ?? 0), 2850000.0, 0.001, 'Sub-minimum value calculation is wrong.');

$integerStep = NobitexOrderRules::prepare([
    'type'=>'sell',
    'srcCurrency'=>'meme',
    'dstCurrency'=>'rls',
    'amount'=>'27',
    'execution'=>'market',
    'price'=>'123456',
    'clientOrderId'=>'test-integer-step',
], $options);

nrAssert(($integerStep['valid'] ?? false) === true, 'Integer-step order should normalize.');
nrAssert(($integerStep['payload']['amount'] ?? '') === '20', 'Integer amount step 10 must be enforced as a multiple, not just zero decimals.');
nrAssert(($integerStep['payload']['price'] ?? '') === '123400', 'Integer price step 100 must be enforced as a multiple.');

$belowStep = NobitexOrderRules::prepare([
    'type'=>'sell',
    'srcCurrency'=>'meme',
    'dstCurrency'=>'rls',
    'amount'=>'5',
    'execution'=>'market',
    'price'=>'123456',
    'clientOrderId'=>'test-below-step',
], $options);

nrAssert(($belowStep['valid'] ?? true) === false, 'Amount below one exchange step must be rejected locally.');
nrAssert(($belowStep['reason'] ?? '') === 'amount_below_exchange_step', 'Wrong rejection reason for amount below step.');

$usdt = NobitexOrderRules::prepare([
    'type'=>'buy',
    'srcCurrency'=>'btc',
    'dstCurrency'=>'usdt',
    'amount'=>'0.001234567',
    'execution'=>'limit',
    'price'=>'105432.129',
    'clientOrderId'=>'test-usdt',
], $options);

nrAssert(($usdt['payload']['amount'] ?? '') === '0.001234', 'Six-decimal amount step must be applied exactly.');
nrAssert(($usdt['payload']['price'] ?? '') === '105432.12', '0.01 USDT price step must be applied exactly.');

$exception = new NobitexHttpException(200, [
    'status'=>'failed',
    'code'=>'BadPrice',
    'message'=>'Order Validation Failed',
    'errors'=>['price'=>'invalid step'],
]);

nrAssert(str_contains($exception->getMessage(), '[BadPrice]'), 'Nobitex API code must be visible in exception message.');
nrAssert(str_contains($exception->getMessage(), 'Order Validation Failed'), 'Nobitex human message must remain visible.');
nrAssert(str_contains($exception->getMessage(), 'invalid step'), 'Validation details must be visible for diagnostics.');
nrAssert($exception->apiCode() === 'BadPrice', 'apiCode() must expose the exact Nobitex code.');

echo "Nobitex order rules regression tests passed.\n";
