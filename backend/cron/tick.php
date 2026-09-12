<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Integrations\BaleTradeNotifier;
use Trade\Observability\BaleSystemAlert;
use Trade\Observability\ErrorReporter;
use Trade\Observability\HostHealthSentinel;
use Trade\Observability\NobitexDecisionReporter;
use Trade\Support\IranClock;
use Trade\Trading\NobitexDisplayMoney;
use Trade\Trading\NobitexDustConverter;
use Trade\Trading\NobitexExternalTradeReconciler;
use Trade\Trading\NobitexFastCycleRunner;
use Trade\Trading\NobitexPositionReconciler;
use Trade\Trading\NobitexRuntimeModels;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) { fwrite(STDERR,"Trade is not installed.\n"); exit(2); }

$heartbeatPath=dirname(__DIR__).'/storage/cron-heartbeat.json';
$heartbeat=['status'=>'running','started_at'=>gmdate(DATE_ATOM),'started_at_iran'=>IranClock::nowPayload(),'finished_at'=>null,'backend_version'=>Updater::currentVersion(),'pid'=>getmypid(),'sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION];
$writeHeartbeat=static function(array $payload)use($heartbeatPath):void{@file_put_contents($heartbeatPath,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);};
$writeHeartbeat($heartbeat);
register_shutdown_function(static function()use(&$heartbeat,$writeHeartbeat):void{
    if(($heartbeat['status']??'')!=='running')return;$last=error_get_last();$heartbeat['status']=$last?'fatal':'terminated';$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();
    if($last)$heartbeat['last_error']=['type'=>(int)($last['type']??0),'message'=>mb_substr((string)($last['message']??''),0,1000),'file'=>(string)($last['file']??''),'line'=>(int)($last['line']??0)];$writeHeartbeat($heartbeat);
});

$pdo=Database::connection();$versionBeforeUpdate=Updater::currentVersion();$update=Updater::autoUpdateIfDue();$versionAfterUpdate=Updater::currentVersion();
$updatedThisProcess=($update['status']??'')==='updated'&&$versionAfterUpdate!==$versionBeforeUpdate&&version_compare($versionAfterUpdate,$versionBeforeUpdate,'>');
if(($update['status']??'')==='updated'&&!$updatedThisProcess){$update['previous_result_status']='updated';$update['status']='up_to_date';$update['current_version']=$versionAfterUpdate;$update['latest_version']=(string)($update['latest_version']??$versionAfterUpdate);$update['replayed_update_state']=true;}
if($updatedThisProcess){
    $heartbeat['status']='updated_deferred';$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['backend_version']=$versionAfterUpdate;$writeHeartbeat($heartbeat);
    ErrorReporter::log('Backend با موفقیت داخل Cron به نسخه جدید به‌روزرسانی شد؛ اجرای معامله عمداً به Tick بعدی موکول شد.','cron_update',['status'=>'updated_deferred','from_version'=>$versionBeforeUpdate,'to_version'=>$versionAfterUpdate],'info');
    echo json_encode([
        'status'=>'success','update'=>$update,'backend_version'=>$versionAfterUpdate,
        'execution_exchange'=>'nobitex','exchanges'=>['nobitex'=>['status'=>'deferred','reason'=>'backend_updated_restart_next_tick']],
        'market_data_sources'=>['bitpin','abantether','bit24','tabdeal'],'time_utc'=>gmdate(DATE_ATOM),'time_iran'=>IranClock::nowPayload(),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;exit(0);
}

try{
    NobitexSchema::ensure();
    $runId=bin2hex(random_bytes(12));
    $stmt=$pdo->prepare("INSERT INTO bot_runs (run_id,status,started_at) VALUES (:id,'running',UTC_TIMESTAMP())");
    $stmt->execute([':id'=>$runId]);
    $baseSummary=[
        'run_id'=>$runId,
        'strategy_mode'=>NobitexRuntimeModels::STRATEGY_MODE,
        'decision_model'=>NobitexRuntimeModels::DECISION,
        'selection_model'=>NobitexRuntimeModels::SELECTION,
        'execution_model'=>NobitexRuntimeModels::EXECUTION,
        'execution_learning_model'=>NobitexRuntimeModels::EXECUTION_LEARNING,
        'adaptive_execution_model'=>NobitexRuntimeModels::ADAPTIVE_EXECUTION,
        'global_portfolio_model'=>NobitexRuntimeModels::GLOBAL_PORTFOLIO,
        'strategy_learning_model'=>NobitexRuntimeModels::STRATEGY_LEARNING,
        'edge_calibration_model'=>NobitexRuntimeModels::EDGE_CALIBRATION,
        'order_value_guard_model'=>NobitexRuntimeModels::ORDER_VALUE_GUARD,
        'fast_cycle_model'=>NobitexFastCycleRunner::MODEL,
        'nobitex_universe'=>'all_executable_irt_usdt_spot_markets',
        'universe_awareness'=>'full_orderbook_scan_each_fast_cycle',
        'deep_analysis'=>'all_executable_markets_no_top_n_gate',
        'score_based_selection'=>false,
        'signal_source'=>'nobitex_internal_1m_5m_15m_plus_external_market_consensus',
        'market_data_sources'=>['bitpin','abantether','bit24','tabdeal'],
        'market_data_role'=>'read_only_intelligence',
        'tradingview_dependency'=>false,
        'analysis_interval_target_seconds'=>12,
        'quote_priority'=>['IRT','USDT'],
        'execution_mode'=>'nobitex_only',
        'execution_exchange'=>'nobitex',
        'update'=>$update,
        'backend_version'=>$versionAfterUpdate,
        'time_utc'=>gmdate(DATE_ATOM),
        'time_iran'=>IranClock::nowPayload(),
    ];

    $externalTradeReconciliation=['status'=>'skipped','reason'=>'nobitex_credentials_missing'];
    $positionReconciliation=['status'=>'skipped','reason'=>'nobitex_credentials_missing'];
    try{
        $hasNobitexCredentials=(bool)$pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='nobitex')")->fetchColumn();
        if($hasNobitexCredentials){
            $externalTradeReconciliation=(new NobitexExternalTradeReconciler())->reconcile($pdo);
            foreach(($externalTradeReconciliation['events']??[]) as $event){
                if(!is_array($event))continue;
                $quote=(string)($event['quote_asset']??'IRT');
                $unit=strtoupper($quote)==='IRT'?'تومان':strtoupper($quote);
                $exit=NobitexDisplayMoney::quoteValue((float)($event['exit_price']??0),$quote);
                $net=NobitexDisplayMoney::quoteValue((float)($event['net_pnl']??0),$quote);
                $body='فروش دستی واقعی در تاریخچه معاملات نوبیتکس شناسایی و با Trade تطبیق داده شد. '
                    .'بازار: '.(string)($event['symbol']??'').' | مقدار فروش: '.rtrim(rtrim(number_format((float)($event['sold_amount']??0),8,'.',''),'0'),'.')
                    .' | قیمت خروج: '.rtrim(rtrim(number_format($exit,8,'.',','),'0'),'.').' '.$unit
                    .' | سود/زیان خالص ثبت‌شده: '.number_format($net,2,'.',',').' '.$unit
                    .' | پوزیشن '.(($event['type']??'')==='closed'?'بسته شد':'کوچک شد').'.';
                (new BaleSystemAlert())->queue(
                    'nobitex-external-trade-'.(string)($event['trade_id']??'').'-'.(int)($event['position_id']??0),
                    'warning','فروش دستی نوبیتکس با پوزیشن ربات تطبیق داده شد',$body,
                    ['component'=>'external_trade_reconciliation','exchange'=>'nobitex','symbol'=>(string)($event['symbol']??''),'status'=>(string)($event['type']??'changed')],true,$pdo
                );
            }

            $positionReconciliation=(new NobitexPositionReconciler())->reconcile($pdo);
            foreach(($positionReconciliation['events']??[]) as $event){
                if(!is_array($event))continue;
                $type=(string)($event['type']??'changed');
                if($type==='fee_aligned')continue;
                $symbol=(string)($event['symbol']??'');
                $asset=(string)($event['asset']??'');
                $before=(float)($event['tracked_amount_before']??0);
                $remaining=(float)($event['remaining_amount']??0);
                $title=$type==='closed'?'کاهش موجودی خارجی / خروج پوزیشن شناسایی شد':'کاهش خارجی موجودی پوزیشن شناسایی شد';
                $body='موجودی واقعی نوبیتکس با پوزیشن داخلی همگام شد. دارایی: '.($asset!==''?$asset:$symbol)
                    .' | مقدار قبلی: '.rtrim(rtrim(number_format($before,8,'.',''),'0'),'.')
                    .' | مقدار باقی‌مانده: '.rtrim(rtrim(number_format($remaining,8,'.',''),'0'),'.')
                    .' | چون Fill دقیق معامله در این مسیر اثبات نشد، سود/زیان ساختگی ثبت نشد.';
                (new BaleSystemAlert())->queue(
                    'nobitex-external-position-'.$type.'-'.(int)($event['position_id']??0),'warning',$title,$body,
                    ['component'=>'position_reconciliation','exchange'=>'nobitex','symbol'=>$symbol,'status'=>$type],true,$pdo
                );
            }
        }
    }catch(Throwable $e){
        $positionReconciliation=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
        if(($externalTradeReconciliation['status']??'')==='skipped')$externalTradeReconciliation=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
        ErrorReporter::captureThrowable($e,'warning','nobitex_position_reconciliation',['exchange'=>'nobitex','run_id'=>$runId]);
    }

    // One cPanel cron invocation now contains several short market-decision cycles.
    // All execution still belongs exclusively to Nobitex; other exchanges remain
    // read-only intelligence sources consumed by the execution guard.
    $results=[];$enabledCount=0;$failedCount=0;
    if(!NobitexSchema::botEnabled('nobitex')){
        $results['nobitex']=['status'=>'disabled','exchange'=>'nobitex'];
    }else{
        $enabledCount++;
        try{
            $results['nobitex']=(new NobitexFastCycleRunner())->run($runId);
            if(strtolower((string)($results['nobitex']['status']??''))==='failed')$failedCount++;
        }catch(Throwable $e){
            $failedCount++;
            $results['nobitex']=['status'=>'failed','exchange'=>'nobitex','error'=>$e->getMessage()];
            ErrorReporter::captureThrowable($e,'error','cron_exchange',['exchange'=>'nobitex','status'=>'failed','run_id'=>$runId]);
        }
    }

    $dust=['status'=>'disabled'];
    try{$dust=(new NobitexDustConverter())->runIfDue($pdo);}
    catch(Throwable $e){$dust=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];ErrorReporter::captureThrowable($e,'error','dust_converter_cron',['exchange'=>'nobitex','run_id'=>$runId]);}

    $hostHealth=['status'=>'ok','alert_count'=>0];
    try{$hostHealth=(new HostHealthSentinel())->run();}
    catch(Throwable $e){$hostHealth=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];ErrorReporter::captureThrowable($e,'warning','host_health_sentinel',['run_id'=>$runId]);}

    // End-of-run delivery remains as a durable safety net in addition to the
    // per-fast-cycle synchronization performed by NobitexFastCycleRunner.
    $bale=['status'=>'disabled'];
    try{
        $notifier=new BaleTradeNotifier();
        $baleStatus=$notifier->status($pdo);
        if(($baleStatus['enabled']??false)&&($baleStatus['configured']??false)){
            $sync=$notifier->syncConfirmedTrades(100,$pdo);
            $delivery=$notifier->flushPending(25,$pdo);
            $systemDelivery=(new BaleSystemAlert())->flushPending(20,$pdo);
            $bale=['status'=>'ok','sync'=>$sync,'delivery'=>$delivery,'system_alerts'=>$systemDelivery];
        }
    }catch(Throwable $e){
        $bale=['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,500)];
        ErrorReporter::captureThrowable($e,'warning','bale_delivery',['status'=>'deferred','run_id'=>$runId]);
    }

    $overall=$failedCount===0?'success':(($enabledCount>$failedCount)?'partial':'failed');
    $summary=$baseSummary+[
        'external_trade_reconciliation'=>$externalTradeReconciliation,
        'position_reconciliation'=>$positionReconciliation,
        'exchanges'=>$results,
        'dust_conversion'=>$dust,
        'host_health_sentinel'=>$hostHealth,
        'bale_notifications'=>$bale,
        'kill_switch'=>(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0')==='1',
    ];
    $stmt=$pdo->prepare("UPDATE bot_runs SET status=:status,summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE run_id=:id");
    $stmt->execute([':status'=>$overall,':summary'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$runId]);
    $heartbeat['status']=$overall;$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['run_id']=$runId;$heartbeat['backend_version']=$versionAfterUpdate;$writeHeartbeat($heartbeat);

    $nobitexResult=is_array($results['nobitex']??null)?$results['nobitex']:[];
    try{(new NobitexDecisionReporter())->report($nobitexResult,$runId);}
    catch(Throwable $e){ErrorReporter::captureThrowable($e,'warning','nobitex_decision_reporter',['exchange'=>'nobitex','run_id'=>$runId]);}

    ErrorReporter::log(
        'چرخه Cron پایان یافت. وضعیت کل: '.$overall
        .' | Nobitex: '.(string)($nobitexResult['status']??'unknown')
        .' | چرخه‌های سریع: '.(int)($nobitexResult['fast_cycles_completed']??0)
        .(($nobitexResult['reason']??'')!==''?' | دلیل Nobitex: '.(string)$nobitexResult['reason']:''),
        'cron_cycle',
        [
            'run_id'=>$runId,'status'=>$overall,'exchange'=>'nobitex','execution_exchange'=>'nobitex',
            'market_data_sources'=>['bitpin','abantether','bit24','tabdeal'],
            'nobitex_status'=>$nobitexResult['status']??null,'nobitex_reason'=>$nobitexResult['reason']??null,
            'fast_cycles_completed'=>$nobitexResult['fast_cycles_completed']??null,
            'fast_cycle_interval_seconds'=>$nobitexResult['fast_cycle_interval_seconds']??null,
            'active_positions'=>$nobitexResult['active_positions']??null,'pending_orders'=>$nobitexResult['pending_orders']??null,
            'backend_version'=>$versionAfterUpdate,
        ],
        'info'
    );

    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($overall==='failed'?1:0);
}catch(Throwable $e){
    ErrorReporter::captureThrowable($e,'critical','cron_tick',['status'=>'failed_before_summary']);
    $heartbeat['status']='failed_before_summary';$heartbeat['finished_at']=gmdate(DATE_ATOM);$heartbeat['finished_at_iran']=IranClock::nowPayload();$heartbeat['error']=mb_substr($e->getMessage(),0,1000);$heartbeat['backend_version']=Updater::currentVersion();$writeHeartbeat($heartbeat);
    fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);
}
