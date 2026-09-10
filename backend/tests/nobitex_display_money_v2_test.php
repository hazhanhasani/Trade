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

echo "nobitex_display_money_v2_test: OK\n";
