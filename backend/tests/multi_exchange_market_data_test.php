<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\MarketData\MarketDataHub;
use Trade\Trading\AutoTraderEngine;
use Trade\Trading\NobitexExternalMarketOracle;
use Trade\Trading\OrderService;

function mdAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function mdNear(float $actual, float $expected, float $eps, string $message): void { mdAssert(abs($actual-$expected) <= $eps, $message . " actual={$actual} expected={$expected}"); }

$quotes = [
    'bitpin'=>['status'=>'ok','mid'=>100000.0],
    'tabdeal'=>['status'=>'ok','mid'=>100400.0],
    'abantether'=>['status'=>'ok','mid'=>100200.0],
    // Simulates a Rial/Toman unit mistake or otherwise broken venue quote.
    'bit24'=>['status'=>'ok','mid'=>1000000.0],
];
$consensus = MarketDataHub::consensusFromQuotes($quotes);
mdAssert(($consensus['available'] ?? false) === true, 'Consensus should survive one extreme source outlier.');
mdAssert(($consensus['source_count'] ?? 0) === 3, 'Exactly three agreeing sources should remain.');
mdAssert(isset($consensus['rejected']['bit24']), 'The 10x unit outlier must be rejected.');
mdNear((float)$consensus['reference_price'], 100200.0, 0.0001, 'Median consensus is wrong.');

$discount = NobitexExternalMarketOracle::assessFromConsensus(99800.0, $consensus);
mdAssert(($discount['hard_block'] ?? true) === false, 'A discount must not hard-block a BUY.');
mdNear((float)($discount['penalty_percent'] ?? -1), 0.0, 0.000001, 'External data must never create a negative penalty/positive edge boost.');

$smallPremium = NobitexExternalMarketOracle::assessFromConsensus(101500.0, $consensus);
mdAssert(($smallPremium['hard_block'] ?? true) === false, 'A small venue premium should be penalized, not hard-blocked.');
mdAssert((float)($smallPremium['penalty_percent'] ?? 0) > 0.0, 'A measurable premium should consume some execution edge.');

$extremePremium = NobitexExternalMarketOracle::assessFromConsensus(109000.0, $consensus);
mdAssert(($extremePremium['hard_block'] ?? false) === true, 'An extreme Nobitex premium must veto the automated BUY.');

$insufficient = MarketDataHub::consensusFromQuotes([
    'bitpin'=>['status'=>'ok','mid'=>100000.0],
    'tabdeal'=>['status'=>'error','mid'=>null],
]);
mdAssert(($insufficient['available'] ?? true) === false, 'One source is not enough to influence execution.');

// Provider parser isolation: a multi-asset response must select only the exact
// requested asset instead of taking a median across unrelated currencies.
$hub = new MarketDataHub();
$abanParser = new ReflectionMethod(MarketDataHub::class, 'abanPrices');
$abanFixture = [
    'data'=>[
        ['symbol'=>'BTC','buy_price'=>'100000','sell_price'=>'100200'],
        ['symbol'=>'ETH','buy_price'=>'5000','sell_price'=>'5050'],
    ],
];
[$abanBid,$abanAsk,$abanLast] = $abanParser->invoke($hub, $abanFixture, 'BTC');
mdNear((float)$abanBid, 100000.0, 0.0001, 'Aban parser mixed another asset into BTC bid.');
mdNear((float)$abanAsk, 100200.0, 0.0001, 'Aban parser mixed another asset into BTC ask.');
mdNear((float)$abanLast, 0.0, 0.0001, 'Aban fixture should not manufacture a last price.');

$unknownAssetRejected = false;
try { $abanParser->invoke($hub, $abanFixture, 'SOL'); }
catch (Throwable $e) { $unknownAssetRejected = str_contains($e->getMessage(), 'requested asset'); }
mdAssert($unknownAssetRejected, 'Aban parser must reject a multi-asset payload when the requested symbol is absent.');

$bitpinEngine = (new AutoTraderEngine())->run();
mdAssert(($bitpinEngine['status'] ?? '') === 'market_data_only', 'Legacy Bitpin engine must be a deterministic market-data-only no-op.');
mdAssert(($bitpinEngine['execution_allowed'] ?? true) === false, 'Bitpin execution must stay disabled.');

$thrown = false;
try { (new OrderService())->create(['symbol'=>'BTC_IRT','side'=>'buy','base_amount'=>0.001]); }
catch (RuntimeException $e) { $thrown = str_contains($e->getMessage(), 'market-data-only'); }
mdAssert($thrown, 'Bitpin OrderService must hard-block order creation regardless of old flags.');

$cancelThrown = false;
try { (new OrderService())->cancel('legacy-order-id'); }
catch (RuntimeException $e) { $cancelThrown = str_contains($e->getMessage(), 'market-data-only'); }
mdAssert($cancelThrown, 'Bitpin OrderService must hard-block cancellation calls as an execution surface.');

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/exchanges.php');
mdAssert(is_string($admin), 'Unable to inspect exchange administration page.');
mdAssert(str_contains($admin, "if(\$exchange!=='nobitex')"), 'Admin POST boundary must reject execution controls for every exchange except Nobitex.');
mdAssert(!str_contains($admin, 'name="exchange" value="bitpin"'), 'Admin UI must not expose a Bitpin bot/live execution control.');
mdAssert(str_contains($admin, 'Bitpin execution: قفل دائمی'), 'Admin UI must make the permanent Bitpin execution lock explicit.');
foreach (['آبان‌تتر','بیت۲۴','تبدیل (Tabdeal)','Market Data only'] as $label) {
    mdAssert(str_contains($admin, $label), 'Admin Market Data source/role label is missing: ' . $label);
}

$legacyOrderService = file_get_contents(dirname(__DIR__) . '/src/Trading/OrderService.php');
$legacyEngine = file_get_contents(dirname(__DIR__) . '/src/Trading/AutoTraderEngine.php');
mdAssert(is_string($legacyOrderService) && is_string($legacyEngine), 'Unable to inspect legacy Bitpin execution surfaces.');
mdAssert(str_contains($legacyOrderService, 'liveEnabled(): bool') && str_contains($legacyOrderService, 'return false;'), 'Bitpin live execution guard must remain hard-disabled.');
mdAssert(str_contains($legacyEngine, "'execution_allowed'=>false"), 'Legacy Bitpin engine must remain non-executable.');

echo "Multi-exchange market-data and execution-boundary regression tests passed.\n";
