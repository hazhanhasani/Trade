<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Integrations\BaleTradeNotifier;
use Trade\Observability\BaleSystemAlert;
use Trade\Observability\ErrorReporter;
use Trade\Observability\HostHealthSentinel;
use Trade\Support\IranClock;
use Trade\Trading\AutoTraderEngine;
use Trade\Trading\NobitexAutoTraderEngine;
use Trade\Trading\NobitexDustConverter;
use Trade\Trading\NobitexRuntimeModels;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) {
    fwrite(STDERR, "Trade is not installed.\n");
    exit(2);
}

$heartbeatPath = dirname(__DIR__) . '/storage/cron-heartbeat.json';
$heartbeat = [
    'status' => 'running',
    'started_at' => gmdate(DATE_ATOM),
    'started_at_iran' => IranClock::nowPayload(),
    'finished_at' => null,
    'backend_version' => Updater::currentVersion(),
    'pid' => getmypid(),
    'sapi' => PHP_SAPI,
    'php_version' => PHP_VERSION,
];
$writeHeartbeat = static function (array $payload) use ($heartbeatPath): void {
    @file_put_contents($heartbeatPath,json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),LOCK_EX);
};
$writeHeartbeat($heartbeat);
register_shutdown_function(static function () use (&$heartbeat, $writeHeartbeat): void {
    if (($heartbeat['status'] ?? '') !== 'running') return;
    $last = error_get_last();
    $heartbeat['status'] = $last ? 'fatal' : 'terminated';
    $heartbeat['finished_at'] = gmdate(DATE_ATOM);
    $heartbeat['finished_at_iran'] = IranClock::nowPayload();
    if ($last) {
        $heartbeat['last_error'] = [
            'type'=>(int)($last['type']??0),'message'=>mb_substr((string)($last['message']??''),0,1000),
            'file'=>(string)($last['file']??''),'line'=>(int)($last['line']??0),
        ];
    }
    $writeHeartbeat($heartbeat);
});

$pdo = Database::connection();
$versionBeforeUpdate = Updater::currentVersion();
$update = Updater::autoUpdateIfDue();
$versionAfterUpdate = Updater::currentVersion();
$updatedThisProcess = ($update['status'] ?? '') === 'updated' && $versionAfterUpdate !== $versionBeforeUpdate && version_compare($versionAfterUpdate,$versionBeforeUpdate,'>');

if (($update['status'] ?? '') === 'updated' && !$updatedThisProcess) {
    $update['previous_result_status']='updated';$update['status']='up_to_date';$update['current_version']=$versionAfterUpdate;$update['latest_version']=(string)($update['latest_version']??$versionAfterUpdate);$update['replayed_update_state']=true;
}

