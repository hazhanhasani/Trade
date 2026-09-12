<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/bitpin_market_data_only_cleanup_test.php';

use Trade\Trading\NobitexCapacityManager;
use Trade\Trading\NobitexRuntimeSafety;

function expectSafety(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$healthy=NobitexRuntimeSafety::adaptiveMaxPositions(8,[1.2,0.8,0.4,-0.1,0.7,0.3]);expectSafety((int)$healthy['effective']===8,'Healthy history must not increase or reduce configured capacity unnecessarily.');
$weak=NobitexRuntimeSafety::adaptiveMaxPositions(8,[-0.2,-0.3,-0.1,0.2,-0.4,0.1]);expectSafety((int)$weak['effective']<=5,'Weak recent performance must reduce effective capacity.');expectSafety((int)$weak['effective']>=1,'Adaptive capacity must remain positive.');
$drawdown=NobitexRuntimeSafety::adaptiveMaxPositions(8,[-1.0,-0.8,-0.6,-0.5,0.2,0.3]);expectSafety((int)$drawdown['effective']===4,'Four consecutive losses must switch to defensive half-capacity mode.');
$liquidMarket=['spread_percent'=>0.10,'depth_quote'=>10000000,'signal'=>['indicators'=>['volatility_percent'=>0.5]]];$volatileMarket=['spread_percent'=>0.65,'depth_quote'=>100000,'signal'=>['indicators'=>['volatility_percent'=>3.0]]];$liquidBuffer=NobitexCapacityManager::dynamicExitBufferPercent($liquidMarket);$volatileBuffer=NobitexCapacityManager::dynamicExitBufferPercent($volatileMarket);expectSafety($liquidBuffer>=0.12&&$liquidBuffer<=1.20,'Dynamic exit buffer must stay inside the safety bounds.');expectSafety($volatileBuffer>$liquidBuffer,'Wide, volatile, shallow markets must receive a larger exit buffer.');
$cheapExit=NobitexCapacityManager::victimScore(['forward_edge_percent'=>-1.0,'unrealized_net_pnl_percent'=>0.2,'spread_percent'=>0.1,'estimated_exit_cost_percent'=>0.1,'volatility_percent'=>0.5,'depth_quote'=>10000000]);$strongPosition=NobitexCapacityManager::victimScore(['forward_edge_percent'=>2.0,'unrealized_net_pnl_percent'=>1.0,'spread_percent'=>0.1,'estimated_exit_cost_percent'=>0.1,'volatility_percent'=>0.5,'depth_quote'=>10000000]);expectSafety($cheapExit<$strongPosition,'Weak forward-edge position must rank ahead of a strong position for capacity reduction.');
fwrite(STDOUT,"Nobitex runtime safety regression tests passed.\n");
