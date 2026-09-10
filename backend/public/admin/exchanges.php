<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\BotController;
use Trade\Trading\NobitexOrderService;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$controller=new BotController();
$nobitex=new NobitexOrderService();
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/exchanges.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $action=(string)($_POST['action']??'');$exchange=strtolower((string)($_POST['exchange']??''));
        if($action==='bot_toggle'){
            $enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeEnabled($exchange,$enabled);$message=ucfirst($exchange).' bot '.($enabled?'enabled.':'disabled.');
        }elseif($action==='live_toggle'){
            $enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeLive($exchange,$enabled);$message=ucfirst($exchange).' live execution '.($enabled?'enabled.':'disabled.');
        }elseif($action==='save_nobitex'){
            $nobitex->saveCredentials((string)($_POST['public_key']??''),(string)($_POST['private_key']??''));$message='کلید API نوبیتکس تست و به‌صورت رمزنگاری‌شده ذخیره شد.';
        }elseif($action==='test_nobitex'){
            $nobitex->client()->test();$message='اتصال نوبیتکس و Wallet API با موفقیت پاسخ داد.';
        }elseif($action==='delete_nobitex'){
            $nobitex->deleteCredentials();$message='کلیدهای نوبیتکس حذف و ربات و اجرای واقعی آن غیرفعال شدند.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود و تازه‌سازی شد؛ هیچ تغییری انجام نشد.';

$status=$controller->status();$ex=$status['exchanges'];$csrf=h((string)$_SESSION['csrf']);$version=Updater::currentVersion();
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>صرافی‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('exchanges',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">EXCHANGES / CONNECTIONS</div><h1>صرافی‌ها</h1><p>کلیدهای API و فعال/غیرفعال کردن Bot و Live هر صرافی فقط در این بخش مدیریت می‌شوند.</p></div><span class="badge <?=($status['kill_switch']??false)?'bad':'good'?>">GLOBAL STOP <?=($status['kill_switch']??false)?'ON':'OFF'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="panel-grid">
<section class="panel"><div class="panel-head"><div><h2>Bitpin</h2><p>Gateway ثانویه؛ کنترل مستقل Bot و Live.</p></div><span class="badge <?=$ex['bitpin']['credentials_configured']?'good':'bad'?>">API <?=$ex['bitpin']['credentials_configured']?'READY':'OFF'?></span></div><div class="status-line"><span class="badge <?=$ex['bitpin']['bot_enabled']?'good':'bad'?>">BOT <?=$ex['bitpin']['bot_enabled']?'ON':'OFF'?></span><span class="badge <?=$ex['bitpin']['live_execution_enabled']?'good':'bad'?>">LIVE <?=$ex['bitpin']['live_execution_enabled']?'ON':'OFF'?></span></div><div class="panel" style="margin-top:12px;box-shadow:none;background:var(--surface2)"><div class="panel-head"><div><h2 style="font-size:14px">اجرای خودکار Bitpin</h2><p>فقط موتور همین صرافی را روشن یا خاموش می‌کند.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['bot_enabled']?'secondary':'safe'?>"><?=$ex['bitpin']['bot_enabled']?'خاموش کردن Bot':'روشن کردن Bot'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['live_execution_enabled']?'secondary':'safe'?>"><?=$ex['bitpin']['live_execution_enabled']?'خاموش کردن Live':'روشن کردن Live'?></button></form></div></div><div class="notice info">تنظیم و عیب‌یابی پیشرفته Bitpin از بخش System → Diagnostics انجام می‌شود.</div></section>

<section class="panel soft"><div class="panel-head"><div><h2>Nobitex</h2><p>Gateway اصلی معاملات واقعی با Ed25519.</p></div><span class="badge <?=$ex['nobitex']['credentials_configured']?'good':'bad'?>">API <?=$ex['nobitex']['credentials_configured']?'READY':'OFF'?></span></div><div class="status-line"><span class="badge <?=$ex['nobitex']['sodium_available']?'good':'bad'?>">Ed25519 <?=$ex['nobitex']['sodium_available']?'READY':'OFF'?></span><span class="badge <?=$ex['nobitex']['bot_enabled']?'good':'bad'?>">BOT <?=$ex['nobitex']['bot_enabled']?'ON':'OFF'?></span><span class="badge <?=$ex['nobitex']['live_execution_enabled']?'good':'bad'?>">LIVE <?=$ex['nobitex']['live_execution_enabled']?'ON':'OFF'?></span></div><div class="notice info">برای Trade فقط دسترسی‌های <b>READ + TRADE</b> لازم است. WITHDRAW لازم نیست. Private Key فقط روی Backend رمزنگاری می‌شود.</div><?php if(!$ex['nobitex']['sodium_available']):?><div class="notice bad">افزونه PHP Sodium برای امضای Ed25519 باید فعال باشد.</div><?php endif?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_nobitex"><div class="field-grid"><div class="field"><label>Nobitex Public Key</label><input name="public_key" required autocomplete="off" spellcheck="false" style="direction:ltr"></div><div class="field"><label>Nobitex Private Key</label><input type="password" name="private_key" required autocomplete="new-password" spellcheck="false" style="direction:ltr"></div></div><div class="actions" style="margin-top:12px"><button class="btn">تست و ذخیره امن کلیدها</button></div></form>
<?php if($ex['nobitex']['credentials_configured']):?><div class="actions" style="margin-top:10px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_nobitex"><button class="btn secondary">تست API نوبیتکس</button></form><form method="post" onsubmit="return confirm('کلید نوبیتکس حذف شود؟ Bot و Live نیز خاموش می‌شوند.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="delete_nobitex"><button class="btn danger">حذف کلیدها</button></form></div><?php endif?>
<div class="panel" style="margin-top:12px;box-shadow:none;background:#fff"><div class="panel-head"><div><h2 style="font-size:14px">اجرای خودکار Nobitex</h2><p>Bot و مجوز ارسال سفارش واقعی را مستقل کنترل می‌کند.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['bot_enabled']?'secondary':'safe'?>"><?=$ex['nobitex']['bot_enabled']?'خاموش کردن Bot':'روشن کردن Bot'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['live_execution_enabled']?'secondary':'safe'?>"><?=$ex['nobitex']['live_execution_enabled']?'خاموش کردن Live':'روشن کردن Live'?></button></form></div></div></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>مرزبندی تنظیمات</h2><p>برای جلوگیری از تداخل، هر کنترل فقط یک مالک دارد.</p></div><span class="badge info">STRUCTURE</span></div><div class="metric-grid"><div class="metric"><span>API / Bot / Live</span><b>صرافی‌ها</b></div><div class="metric"><span>Risk / Position sizing</span><b>معاملات</b></div><div class="metric"><span>Global Kill Switch / Cron</span><b>سیستم</b></div></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>