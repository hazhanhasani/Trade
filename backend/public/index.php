<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Intelligence\MarketContextService;
use Trade\MarketData\MarketDataHub;
use Trade\Observability\ErrorReporter;
use Trade\Observability\SystemObservability;
use Trade\ReleaseContract;
use Trade\Security\AppAccess;
use Trade\Support\IranClock;
use Trade\Trading\BotController;
use Trade\Trading\NobitexAutoTraderEngine;
use Trade\Trading\NobitexDisplayMoney;
use Trade\Trading\NobitexDustConverter;
use Trade\Trading\NobitexEdgeCalibration;
use Trade\Trading\NobitexExecutionLearning;
use Trade\Trading\NobitexOrderService;
use Trade\Trading\NobitexPerformanceAnalytics;
use Trade\Trading\NobitexPortfolioSnapshotCache;
use Trade\Trading\NobitexRotationMonitor;
use Trade\Trading\NobitexSchema;
use Trade\Trading\NobitexStrategyLearning;
use Trade\Trading\NobitexTradeTimeline;
use Trade\Trading\TradeCommandCenter;
use Trade\Trading\TradeNotificationCenter;
use Trade\Trading\TradingViewSignalService;
use Trade\Trading\TradingViewWebhookException;
use Trade\Updater;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if(!Config::installed()){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'not_installed','install'=>'/install/']);exit;}
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');

