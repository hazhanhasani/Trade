<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Observability\SystemObservability;
use Trade\Security\AppAccess;
use Trade\Support\IranClock;
use Trade\Trading\BotController;
use Trade\Trading\NobitexPortfolioIntelligence;

if(!Config::installed()){http_response_code(503);exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!isset($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
try{
    $pdo=Database::connection();$status=(new BotController())->status();$intelligence=[];
    try{$intelligence=(new NobitexPortfolioIntelligence())->snapshot($pdo);}catch(Throwable){}
    [$start,$end]=IranClock::todayUtcRange();$s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE created_at>=:start AND created_at<:end');$s->execute([':start'=>$start,':end'=>$end]);
    $obs=(new SystemObservability())->snapshot();
    echo json_encode(['ok'=>true,'data'=>[
        'status'=>$status,
        'intelligence'=>$intelligence,
        'order_count_today'=>(int)$s->fetchColumn(),
        'device_count'=>AppAccess::activeCount($pdo),
        'host'=>['errors_last_60m'=>$obs['errors_last_60m']??[],'cron'=>$obs['cron']??[],'database'=>$obs['database']??[]],
        'time_iran'=>IranClock::nowPayload(),
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'live_dashboard_failed']);}
