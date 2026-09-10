<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Trading\TradeNotificationCenter;
use Trade\Updater;

if(!Config::installed()){header('Location:/install/');exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();header('Cache-Control:no-store');
if(!isset($_SESSION['admin_id'])){header('Location:/admin/');exit;}
if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$center=new TradeNotificationCenter();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location:/admin/notifications.php?csrf_refresh=1',true,303);exit;}
    try{$action=(string)($_POST['action']??'');if($action==='read_all'){$center->markRead();$message='همه اعلان‌ها خوانده شدند.';}elseif($action==='read_one'){$id=(int)($_POST['id']??0);if($id>0)$center->markRead($id);}}catch(Throwable $e){$error=mb_substr($e->getMessage(),0,600);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی تازه شد؛ هیچ تغییری انجام نشد.';
$unread=$center->unreadCount();$items=$center->recent(80,false);$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اعلان‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.feed{display:grid;gap:9px}.notification{display:grid;grid-template-columns:42px 1fr auto;gap:11px;align-items:start;padding:14px;border:1px solid var(--line);border-radius:17px;background:#fff}.notification.unread{background:linear-gradient(135deg,#fff,#f5f2ff);border-color:#ddd6ff}.icon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;font-weight:900}.icon.info{background:#edf4ff;color:#2563eb}.icon.success{background:#e8f7f1;color:#0b966d}.icon.warning{background:#fff6e7;color:#ad6908}.icon.critical{background:#fff0f0;color:#d04444}.notification h3{margin:0 0 5px;font-size:13px}.notification p{margin:0;color:var(--muted);font-size:11px;line-height:1.8}.notification small{display:block;color:var(--muted);margin-top:6px;font-size:9px}@media(max-width:650px){.notification{grid-template-columns:38px 1fr}.notification form{grid-column:2}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('system',$version);?>
<div class="page-head"><div><div class="page-eyebrow">EVENTS / NOTIFICATIONS</div><h1>مرکز اعلان‌ها</h1><p>رخدادهای مهم معاملات، اجرا، Rotation، Risk و خطاهای سیستم در یک Feed متمرکز.</p></div><span class="badge <?=$unread>0?'warn':'good'?>"><?=$unread?> خوانده‌نشده</span></div>
<div class="subnav"><a href="/admin/system.php">سیستم</a><a class="active" href="/admin/notifications.php">اعلان‌ها</a></div>
<?php if($message!==''):?><div class="notice good"><?=h($message)?></div><?php endif?><?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php endif?>
<div class="actions" style="margin-bottom:12px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="read_all"><button class="btn soft" <?=$unread===0?'disabled':''?>>خواندن همه</button></form></div>
<section class="panel"><div class="panel-head"><div><h2>Feed</h2><p>اعلان‌ها از Eventهای واقعی Backend ساخته می‌شوند و با event_key یکتا Deduplicate می‌شوند.</p></div><span class="badge info"><?=count($items)?> EVENT</span></div><div class="feed"><?php if($items===[]):?><div class="empty">هنوز اعلانی ثبت نشده است.</div><?php endif?><?php foreach($items as $n):$priority=(string)($n['priority']??'info');if(!in_array($priority,['info','success','warning','critical'],true))$priority='info';?><div class="notification <?=($n['unread']??false)?'unread':''?>"><div class="icon <?=$priority?>">!</div><div><h3><?=h($n['title']??'')?></h3><p><?=h($n['body']??'')?></p><small><?=h(($n['category']??''). ' • '.($n['created_at']??''))?></small></div><?php if($n['unread']??false):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="read_one"><input type="hidden" name="id" value="<?=h($n['id'])?>"><button class="btn soft">خواندم</button></form><?php endif?></div><?php endforeach?></div></section>
<?php tradeAdminFooter($version);?></div></body></html>