function respond(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function jsonBody():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return[];$d=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new InvalidArgumentException('JSON object expected.');return$d;}
function boolValue(mixed $v,bool $default=false):bool{if($v===null)return$default;if(is_bool($v))return$v;return in_array(strtolower(trim((string)$v)),['1','true','yes','on'],true);}
function requireAppToken():void{$h=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer\s+(.+)$/i',$h,$m))respond(['ok'=>false,'error'=>'unauthorized'],401);$pdo=Database::connection();if(!AppAccess::validate($pdo,trim($m[1])))respond(['ok'=>false,'error'=>'unauthorized'],401);}
function executionExchange(?array $body=null):string{$raw=strtolower(trim((string)($body['exchange']??$_GET['exchange']??'nobitex')));if($raw!=='nobitex')throw new InvalidArgumentException('Nobitex is the only execution exchange.');return'nobitex';}
function credentialExists(string $exchange):bool{$s=Database::connection()->prepare('SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name=:e)');$s->execute([':e'=>$exchange]);return(bool)$s->fetchColumn();}
function globalPortfolioSnapshot():array{if(!credentialExists('nobitex'))return['status'=>'unavailable','reason'=>'credentials_missing'];try{return(new NobitexPortfolioSnapshotCache())->snapshot(Database::connection());}catch(Throwable $e){ErrorReporter::captureThrowable($e,'warning','global_portfolio');return['status'=>'deferred','reason'=>'valuation_failed','message'=>mb_substr($e->getMessage(),0,240)];}}

try{
    NobitexSchema::ensure();
    if($method==='POST'&&preg_match('#^/webhooks/tradingview/([A-Za-z0-9_-]{32,128})$#',$path,$m)){
        if(is_file(dirname(__DIR__).'/storage/maintenance.lock'))respond(['ok'=>false,'error'=>'maintenance'],503);$tv=new TradingViewSignalService();
        try{$payload=jsonBody();}catch(JsonException|InvalidArgumentException $e){respond(['ok'=>false,'error'=>'invalid_json'],400);}
        $result=$tv->ingest($m[1],$payload,TradingViewSignalService::requestSourceIp());http_response_code(202);echo json_encode(['ok'=>true,'accepted'=>$result['accepted'],'duplicate'=>$result['duplicate'],'event_id'=>$result['event_id'],'time_iran'=>IranClock::nowPayload()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();ignore_user_abort(true);if($tv->shouldInstantRecheck($result)){try{(new NobitexAutoTraderEngine())->run();}catch(Throwable $e){ErrorReporter::captureThrowable($e,'error','tradingview_instant_recheck');}}}exit;
    }
    if($method==='GET'&&$path==='/api/health'){$db=true;try{$pdo=Database::connection();$pdo->query('SELECT 1');AppAccess::bootstrapLegacy($pdo);}catch(Throwable $e){$db=false;ErrorReporter::captureThrowable($e,'critical','health_database');}$bot=$db?(new BotController())->status():null;$tv=$db?(new TradingViewSignalService())->publicStatus():null;respond(['ok'=>$db,'service'=>'Trade','execution_mode'=>'live_only','capital_asset'=>'IRT/USDT','quote_priority'=>['IRT','USDT'],'version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES,'app_url'=>(string)Config::get('app.url','https://rado-taxi.sbs'),'database'=>$db?'ok':'error','exchanges'=>$bot['exchanges']??[],'market_data_sources'=>$bot['market_data_sources']??[],'tradingview'=>$tv,'cron_health'=>$bot['cron_health']??null,'update_state'=>Updater::state(),'time_utc'=>gmdate(DATE_ATOM),'time_iran'=>IranClock::nowPayload()],$db?200:503);}
    if($method==='GET'&&$path==='/api/update'){try{$data=Updater::appUpdateInfo();$data['api_contract']=ReleaseContract::API_CONTRACT;$data['capabilities']=ReleaseContract::CAPABILITIES;$data['time_iran']=IranClock::nowPayload();respond(['ok'=>true,'data'=>$data]);}catch(Throwable $e){ErrorReporter::captureThrowable($e,'error','update_check');respond(['ok'=>false,'error'=>'update_check_failed','message'=>$e->getMessage(),'backend_version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES,'time_iran'=>IranClock::nowPayload()],503);}}
    if($method==='POST'&&$path==='/api/pair'){$body=jsonBody();$code=trim((string)($body['code']??''));if($code==='')throw new InvalidArgumentException('کد اتصال لازم است.');$paired=AppAccess::consumePairing(Database::connection(),$code);respond(['ok'=>true,'data'=>['token'=>$paired['token'],'token_id'=>$paired['token_id'],'label'=>$paired['label'],'server_url'=>(string)Config::get('app.url','https://rado-taxi.sbs'),'time_iran'=>IranClock::nowPayload()]]);}
    if(is_file(dirname(__DIR__).'/storage/maintenance.lock'))respond(['ok'=>false,'error'=>'maintenance','message'=>'Trade is updating. Try again shortly.','time_iran'=>IranClock::nowPayload()],503);
    requireAppToken();$controller=new BotController();
    if($method==='GET'&&$path==='/api/status'){$pdo=Database::connection();$status=$controller->status();$notifications=new TradeNotificationCenter();respond(['ok'=>true,'data'=>['mode'=>$status['exchanges']['nobitex']['live_execution_enabled']?'live':'live_disabled','execution_mode'=>'live_only','capital_asset'=>'IRT/USDT','quote_priority'=>['IRT','USDT'],'kill_switch'=>$status['kill_switch'],'credentials_configured'=>$status['exchanges']['nobitex']['credentials_configured'],'orders_logged'=>(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE exchange_name='nobitex'")->fetchColumn(),'active_app_tokens'=>AppAccess::activeCount($pdo),'backend_version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES,'update_state'=>Updater::state(),'last_run'=>$status['last_run'],'cron_health'=>$status['cron_health'],'tradingview'=>$status['tradingview']??(new TradingViewSignalService())->publicStatus(),'bot'=>$status,'exchanges'=>$status['exchanges'],'market_data_sources'=>$status['market_data_sources'],'global_portfolio'=>globalPortfolioSnapshot(),'notification_unread_count'=>$notifications->unreadCount($pdo),'time_iran'=>IranClock::nowPayload()]]);}
    if($method==='GET'&&$path==='/api/exchanges')respond(['ok'=>true,'data'=>$controller->status()['exchanges'],'time_iran'=>IranClock::nowPayload()]);
    if($method==='GET'&&$path==='/api/market-data'){$asset=strtoupper(trim((string)($_GET['asset']??'USDT')));$quote=strtoupper(trim((string)($_GET['quote']??'IRT')));if(!preg_match('/^[A-Z0-9]{2,20}$/',$asset)||!in_array($quote,['IRT','USDT'],true))throw new InvalidArgumentException('Invalid market-data asset or quote.');respond(['ok'=>true,'data'=>(new MarketDataHub())->snapshot($asset,$quote,boolValue($_GET['force']??false)),'time_iran'=>IranClock::nowPayload()]);}
    if($method==='GET'&&$path==='/api/tradingview')respond(['ok'=>true,'data'=>(new TradingViewSignalService())->publicStatus(),'time_iran'=>IranClock::nowPayload()]);
    if($method==='GET'&&$path==='/api/bot')respond(['ok'=>true,'data'=>$controller->status()]);
    if($method==='GET'&&$path==='/api/bot/recent')respond(['ok'=>true,'data'=>$controller->recentData(isset($_GET['limit'])?(int)$_GET['limit']:25)]);
    if($method==='GET'&&$path==='/api/bot/rotation')respond(['ok'=>true,'data'=>(new NobitexRotationMonitor())->snapshot(isset($_GET['limit'])?(int)$_GET['limit']:20)]);
    if($method==='GET'&&$path==='/api/bot/strategy-learning')respond(['ok'=>true,'data'=>(new NobitexStrategyLearning())->snapshot(null,isset($_GET['limit'])?(int)$_GET['limit']:240)]);
    if($method==='GET'&&$path==='/api/bot/execution-learning')respond(['ok'=>true,'data'=>(new NobitexExecutionLearning())->snapshot(null,isset($_GET['limit'])?(int)$_GET['limit']:240)]);
    if($method==='GET'&&$path==='/api/bot/edge-calibration')respond(['ok'=>true,'data'=>(new NobitexEdgeCalibration())->snapshot()]);
    if($method==='GET'&&$path==='/api/portfolio/global')respond(['ok'=>true,'data'=>globalPortfolioSnapshot()]);
    if($method==='GET'&&$path==='/api/analytics')respond(['ok'=>true,'data'=>(new NobitexPerformanceAnalytics())->snapshot(null,isset($_GET['days'])?(int)$_GET['days']:30)]);
    if($method==='GET'&&$path==='/api/timeline')respond(['ok'=>true,'data'=>(new NobitexTradeTimeline())->snapshot(null,isset($_GET['limit'])?(int)$_GET['limit']:80)]);
    if($method==='GET'&&$path==='/api/system/observability')respond(['ok'=>true,'data'=>(new SystemObservability())->snapshot()]);
    if($method==='GET'&&$path==='/api/market-context')respond(['ok'=>true,'data'=>(new MarketContextService())->snapshot(boolValue($_GET['force']??false))]);
    if($method==='GET'&&$path==='/api/dust-conversion')respond(['ok'=>true,'data'=>(new NobitexDustConverter())->status()]);
    if($method==='POST'&&$path==='/api/dust-conversion'){$b=jsonBody();respond(['ok'=>true,'data'=>(new NobitexDustConverter())->configure(boolValue($b['enabled']??false),(float)($b['min_toman']??1000),(float)($b['max_toman']??100000),(int)($b['cooldown_hours']??6))]);}

    $commandCenter=new TradeCommandCenter();
    if($method==='GET'&&$path==='/api/command-center')respond(['ok'=>true,'data'=>$commandCenter->snapshot()]);
    if($method==='GET'&&$path==='/api/settings/history')respond(['ok'=>true,'data'=>$commandCenter->history(null,isset($_GET['limit'])?(int)$_GET['limit']:30)]);
    if($method==='POST'&&$path==='/api/settings/preview')respond(['ok'=>true,'data'=>$commandCenter->previewSettings(jsonBody())]);
    if($method==='POST'&&$path==='/api/settings/preset'){$b=jsonBody();respond(['ok'=>true,'data'=>$commandCenter->applyPreset((string)($b['name']??''))]);}
    if($method==='POST'&&$path==='/api/settings/rollback'){$b=jsonBody();respond(['ok'=>true,'data'=>$commandCenter->rollback((int)($b['history_id']??0))]);}
    if($method==='POST'&&$path==='/api/emergency'){$b=jsonBody();respond(['ok'=>true,'data'=>$commandCenter->setEmergencyMode((string)($b['mode']??''))]);}
    if($method==='GET'&&$path==='/api/notification-rules')respond(['ok'=>true,'data'=>$commandCenter->notificationRules()]);
    if($method==='POST'&&$path==='/api/notification-rules')respond(['ok'=>true,'data'=>$commandCenter->configureNotificationRules(jsonBody())]);
    if($method==='POST'&&$path==='/api/shadow-mode'){$b=jsonBody();respond(['ok'=>true,'data'=>$commandCenter->setShadowMode(boolValue($b['enabled']??true,true))]);}
    if($method==='POST'&&$path==='/api/strategy-lab')respond(['ok'=>true,'data'=>$commandCenter->strategyLab(jsonBody())]);
    if($method==='GET'&&preg_match('#^/api/trade-replay/(\d+)$#',$path,$m))respond(['ok'=>true,'data'=>$commandCenter->tradeReplay((int)$m[1])]);
    if($method==='GET'&&$path==='/api/notifications'){$center=new TradeNotificationCenter();respond(['ok'=>true,'data'=>['unread_count'=>$center->unreadCount(),'items'=>$center->recent(isset($_GET['limit'])?(int)$_GET['limit']:50,boolValue($_GET['unread']??false)),'time_iran'=>IranClock::nowPayload()]]);}
    if($method==='POST'&&$path==='/api/notifications/read'){$body=jsonBody();$center=new TradeNotificationCenter();$all=boolValue($body['all']??false);$id=isset($body['id'])?(int)$body['id']:null;if(!$all&&($id===null||$id<=0))throw new InvalidArgumentException('id or all=true is required.');$center->markRead($all?null:$id);respond(['ok'=>true,'data'=>['unread_count'=>$center->unreadCount()]]);}
    if($method==='POST'&&$path==='/api/bot/settings'){$commandCenter->captureCurrentSettings('api','قبل از تغییر تنظیمات');$controller->updateSettings(jsonBody());$commandCenter->captureCurrentSettings('api','بعد از تغییر تنظیمات');respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&$path==='/api/bot/enabled'){$b=jsonBody();$controller->setExchangeEnabled('nobitex',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&$path==='/api/bot/live'){$b=jsonBody();$controller->setExchangeLive('nobitex',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/nobitex/bot$#',$path)){$b=jsonBody();$controller->setExchangeEnabled('nobitex',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()['exchanges']['nobitex']]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/nobitex/live$#',$path)){$b=jsonBody();$controller->setExchangeLive('nobitex',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()['exchanges']['nobitex']]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/nobitex/run$#',$path))respond(['ok'=>true,'data'=>$controller->runNow('nobitex')]);

    if($method==='GET'&&$path==='/api/markets'){executionExchange();$client=(new NobitexOrderService())->client();respond(['ok'=>true,'exchange'=>'nobitex','data'=>NobitexDisplayMoney::orderBooksResponse($client->allOrderBooks()),'unit_policy'=>['logical_irt'=>'TOMAN display','exchange_native'=>'RLS','rls_per_toman'=>10],'time_iran'=>IranClock::nowPayload()]);}
    if($method==='GET'&&$path==='/api/wallets'){executionExchange();respond(['ok'=>true,'exchange'=>'nobitex','data'=>NobitexDisplayMoney::walletResponse((new NobitexOrderService())->client()->wallets()),'time_iran'=>IranClock::nowPayload()]);}
    if($method==='GET'&&$path==='/api/orders'){executionExchange();$query=$_GET;unset($query['exchange']);respond(['ok'=>true,'exchange'=>'nobitex','data'=>NobitexDisplayMoney::ordersResponse((new NobitexOrderService())->client()->orders($query)),'time_iran'=>IranClock::nowPayload()]);}
    if($method==='POST'&&$path==='/api/orders'){$body=jsonBody();executionExchange($body);unset($body['exchange']);respond(['ok'=>true,'exchange'=>'nobitex','data'=>(new NobitexOrderService())->create($body,'android_or_api'),'time_iran'=>IranClock::nowPayload()],201);}
    if($method==='DELETE'&&preg_match('#^/api/orders/([^/]+)$#',$path,$m)){executionExchange();respond(['ok'=>true,'exchange'=>'nobitex','data'=>(new NobitexOrderService())->cancel($m[1]),'time_iran'=>IranClock::nowPayload()]);}
    if($method==='POST'&&$path==='/api/kill-switch'){$b=jsonBody();$enabled=boolValue($b['enabled']??true,true);$controller->setKillSwitch($enabled);respond(['ok'=>true,'kill_switch'=>$enabled,'time_iran'=>IranClock::nowPayload()]);}
    respond(['ok'=>false,'error'=>'not_found','time_iran'=>IranClock::nowPayload()],404);
}catch(TradingViewWebhookException $e){ErrorReporter::captureThrowable($e,'warning','api_tradingview');respond(['ok'=>false,'error'=>'tradingview_webhook','message'=>$e->getMessage(),'time_iran'=>IranClock::nowPayload()],$e->statusCode);}catch(InvalidArgumentException $e){respond(['ok'=>false,'error'=>'validation_error','message'=>$e->getMessage(),'time_iran'=>IranClock::nowPayload()],422);}catch(Throwable $e){ErrorReporter::captureThrowable($e,'error','api_request',['request_id'=>$_SERVER['HTTP_X_REQUEST_ID']??null]);respond(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage(),'time_iran'=>IranClock::nowPayload()],500);}
