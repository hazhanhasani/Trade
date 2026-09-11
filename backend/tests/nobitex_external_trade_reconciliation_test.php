<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexExternalTradeReconciler;

function expectExternal(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function nearExternal(float $a,float $b,float $eps=1e-9):bool{return abs($a-$b)<=$eps;}

$rows=NobitexExternalTradeReconciler::tradeRows(['status'=>'ok','trades'=>[
    ['id'=>101,'orderId'=>9001,'srcCurrency'=>'ton','dstCurrency'=>'rls','timestamp'=>1760000000000,'type'=>'sell','price'=>'19791104','amount'=>'4.25','total'=>'84112192','fee'=>'21028'],
]]);
expectExternal(count($rows)===1,'Official-style trades list must be extracted.');
$t=NobitexExternalTradeReconciler::normalizeTrade($rows[0]);
expectExternal(is_array($t),'Official-style trade must normalize.');
expectExternal($t['id']==='101','Trade id must remain stable as a string.');
expectExternal($t['order_id']==='9001','orderId must be preserved for bot/manual ownership checks.');
expectExternal($t['type']==='sell','SELL side must normalize.');
expectExternal($t['asset']==='TON','Source asset must normalize.');
expectExternal($t['quote']==='IRT','Nobitex RLS destination must map to logical IRT while raw price stays exchange-native.');
expectExternal(nearExternal($t['amount'],4.25),'Exact fill amount must be preserved.');
expectExternal(nearExternal($t['price'],19791104.0),'Exact exchange-native fill price must be preserved.');
expectExternal(nearExternal($t['fee_quote'],21028.0),'Exchange-reported sell fee must be preserved in quote units.');
expectExternal($t['time_utc']!==null,'Millisecond timestamp must become UTC DB time.');

$alias=NobitexExternalTradeReconciler::normalizeTrade([
    'id'=>'x2','orderId'=>'x-order','srcCurrency'=>'gram','dstCurrency'=>'usdt','timestamp'=>'2026-09-11T00:00:00Z','type'=>'sell','price'=>'3.14','amount'=>'2','fee'=>'0.01',
]);
expectExternal($alias['asset']==='TON','GRAM manual fills must reconcile against canonical TON managed positions.');
expectExternal($alias['quote']==='USDT','USDT quote must stay USDT.');

$buy=NobitexExternalTradeReconciler::normalizeTrade(['id'=>3,'srcCurrency'=>'btc','dstCurrency'=>'rls','type'=>'buy','price'=>1,'amount'=>1]);
expectExternal($buy['type']==='buy','BUY trade normalization must be explicit so runtime can ignore it safely.');

$plan=NobitexExternalTradeReconciler::allocationPlan([
    ['id'=>10,'amount'=>3.0],['id'=>11,'amount'=>5.0],
],6.5);
expectExternal(count($plan)===2,'A manual fill can span legacy duplicate managed positions.');
expectExternal((int)$plan[0]['id']===10&&nearExternal((float)$plan[0]['amount'],3.0),'Oldest managed position must be consumed first.');
expectExternal((int)$plan[1]['id']===11&&nearExternal((float)$plan[1]['amount'],3.5),'Remaining manual fill must reduce the next position only by the remaining amount.');

$oversell=NobitexExternalTradeReconciler::allocationPlan([['id'=>20,'amount'=>2.0]],10.0);
expectExternal(count($oversell)===1&&nearExternal((float)$oversell[0]['amount'],2.0),'External inventory beyond managed exposure must never make managed amount negative.');

fwrite(STDOUT,"Nobitex external trade reconciliation v2 regression tests passed.\n");
