<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Trade\Trading\NobitexDisplayMoney;

function ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function closeTo(float $a,float $b,float $eps=0.000001):bool{return abs($a-$b)<=$eps;}

ok(closeTo(NobitexDisplayMoney::quoteValue(49477703.63,'IRT'),4947770.363),'IRT exchange-native Rial must display in Toman');
ok(closeTo(NobitexDisplayMoney::quoteValue(123.456,'USDT'),123.456),'USDT must stay unchanged');

$out=NobitexDisplayMoney::walletResponse(['wallets'=>[
    ['currency'=>'RLS','activeBalance'=>49477760,'rialValue'=>49477760],
    ['currency'=>'BTC','activeBalance'=>0.001,'rialValue'=>49477703.63],
]]);
$cash=$out['wallets'][0];$btc=$out['wallets'][1];
ok(($cash['currency']??'')==='IRT','public cash currency must be IRT');
ok(($cash['raw_currency']??'')==='RLS','raw cash currency must remain auditable');
ok(closeTo((float)$cash['activeBalance'],4947776.0),'legacy public cash balance must be Toman');
ok(closeTo((float)$cash['raw_activeBalance'],49477760.0),'raw cash balance must be retained');
ok(closeTo((float)$btc['rialValue'],4947770.363),'legacy crypto wallet valuation must be Toman');
ok(closeTo((float)$btc['raw_rialValue'],49477703.63),'raw Rial valuation must be retained');
ok(closeTo((float)$btc['display_value_toman'],4947770.363),'explicit display value must match Nobitex UI unit');
ok(($out['_trade_display']['rls_per_toman']??0)===10.0,'unit metadata must be explicit');

$books=NobitexDisplayMoney::orderBooksResponse(['status'=>'ok','orderbooks'=>[
    'BTCIRT'=>['bids'=>[['494777030','0.1']], 'asks'=>[['494778000','0.2']], 'lastTradePrice'=>'494777500'],
    'BTCUSDT'=>['bids'=>[['65000.25','0.1']], 'asks'=>[['65001.25','0.2']]],
]]);
ok(closeTo((float)$books['orderbooks']['BTCIRT']['bids'][0][0],49477703.0),'IRT orderbook bid price must be Toman');
ok(closeTo((float)$books['orderbooks']['BTCIRT']['asks'][0][0],49477800.0),'IRT orderbook ask price must be Toman');
ok(closeTo((float)$books['orderbooks']['BTCIRT']['lastTradePrice'],49477750.0),'IRT last trade price must be Toman');
ok(closeTo((float)$books['orderbooks']['BTCUSDT']['bids'][0][0],65000.25),'USDT orderbook price must stay unchanged');

$orders=NobitexDisplayMoney::ordersResponse(['status'=>'ok','orders'=>[
    ['id'=>1,'srcCurrency'=>'btc','dstCurrency'=>'rls','price'=>'494777030','averagePrice'=>'494777000','totalOrderPrice'=>'49477703','amount'=>'0.1'],
    ['id'=>2,'srcCurrency'=>'btc','dstCurrency'=>'usdt','price'=>'65000.25','amount'=>'0.1'],
]]);
$irtOrder=$orders['orders'][0];$usdtOrder=$orders['orders'][1];
ok(($irtOrder['dstCurrency']??'')==='IRT','RLS destination currency must present as IRT');
ok(($irtOrder['raw_dstCurrency']??'')==='rls','raw order destination currency must be retained');
ok(closeTo((float)$irtOrder['price'],49477703.0),'IRT order price must be Toman');
ok(closeTo((float)$irtOrder['averagePrice'],49477700.0),'IRT average order price must be Toman');
ok(closeTo((float)$irtOrder['raw_price'],494777030.0),'raw order price must remain auditable');
ok(closeTo((float)$usdtOrder['price'],65000.25),'USDT order price must stay unchanged');

echo "nobitex_display_money_v2_test: OK\n";
