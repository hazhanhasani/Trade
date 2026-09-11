<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Trading\TradeCommandCenter;
use Trade\Updater;

if(!Config::installed()){header('Location:/install/');exit;}
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control:no-store,no-cache,must-revalidate,max-age=0');
if(!isset($_SESSION['admin_id'])){header('Location:/admin/');exit;}

function replayH(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$id=max(0,(int)($_GET['id']??0));$data=null;$error='';
if($id>0){try{$data=(new TradeCommandCenter())->tradeReplay($id);}catch(Throwable $e){$error=$e->getMessage();}}
$version=Updater::currentVersion();
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade Replay</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell"><?php tradeAdminNav('command',$version);?>
<div class="page-head"><div><div class="page-eyebrow">TRADE REPLAY</div><h1>بازپخش معامله</h1><p>مسیر یک پوزیشن از ورود تا سیگنال‌ها و خروج را بدون ارسال سفارش بازسازی می‌کند.</p></div><a class="btn secondary" href="/admin/command-center.php">بازگشت به مرکز فرمان</a></div>
<section class="panel"><form method="get" class="field-grid"><label class="field">شناسه Position<input type="number" min="1" name="id" value="<?=$id>0?$id:''?>" required></label><button class="btn primary">نمایش Replay</button></form></section>
<?php if($error!==''):?><div class="notice bad"><?=replayH($error)?></div><?php endif?>
<?php if(is_array($data)):?><section class="panel"><div class="panel-head"><div><h2><?=replayH($data['position']['symbol']??'Position')?></h2><p>Position #<?=$id?> • <?=replayH($data['position']['status']??'')?></p></div></div><?php foreach((array)($data['steps']??[]) as$step):?><div class="metric" style="margin-bottom:8px"><span><?=replayH($step['time']??'')?></span><b><?=replayH($step['text']??$step['type']??'رویداد')?></b><?php if(isset($step['signal']['score'])):?><small>Score <?=replayH($step['signal']['score'])?> • <?=replayH($step['signal']['action']??'')?></small><?php endif?></div><?php endforeach?></section><?php elseif($id===0):?><div class="notice">شناسه یک پوزیشن را وارد کن. از کارت پوزیشن‌ها در مرکز فرمان هم می‌توانی مستقیم Replay را باز کنی.</div><?php endif?>
<?php tradeAdminFooter($version);?></div></body></html>
