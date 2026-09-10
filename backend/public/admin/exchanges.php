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
            $enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeEnabled($exchange,$enabled);$message=ucfirst($exchange).' — ربات '.($enabled?'روشن شد.':'خاموش شد.');
        }elseif($action==='live_toggle'){
            $enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeLive($exchange,$enabled);$message=ucfirst($exchange).' — ارسال سفارش واقعی '.($enabled?'روشن شد.':'خاموش شد.');
        }elseif($action==='save_nobitex'){
            $nobitex->saveCredentials((string)($_POST['public_key']??''),(string)($_POST['private_key']??''));$message='کلید API نوبیتکس تست و به‌صورت رمزنگاری‌شده ذخیره شد.';
        }elseif($action==='test_nobitex'){
            $nobitex->client()->test();$message='اتصال نوبیتکس و دریافت موجودی با موفقیت انجام شد.';
        }elseif($action==='delete_nobitex'){
            $nobitex->deleteCredentials();$message='کلیدهای نوبیتکس حذف شدند و ربات و ارسال واقعی آن خاموش شد.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود و تازه‌سازی شد؛ هیچ تغییری انجام نشد.';

$status=$controller->status();$ex=$status['exchanges'];$csrf=h((string)$_SESSION['csrf']);$version=Updater::currentVersion();
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>صرافی‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>details.help-box{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 12px}details.help-box summary{cursor:pointer;font-weight:800;font-size:11px}details.help-box p{color:var(--muted);font-size:10px;line-height:1.9;margin:9px 0 0}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('exchanges',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">اتصال و اجازه معامله</div><h1>صرافی‌ها</h1><p>در این صفحه فقط اتصال API، روشن/خاموش بودن ربات و اجازه ارسال سفارش واقعی هر صرافی را مدیریت می‌کنی.</p></div><span class="badge <?=($status['kill_switch']??false)?'bad':'good'?>">توقف اضطراری <?=($status['kill_switch']??false)?'روشن':'خاموش'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>معنی سه وضعیت اصلی</h2><p>برای جلوگیری از سردرگمی، این سه مورد را جدا در نظر بگیر.</p></div><span class="badge info">راهنمای ساده</span></div><div class="simple-guide"><div><b>API</b><span>یعنی پنل می‌تواند حساب صرافی را بخواند و در صورت داشتن مجوز، سفارش بفرستد.</span></div><div><b>ربات</b><span>یعنی موتور تحلیل و تصمیم‌گیری خودکار آن صرافی فعال است.</span></div><div><b>ارسال واقعی</b><span>یعنی ربات اجازه دارد تصمیمش را به سفارش واقعی خرید یا فروش تبدیل کند.</span></div></div></section>

<div class="panel-grid">
<section class="panel"><div class="panel-head"><div><h2>Bitpin</h2><p>صرافی ثانویه؛ کنترل آن مستقل از نوبیتکس است.</p></div><span class="badge <?=$ex['bitpin']['credentials_configured']?'good':'bad'?>">API <?=$ex['bitpin']['credentials_configured']?'متصل':'قطع'?></span></div><div class="status-line"><span class="badge <?=$ex['bitpin']['bot_enabled']?'good':'bad'?>">ربات <?=$ex['bitpin']['bot_enabled']?'روشن':'خاموش'?></span><span class="badge <?=$ex['bitpin']['live_execution_enabled']?'good':'bad'?>">ارسال واقعی <?=$ex['bitpin']['live_execution_enabled']?'روشن':'خاموش'?></span></div><div class="panel" style="margin-top:12px;box-shadow:none;background:var(--surface2)"><div class="panel-head"><div><h2 style="font-size:14px">کنترل خودکار Bitpin</h2><p>روشن بودن ربات فقط تحلیل و تصمیم‌گیری را فعال می‌کند؛ ارسال واقعی باید جداگانه روشن باشد.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['bot_enabled']?'secondary':'safe'?>"><?=$ex['bitpin']['bot_enabled']?'خاموش کردن ربات':'روشن کردن ربات'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['live_execution_enabled']?'secondary':'safe'?>"><?=$ex['bitpin']['live_execution_enabled']?'خاموش کردن ارسال واقعی':'روشن کردن ارسال واقعی'?></button></form></div></div><div class="notice info">تنظیمات فنی و عیب‌یابی Bitpin در بخش «سیستم ← عیب‌یابی» قرار دارد.</div></section>

<section class="panel soft"><div class="panel-head"><div><h2>Nobitex</h2><p>صرافی اصلی برای معاملات واقعی پروژه.</p></div><span class="badge <?=$ex['nobitex']['credentials_configured']?'good':'bad'?>">API <?=$ex['nobitex']['credentials_configured']?'متصل':'قطع'?></span></div><div class="status-line"><span class="badge <?=$ex['nobitex']['sodium_available']?'good':'bad'?>">امضای امن <?=$ex['nobitex']['sodium_available']?'آماده':'غیرفعال'?></span><span class="badge <?=$ex['nobitex']['bot_enabled']?'good':'bad'?>">ربات <?=$ex['nobitex']['bot_enabled']?'روشن':'خاموش'?></span><span class="badge <?=$ex['nobitex']['live_execution_enabled']?'good':'bad'?>">ارسال واقعی <?=$ex['nobitex']['live_execution_enabled']?'روشن':'خاموش'?></span></div><div class="notice info">برای این پروژه فقط دسترسی <b>خواندن اطلاعات + معامله</b> لازم است. دسترسی برداشت وجه لازم نیست و نباید فعال شود. کلید خصوصی فقط روی Backend به‌صورت رمزنگاری‌شده ذخیره می‌شود.</div><?php if(!$ex['nobitex']['sodium_available']):?><div class="notice bad">افزونه PHP Sodium برای امضای امن درخواست‌های نوبیتکس باید فعال باشد.</div><?php endif?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_nobitex"><div class="field-grid"><div class="field"><label>کلید عمومی API نوبیتکس</label><input name="public_key" required autocomplete="off" spellcheck="false" style="direction:ltr"><span class="field-help">شناسه عمومی اتصال API که نوبیتکس در اختیارت قرار می‌دهد.</span></div><div class="field"><label>کلید خصوصی API نوبیتکس</label><input type="password" name="private_key" required autocomplete="new-password" spellcheck="false" style="direction:ltr"><span class="field-help">کلید محرمانه برای امضای درخواست‌ها؛ در پنل نمایش داده نمی‌شود و رمزنگاری‌شده ذخیره می‌شود.</span></div></div><div class="actions" style="margin-top:12px"><button class="btn">تست اتصال و ذخیره امن</button></div></form>
<?php if($ex['nobitex']['credentials_configured']):?><div class="actions" style="margin-top:10px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_nobitex"><button class="btn secondary">تست دوباره اتصال نوبیتکس</button></form><form method="post" onsubmit="return confirm('کلید نوبیتکس حذف شود؟ ربات و ارسال واقعی نیز خاموش می‌شوند.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="delete_nobitex"><button class="btn danger">حذف کلیدهای API</button></form></div><?php endif?>
<div class="panel" style="margin-top:12px;box-shadow:none;background:#fff"><div class="panel-head"><div><h2 style="font-size:14px">کنترل خودکار Nobitex</h2><p>ربات و اجازه ارسال سفارش واقعی مستقل از هم هستند تا کنترل بیشتری داشته باشی.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['bot_enabled']?'secondary':'safe'?>"><?=$ex['nobitex']['bot_enabled']?'خاموش کردن ربات':'روشن کردن ربات'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['live_execution_enabled']?'secondary':'safe'?>"><?=$ex['nobitex']['live_execution_enabled']?'خاموش کردن ارسال واقعی':'روشن کردن ارسال واقعی'?></button></form></div></div>
<details class="help-box"><summary>چه زمانی ربات واقعاً معامله می‌کند؟</summary><p>برای معامله خودکار واقعی نوبیتکس، چهار شرط باید هم‌زمان برقرار باشد: API متصل باشد، ربات روشن باشد، ارسال واقعی روشن باشد و توقف اضطراری خاموش باشد. علاوه بر این، خود موتور باید فرصت مناسب و مجاز از نظر ریسک پیدا کند.</p></details></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>هر تنظیم را از کجا پیدا کنم؟</h2><p>برای اینکه کنترل‌ها قاطی نشوند، هر گروه فقط در یک بخش قرار دارد.</p></div><span class="badge info">راهنما</span></div><div class="metric-grid"><div class="metric"><span>اتصال API / روشن کردن ربات / ارسال واقعی</span><b>صرافی‌ها</b></div><div class="metric"><span>حد ضرر / حد سود / اندازه خرید / سقف سرمایه</span><b>معاملات</b></div><div class="metric"><span>توقف اضطراری / سلامت Cron / ابزارهای فنی</span><b>سیستم</b></div></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>