<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexExecutionPlanner;

function assertTrue(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function assertClose(float $actual,float $expected,float $epsilon,string $message):void{if(abs($actual-$expected)>$epsilon)throw new RuntimeException($message." actual={$actual} expected={$expected}");}

$planner=new NobitexExecutionPlanner();

$deepMarket=[
    'best_ask'=>100.0,'best_bid'=>99.94,'price'=>99.97,'spread_percent'=>0.06,
    'depth_quote'=>30000.0,'orderbook_imbalance'=>0.10,
];
$strongSignal=['execution_quality_score'=>88.0,'ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>0.80];
$plan=$planner->plan($deepMarket,$strongSignal,10.0,'buy');
assertTrue($plan['mode']==='market','Deep/tight/high-quality book should use market execution.');
assertTrue((int)$plan['max_reprices']===0,'Market execution must never enter reprice path.');
assertTrue((float)$plan['hard_price_limit']>(float)$plan['reference_price'],'BUY hard price bound must stay above reference.');
assertTrue(($plan['strict_market_eligible']??false)===true,'Strict high-quality path should remain available.');

// A BUY that has already passed strategy/risk and still has enough post-cost
// edge to absorb the planner's worst slippage should be sent as a real market
// execution instead of unnecessarily waiting on a passive limit order.
$edgeBackedMarket=[
    'best_ask'=>100.0,'best_bid'=>99.84,'price'=>99.92,'spread_percent'=>0.16,
    'depth_quote'=>15000.0,'orderbook_imbalance'=>0.0,
];
$edgeBackedSignal=[
    'execution_quality_score'=>68.0,
    'ready'=>true,
    'action'=>'buy',
    'tradable_net_edge_percent'=>0.55,
];
$liveFill=$planner->plan($edgeBackedMarket,$edgeBackedSignal,10.0,'buy');
assertTrue($liveFill['mode']==='market','Approved positive-edge BUY should use live-fill market execution when edge safely covers worst-case slippage.');
assertTrue(($liveFill['strict_market_eligible']??true)===false,'Edge-backed test market should not accidentally pass the original strict market path.');
assertTrue(($liveFill['edge_backed_market_eligible']??false)===true,'Positive-edge live-fill path should be explicitly active.');
assertTrue(($liveFill['reason']??'')==='positive_edge_live_fill_market_execution','Live-fill market path should expose a stable reason code.');
assertTrue((float)($liveFill['edge_after_max_slippage_percent']??0.0)>=0.12,'Approved market execution must preserve an edge reserve after worst-case slippage.');
assertTrue((int)$liveFill['max_reprices']===0,'Edge-backed market execution must not enter reprice path.');

// The execution layer must never manufacture a BUY. The same book with HOLD
// remains bounded-limit eligible only, even if a diagnostic edge is supplied.
$holdPlan=$planner->plan($edgeBackedMarket,[
    'execution_quality_score'=>68.0,
    'ready'=>true,
    'action'=>'hold',
    'tradable_net_edge_percent'=>0.55,
],10.0,'buy');
assertTrue($holdPlan['mode']==='limit','Live-fill policy must not turn HOLD into a market BUY.');
assertTrue(($holdPlan['edge_backed_market_eligible']??true)===false,'HOLD must never activate edge-backed market execution.');

$thinMarket=[
    'best_ask'=>100.0,'best_bid'=>99.60,'price'=>99.80,'spread_percent'=>0.40,
    'depth_quote'=>2500.0,'orderbook_imbalance'=>-0.35,
];
$weakSignal=['execution_quality_score'=>61.0,'ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>0.20];
$limit=$planner->plan($thinMarket,$weakSignal,10.0,'buy');
assertTrue($limit['mode']==='limit','Wide/thin book must use bounded marketable limit.');
assertTrue((int)$limit['max_reprices']===1,'Limit entry should allow exactly one bounded reprice.');
assertTrue((float)$limit['limit_price']<=(float)$limit['hard_price_limit'],'Initial BUY limit must remain inside hard slippage bound.');
assertTrue((float)$limit['max_slippage_percent']<=0.35,'Max slippage must remain bounded.');

$position=[
    'amount'=>10.0,
    'execution_reprice_count'=>0,
    'execution_max_reprices'=>1,
    'execution_hard_price_limit'=>$limit['hard_price_limit'],
];
$improved=[
    'best_ask'=>100.05,'best_bid'=>99.90,'price'=>100.0,'spread_percent'=>0.15,
    'depth_quote'=>8000.0,'orderbook_imbalance'=>0.0,
];
$reprice=$planner->canReprice($position,$improved,['execution_quality_score'=>70.0,'ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>0.10],'buy');
assertTrue(($reprice['allowed']??false)===true,'One reprice inside original bound should be allowed.');
assertTrue((float)$reprice['plan']['limit_price']<=(float)$position['execution_hard_price_limit'],'Reprice must never widen original BUY hard bound.');

$position['execution_reprice_count']=1;
$blocked=$planner->canReprice($position,$improved,['execution_quality_score'=>80.0,'ready'=>true,'action'=>'buy','tradable_net_edge_percent'=>0.10],'buy');
assertTrue(($blocked['allowed']??true)===false,'Second reprice must be blocked.');
assertTrue(($blocked['reason']??'')==='execution_reprice_limit_reached','Second reprice should expose stable reason code.');

$expired=$position;$expired['execution_reprice_count']=0;
$blockedSignal=$planner->canReprice($expired,$improved,['execution_quality_score'=>80.0,'ready'=>true,'action'=>'hold','tradable_net_edge_percent'=>0.80],'buy');
assertTrue(($blockedSignal['allowed']??true)===false,'Expired BUY signal must not be repriced.');

$sell=$planner->plan($deepMarket,$strongSignal,10.0,'sell');
assertTrue((float)$sell['hard_price_limit']<(float)$sell['reference_price'],'SELL hard bound must stay below reference.');

assertClose((float)$plan['spread_percent'],0.06,0.0001,'Planner should preserve measured spread.');
echo "Execution Quality v2 regression tests passed.\n";