if ($updatedThisProcess) {
    $heartbeat['status']='updated_deferred';$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['backend_version']=$versionAfterUpdate;$writeHeartbeat($heartbeat);
    echo json_encode(['status'=>'success','update'=>$update,'backend_version'=>$versionAfterUpdate,'exchanges'=>['bitpin'=>['status'=>'deferred','reason'=>'backend_updated_restart_next_tick'],'nobitex'=>['status'=>'deferred','reason'=>'backend_updated_restart_next_tick']],'time_utc'=>gmdate(DATE_ATOM),'time_iran'=>IranClock::nowPayload()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

try {
    NobitexSchema::ensure();
    $runId=bin2hex(random_bytes(12));
    $stmt=$pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");$stmt->execute([':id'=>$runId]);

    $baseSummary=[
        'run_id'=>$runId,'strategy_mode'=>NobitexRuntimeModels::STRATEGY_MODE,'decision_model'=>NobitexRuntimeModels::DECISION,'selection_model'=>NobitexRuntimeModels::SELECTION,
        'execution_model'=>NobitexRuntimeModels::EXECUTION,'execution_learning_model'=>NobitexRuntimeModels::EXECUTION_LEARNING,'adaptive_execution_model'=>NobitexRuntimeModels::ADAPTIVE_EXECUTION,
        'global_portfolio_model'=>NobitexRuntimeModels::GLOBAL_PORTFOLIO,'strategy_learning_model'=>NobitexRuntimeModels::STRATEGY_LEARNING,'edge_calibration_model'=>NobitexRuntimeModels::EDGE_CALIBRATION,
        'order_value_guard_model'=>NobitexRuntimeModels::ORDER_VALUE_GUARD,'nobitex_universe'=>'all_executable_irt_usdt_spot_markets','universe_awareness'=>'full_orderbook_scan_each_tick',
        'deep_analysis'=>'all_executable_markets_no_top_n_gate','score_based_selection'=>false,'signal_source'=>'nobitex_internal_1m_5m_15m','tradingview_dependency'=>false,
        'analysis_interval_target_seconds'=>60,'quote_priority'=>['IRT','USDT'],'execution_mode'=>'live_only','update'=>$update,'backend_version'=>$versionAfterUpdate,'time_utc'=>gmdate(DATE_ATOM),'time_iran'=>IranClock::nowPayload(),
    ];

    $results=[];$enabledCount=0;$failedCount=0;
    $runExchange=static function(string $exchange,callable $runner)use(&$results,&$enabledCount,&$failedCount):void{
        if(!NobitexSchema::botEnabled($exchange)){$results[$exchange]=['status'=>'disabled','exchange'=>$exchange];return;}
        $enabledCount++;
        try{$results[$exchange]=$runner();}
        catch(Throwable $e){$failedCount++;$results[$exchange]=['status'=>'failed','exchange'=>$exchange,'error'=>$e->getMessage()];ErrorReporter::captureThrowable($e,'error','cron_exchange',['exchange'=>$exchange,'status'=>'failed']);}
    };

    $runExchange('bitpin',static fn():array=>(new AutoTraderEngine())->run());
    $runExchange('nobitex',static function():array{$engine=new NobitexAutoTraderEngine();$engine->runBootstrapIfPending();return$engine->run();});

    $dust=['status'=>'disabled'];
    try{$dust=(new NobitexDustConverter())->runIfDue($pdo);}catch(Throwable $e){$dust=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];ErrorReporter::captureThrowable($e,'error','dust_converter_cron',['exchange'=>'nobitex']);}

    $hostHealth=['status'=>'ok','alert_count'=>0];
    try{$hostHealth=(new HostHealthSentinel())->run();}catch(Throwable $e){$hostHealth=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];ErrorReporter::captureThrowable($e,'warning','host_health_sentinel');}

    $bale=['status'=>'disabled'];
    try{
        $notifier=new BaleTradeNotifier();$baleStatus=$notifier->status($pdo);
        if(($baleStatus['enabled']??false)&&($baleStatus['configured']??false)){
            $sync=$notifier->syncConfirmedTrades(50,$pdo);$delivery=$notifier->flushPending(15,$pdo);$systemDelivery=(new BaleSystemAlert())->flushPending(20,$pdo);
            $bale=['status'=>'ok','sync'=>$sync,'delivery'=>$delivery,'system_alerts'=>$systemDelivery];
        }
    }catch(Throwable $e){$bale=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];ErrorReporter::captureThrowable($e,'warning','bale_delivery',['status'=>'deferred']);}

    $overall=$failedCount===0?'success':(($enabledCount>$failedCount)?'partial':'failed');
    $summary=$baseSummary+['exchanges'=>$results,'dust_conversion'=>$dust,'host_health_sentinel'=>$hostHealth,'bale_notifications'=>$bale,'kill_switch'=>(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0')==='1'];
    $stmt=$pdo->prepare("UPDATE bot_runs SET status=:status,summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");$stmt->execute([':status'=>$overall,':summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$runId]);

    $heartbeat['status']=$overall;$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['run_id']=$runId;$heartbeat['backend_version']=$versionAfterUpdate;$writeHeartbeat($heartbeat);
    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($overall==='failed'?1:0);
} catch (Throwable $e) {
    ErrorReporter::captureThrowable($e,'critical','cron_tick',['status'=>'failed_before_summary']);
    $heartbeat['status']='failed_before_summary';$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['error']=mb_substr($e->getMessage(),0,1000);$heartbeat['backend_version']=Updater::currentVersion();$writeHeartbeat($heartbeat);
    fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);
}
