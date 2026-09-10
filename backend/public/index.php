<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\ReleaseContract;
use Trade\Security\AppAccess;
use Trade\Trading\BotController;
use Trade\Trading\NobitexAutoTraderEngine;
use Trade\Trading\NobitexEdgeCalibration;
use Trade\Trading\NobitexExecutionLearning;
use Trade\Trading\NobitexOrderService;
use Trade\Trading\NobitexPerformanceAnalytics;
use Trade\Trading\NobitexPortfolioValuation;
use Trade\Trading\NobitexRotationMonitor;
use Trade\Trading\NobitexSchema;
use Trade\Trading\NobitexStrategyLearning;
use Trade\Trading\OrderService;
use Trade\Trading\TradeNotificationCenter;
use Trade\Trading\TradingViewSignalService;
use Trade\Trading\TradingViewWebhookException;
use Trade\Updater;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!Config::installed()) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>'not_installed','install'=>'/install/']); exit; }

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');

function respond(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function jsonBody():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return[];$d=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new InvalidArgumentException('JSON object expected.');return$d;}
function boolValue(mixed $v,bool $default=false):bool{if($v===null)return$default;if(is_bool($v))return$v;return in_array(strtolower(trim((string)$v)),['1','true','yes','on'],true);}
function requireAppToken():void{$h=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer\s+(.+)$/i',$h,$m))respond(['ok'=>false,'error'=>'unauthorized'],401);$pdo=Database::connection();if(!AppAccess::validate($pdo,trim($m[1])))respond(['ok'=>false,'error'=>'unauthorized'],401);}
function exchangeName(?array $body=null):string{$raw=strtolower(trim((string)($body['exchange']??$_GET['exchange']??'bitpin')));if(!in_array($raw,['bitpin','nobitex'],true))throw new InvalidArgumentException('exchange must be bitpin or nobitex.');return$raw;}
function credentialExists(string $exchange):bool{$s=Database::connection()->prepare('SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name=:e)');$s->execute([':e'=>$exchange]);return(bool)$s->fetchColumn();}
function globalPortfolioSnapshot():array{
    if(!credentialExists('nobitex'))return['status'=>'unavailable','reason'=>'credentials_missing'];
    try{
        $pdo=Database::connection();$service=new NobitexOrderService();$client=$service->client();$wallets=$client->wallets();
        $positions=$pdo->query("SELECT symbol,asset,quote_asset,amount,entry_price,mark_price,status FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();
        return['status'=>'ok']+(new NobitexPortfolioValuation())->snapshot($client,$wallets,$positions);
    }catch(Throwable $e){return['status'=>'deferred','reason'=>'valuation_failed','message'=>mb_substr($e->getMessage(),0,240)];}
}

try{
    NobitexSchema::ensure();

    if($method==='POST'&&preg_match('#^/webhooks/tradingview/([A-Za-z0-9_-]{32,128})$#',$path,$m)){
        if(is_file(dirname(__DIR__).'/storage/maintenance.lock'))respond(['ok'=>false,'error'=>'maintenance'],503);
        $tv=new TradingViewSignalService();
        try{$payload=jsonBody();}catch(JsonException|InvalidArgumentException $e){respond(['ok'=>false,'error'=>'invalid_json'],400);}
        $result=$tv->ingest($m[1],$payload,TradingViewSignalService::requestSourceIp());
        http_response_code(202);echo json_encode(['ok'=>true,'accepted'=>$result['accepted'],'duplicate'=>$result['duplicate'],'event_id'=>$result['event_id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();ignore_user_abort(true);if($tv->shouldInstantRecheck($result)){try{(new NobitexAutoTraderEngine())->run();}catch(Throwable $e){error_log('[Trade TradingView] instant recheck failed: '.$e->getMessage());}}}
        exit;
    }

    if($method==='GET'&&$path==='/api/health'){
        $db=true;try{$pdo=Database::connection();$pdo->query('SELECT 1');AppAccess::bootstrapLegacy($pdo);}catch(Throwable){$db=false;}
        $bot=$db?(new BotController())->status():null;$tv=$db?(new TradingViewSignalService())->publicStatus():null;
        respond(['ok'=>$db,'service'=>'Trade','execution_mode'=>'live_only','capital_asset'=>'IRT/USDT','quote_priority'=>['IRT','USDT'],'version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES,'app_url'=>(string)Config::get('app.url','https://rado-taxi.sbs'),'database'=>$db?'ok':'error','exchanges'=>$bot['exchanges']??[],'tradingview'=>$tv,'cron_health'=>$bot['cron_health']??null,'update_state'=>Updater::state(),'time_utc'=>gmdate(DATE_ATOM)],$db?200:503);
    }
    if($method==='GET'&&$path==='/api/update'){
        try{$data=Updater::appUpdateInfo();$data['api_contract']=ReleaseContract::API_CONTRACT;$data['capabilities']=ReleaseContract::CAPABILITIES;respond(['ok'=>true,'data'=>$data]);}catch(Throwable $e){respond(['ok'=>false,'error'=>'update_check_failed','message'=>$e->getMessage(),'backend_version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES],503);}
    }
    if($method==='POST'&&$path==='/api/pair'){
        $body=jsonBody();$code=trim((string)($body['code']??''));if($code==='')throw new InvalidArgumentException('کد اتصال لازم است.');$paired=AppAccess::consumePairing(Database::connection(),$code);respond(['ok'=>true,'data'=>['token'=>$paired['token'],'token_id'=>$paired['token_id'],'label'=>$paired['label'],'server_url'=>(string)Config::get('app.url','https://rado-taxi.sbs')]]);
    }
    if(is_file(dirname(__DIR__).'/storage/maintenance.lock'))respond(['ok'=>false,'error'=>'maintenance','message'=>'Trade is updating. Try again shortly.'],503);
    requireAppToken();

    $controller=new BotController();
    if($method==='GET'&&$path==='/api/status'){
        $pdo=Database::connection();$status=$controller->status();$notifications=new TradeNotificationCenter();
        respond(['ok'=>true,'data'=>[
            'mode'=>$status['exchanges']['nobitex']['live_execution_enabled']?'live':'live_disabled','execution_mode'=>'live_only','capital_asset'=>'IRT/USDT','quote_priority'=>['IRT','USDT'],'kill_switch'=>$status['kill_switch'],
            'credentials_configured'=>$status['exchanges']['nobitex']['credentials_configured'],'orders_logged'=>(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),'active_app_tokens'=>AppAccess::activeCount($pdo),
            'backend_version'=>Updater::currentVersion(),'api_contract'=>ReleaseContract::API_CONTRACT,'capabilities'=>ReleaseContract::CAPABILITIES,'update_state'=>Updater::state(),'last_run'=>$status['last_run'],'cron_health'=>$status['cron_health'],'tradingview'=>$status['tradingview']??(new TradingViewSignalService())->publicStatus(),'bot'=>$status,'exchanges'=>$status['exchanges'],
            'global_portfolio'=>globalPortfolioSnapshot(),'notification_unread_count'=>$notifications->unreadCount($pdo),
        ]]);
    }
    if($method==='GET'&&$path==='/api/exchanges')respond(['ok'=>true,'data'=>$controller->status()['exchanges']]);
    if($method==='GET'&&$path==='/api/tradingview')respond(['ok'=>true,'data'=>(new TradingViewSignalService())->publicStatus()]);
    if($method==='GET'&&$path==='/api/bot')respond(['ok'=>true,'data'=>$controller->status()]);
    if($method==='GET'&&$path==='/api/bot/recent')respond(['ok'=>true,'data'=>$controller->recentData(isset($_GET['limit'])?(int)$_GET['limit']:25)]);
    if($method==='GET'&&$path==='/api/bot/rotation')respond(['ok'=>true,'data'=>(new NobitexRotationMonitor())->snapshot(isset($_GET['limit'])?(int)$_GET['limit']:20)]);
    if($method==='GET'&&$path==='/api/bot/strategy-learning')respond(['ok'=>true,'data'=>(new NobitexStrategyLearning())->snapshot(null,isset($_GET['limit'])?(int)$_GET['limit']:240)]);
    if($method==='GET'&&$path==='/api/bot/execution-learning')respond(['ok'=>true,'data'=>(new NobitexExecutionLearning())->snapshot(null,isset($_GET['limit'])?(int)$_GET['limit']:240)]);
    if($method==='GET'&&$path==='/api/bot/edge-calibration')respond(['ok'=>true,'data'=>(new NobitexEdgeCalibration())->snapshot()]);
    if($method==='GET'&&$path==='/api/portfolio/global')respond(['ok'=>true,'data'=>globalPortfolioSnapshot()]);
    if($method==='GET'&&$path==='/api/analytics')respond(['ok'=>true,'data'=>(new NobitexPerformanceAnalytics())->snapshot(null,isset($_GET['days'])?(int)$_GET['days']:30)]);
    if($method==='GET'&&$path==='/api/notifications'){
        $center=new TradeNotificationCenter();$limit=isset($_GET['limit'])?(int)$_GET['limit']:50;$unread=boolValue($_GET['unread']??false);respond(['ok'=>true,'data'=>['unread_count'=>$center->unreadCount(),'items'=>$center->recent($limit,$unread)]]);
    }
    if($method==='POST'&&$path==='/api/notifications/read'){
        $body=jsonBody();$center=new TradeNotificationCenter();$all=boolValue($body['all']??false);$id=isset($body['id'])?(int)$body['id']:null;if(!$all&&($id===null||$id<=0))throw new InvalidArgumentException('id or all=true is required.');$center->markRead($all?null:$id);respond(['ok'=>true,'data'=>['unread_count'=>$center->unreadCount()]]);
    }
    if($method==='POST'&&$path==='/api/bot/settings'){$controller->updateSettings(jsonBody());respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&$path==='/api/bot/enabled'){$b=jsonBody();$controller->setExchangeEnabled('bitpin',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&$path==='/api/bot/live'){$b=jsonBody();$controller->setExchangeLive('bitpin',boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/(bitpin|nobitex)/bot$#',$path,$m)){$b=jsonBody();$controller->setExchangeEnabled($m[1],boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()['exchanges'][$m[1]]]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/(bitpin|nobitex)/live$#',$path,$m)){$b=jsonBody();$controller->setExchangeLive($m[1],boolValue($b['enabled']??null));respond(['ok'=>true,'data'=>$controller->status()['exchanges'][$m[1]]]);}
    if($method==='POST'&&preg_match('#^/api/exchanges/(bitpin|nobitex)/run$#',$path,$m))respond(['ok'=>true,'data'=>$controller->runNow($m[1])]);

    if($method==='GET'&&$path==='/api/markets'){
        $exchange=exchangeName();if($exchange==='nobitex'){$client=(new NobitexOrderService())->client();respond(['ok'=>true,'exchange'=>'nobitex','data'=>$client->allOrderBooks()]);}$service=new OrderService();$client=$service->client();$query=$_GET;unset($query['exchange']);respond(['ok'=>true,'exchange'=>'bitpin','data'=>$client->markets($query)]);
    }
    if($method==='GET'&&$path==='/api/wallets'){
        $exchange=exchangeName();if($exchange==='nobitex'){$client=(new NobitexOrderService())->client();respond(['ok'=>true,'exchange'=>'nobitex','data'=>$client->wallets()]);}$service=new OrderService();$client=$service->client();$query=$_GET;unset($query['exchange']);$data=$client->wallets($query);$service->syncTokens($client);respond(['ok'=>true,'exchange'=>'bitpin','data'=>$data]);
    }
    if($method==='GET'&&$path==='/api/orders'){
        $exchange=exchangeName();if($exchange==='nobitex'){$client=(new NobitexOrderService())->client();$query=$_GET;unset($query['exchange']);respond(['ok'=>true,'exchange'=>'nobitex','data'=>$client->orders($query)]);}$service=new OrderService();$client=$service->client();$query=$_GET;unset($query['exchange']);$data=$client->orders($query);$service->syncTokens($client);respond(['ok'=>true,'exchange'=>$exchange,'data'=>$data]);
    }
    if($method==='POST'&&$path==='/api/orders'){
        $body=jsonBody();$exchange=exchangeName($body);unset($body['exchange']);$data=$exchange==='nobitex'?(new NobitexOrderService())->create($body,'android_or_api'):(new OrderService())->create($body,'android_or_api');respond(['ok'=>true,'exchange'=>$exchange,'data'=>$data],201);
    }
    if($method==='DELETE'&&preg_match('#^/api/orders/([^/]+)$#',$path,$m)){$exchange=exchangeName();$data=$exchange==='nobitex'?(new NobitexOrderService())->cancel($m[1]):(new OrderService())->cancel($m[1]);respond(['ok'=>true,'exchange'=>$exchange,'data'=>$data]);}
    if($method==='POST'&&$path==='/api/kill-switch'){$b=jsonBody();$enabled=boolValue($b['enabled']??true,true);$controller->setKillSwitch($enabled);respond(['ok'=>true,'kill_switch'=>$enabled]);}
    respond(['ok'=>false,'error'=>'not_found'],404);
}catch(TradingViewWebhookException $e){respond(['ok'=>false,'error'=>'tradingview_webhook','message'=>$e->getMessage()],$e->statusCode);}catch(InvalidArgumentException $e){respond(['ok'=>false,'error'=>'validation_error','message'=>$e->getMessage()],422);}catch(Throwable $e){respond(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()],500);}
