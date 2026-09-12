<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
use Trade\MarketData\MarketDataHub;use Trade\Trading\NobitexExternalMarketOracle;
function mdAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}function mdNear(float $a,float $e,float $eps,string $m):void{mdAssert(abs($a-$e)<=$eps,$m." actual={$a} expected={$e}");}

$quotes=['bitpin'=>['status'=>'ok','mid'=>100000.0],'tabdeal'=>['status'=>'ok','mid'=>100400.0],'abantether'=>['status'=>'ok','mid'=>100200.0],'bit24'=>['status'=>'ok','mid'=>1000000.0]];
$consensus=MarketDataHub::consensusFromQuotes($quotes);
mdAssert(($consensus['available']??false)===true,'Consensus should survive one extreme source outlier.');
mdAssert(($consensus['source_count']??0)===3,'Exactly three agreeing sources should remain.');
mdAssert(isset($consensus['rejected']['bit24']),'The 10x unit outlier must be rejected.');
mdNear((float)$consensus['reference_price'],100200.0,0.0001,'Median consensus is wrong.');

$discount=NobitexExternalMarketOracle::assessFromConsensus(99800.0,$consensus);
mdAssert(($discount['hard_block']??true)===false,'A discount must not hard-block a BUY.');
mdNear((float)($discount['penalty_percent']??-1),0.0,0.000001,'External data must never boost expected edge.');
$premium=NobitexExternalMarketOracle::assessFromConsensus(101500.0,$consensus);
mdAssert(($premium['hard_block']??true)===false,'A small premium should be penalized, not hard-blocked.');
mdAssert((float)($premium['penalty_percent']??0)>0.0,'A premium should consume execution edge.');
$extreme=NobitexExternalMarketOracle::assessFromConsensus(109000.0,$consensus);
mdAssert(($extreme['hard_block']??false)===true,'An extreme premium must veto automated BUY.');

$insufficient=MarketDataHub::consensusFromQuotes(['bitpin'=>['status'=>'ok','mid'=>100000.0],'tabdeal'=>['status'=>'error','mid'=>null]]);
mdAssert(($insufficient['available']??true)===false,'One source is not enough to influence execution.');

// Execution-time sanitation: malformed crossed books and stale cached quotes may
// be visible in diagnostics, but they must never participate in live consensus.
$now=1700000000;
$sanitized=NobitexExternalMarketOracle::sanitizeSources([
    'bitpin'=>['status'=>'ok','mid'=>100000.0,'bid'=>99900.0,'ask'=>100100.0,'received_unix'=>$now],
    'tabdeal'=>['status'=>'ok','mid'=>100200.0,'bid'=>100100.0,'ask'=>100300.0,'received_unix'=>$now-2],
    'bit24'=>['status'=>'ok','mid'=>100150.0,'bid'=>100300.0,'ask'=>100000.0,'received_unix'=>$now],
    'abantether'=>['status'=>'ok','mid'=>100050.0,'bid'=>100000.0,'ask'=>100100.0,'received_unix'=>$now-30],
],$now);
mdAssert(($sanitized['bit24']['status']??'')==='error'&&($sanitized['bit24']['reason']??'')==='crossed_orderbook_rejected','Crossed external book must be rejected.');
mdAssert(($sanitized['abantether']['status']??'')==='error'&&($sanitized['abantether']['reason']??'')==='stale_external_quote_rejected','Stale external quote must be rejected.');
$qualityConsensus=MarketDataHub::consensusFromQuotes($sanitized);
mdAssert(($qualityConsensus['available']??false)===true&&($qualityConsensus['source_count']??0)===2,'Two healthy external books must remain after quality filtering.');

$hub=new MarketDataHub();
$parser=new ReflectionMethod(MarketDataHub::class,'abanPrices');
$fixture=['data'=>[['symbol'=>'BTC','buy_price'=>'100000','sell_price'=>'100200'],['symbol'=>'ETH','buy_price'=>'5000','sell_price'=>'5050']]];
[$bid,$ask,$last]=$parser->invoke($hub,$fixture,'BTC');
mdNear((float)$bid,100000.0,0.0001,'Aban parser mixed another asset into BTC bid.');
mdNear((float)$ask,100200.0,0.0001,'Aban parser mixed another asset into BTC ask.');
mdNear((float)$last,0.0,0.0001,'Aban fixture should not manufacture a last price.');

$admin=(string)file_get_contents(dirname(__DIR__).'/public/admin/exchanges.php');
foreach(['آبان‌تتر','بیت۲۴','تبدیل (Tabdeal)','Bitpin','Public Market Data فعال']as$label)mdAssert(str_contains($admin,$label),'Admin source label is missing: '.$label);
require __DIR__.'/bitpin_market_data_only_cleanup_test.php';
echo "Multi-exchange market-data regression tests passed.\n";
