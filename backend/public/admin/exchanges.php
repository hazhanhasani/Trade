<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\BotController;
use Trade\Trading\NobitexOrderService;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$controller=new BotController();$nobitex=new NobitexOrderService();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/exchanges.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $action=(string)($_POST['action']??'');$exchange=strtolower((string)($_POST['exchange']??''));
        if($action==='bot_toggle'){$enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeEnabled($exchange,$enabled);$message=ucfirst($exchange).' bot '.($enabled?'enabled.':'disabled.');}
        elseif($action==='live_toggle'){$enabled=(string)($_POST['enabled']??'0')==='1';$controller->setExchangeLive($exchange,$enabled);$message=ucfirst($exchange).' live execution '.($enabled?'enabled.':'disabled.');}
        elseif($action==='save_nobitex'){$nobitex->saveCredentials((string)($_POST['public_key']??''),(string)($_POST['private_key']??''));$message='کلید API نوبیتکس تست و به‌صورت رمزنگاری‌شده ذخیره شد.';}
        elseif($action==='test_nobitex'){$nobitex->client()->test();$message='اتصال نوبیتکس و Wallet API با موفقیت پاسخ داد.';}
        elseif($action==='delete_nobitex'){$nobitex->deleteCredentials();$message='کلیدهای نوبیتکس حذف و ربات و اجرای واقعی آن غیرفعال شدند.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود و تازه‌سازی شد؛ هیچ تغییری انجام نشد. دوباره دکمه موردنظر را بزن.';
$status=$controller->status();$ex=$status['exchanges'];$csrf=h((string)$_SESSION['csrf']);
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>صرافی‌ها — Trade</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1050px;margin:auto;padding:18px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid #e1e7f0;border-radius:20px;padding:18px;margin-top:14px;box-shadow:0 8px 28px #14213a0b}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.badge{display:inline-block;padding:7px 10px;border-radius:999px;background:#f3f6fb;margin:3px}.ok{color:#087857}.bad{color:#b42318}.muted{color:#68748a;font-size:13px}.btn{border:0;border-radius:11px;padding:11px 14px;background:#1769ff;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn.safe{background:#147a52}.btn.gray{background:#657187}.btn.danger{background:#c62828}.btn.warn{background:#a86500}input{width:100%;padding:12px;border:1px solid #ccd5e3;border-radius:10px;margin:6px 0 12px;direction:ltr}.msg,.err,.note{padding:12px;border-radius:12px;margin-top:12px}.msg{background:#ecfff4;color:#176548}.err{background:#fff0f0;color:#a11}.note{background:#eef5ff;color:#245183}.switchline{display:flex;justify-content:space-between;align-items:center;gap:10px;border-top:1px solid #edf0f5;padding:13px 0}.switchline:first-of-type{border-top:0}@media(max-width:760px){.wrap{padding:11px}.top{flex-direction:column;align-items:flex-start}.grid{grid-template-columns:1fr}.btn{width:100%;text-align:center}.row form{width:100%}}
</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin:0 0 5px">صرافی‌ها</h1><div class="muted">مدیریت مستقل Bitpin و Nobitex — GRAM / TON</div></div><div class="row"><a class="btn gray" href="/admin/">پنل اصلی</a><a class="btn gray" href="/admin/bot/">ربات‌ها</a></div></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif?><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?>
<div class="grid">
<div class="card"><h2>Bitpin</h2><div><span class="badge">API: <b class="<?=$ex['bitpin']['credentials_configured']?'ok':'bad'?>"><?=$ex['bitpin']['credentials_configured']?'تنظیم شده':'تنظیم نشده'?></b></span><span class="badge">Bot: <b><?=$ex['bitpin']['bot_enabled']?'ON':'OFF'?></b></span><span class="badge">Live: <b><?=$ex['bitpin']['live_execution_enabled']?'ON':'OFF'?></b></span></div>
<p class="muted">Bitpin در پروژه باقی می‌ماند، اما ربات آن مستقل از نوبیتکس قابل خاموش‌کردن است.</p>
<div class="switchline"><span>ربات خودکار Bitpin</span><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['bot_enabled']?'gray':'safe'?>"><?=$ex['bitpin']['bot_enabled']?'غیرفعال کردن':'فعال کردن'?></button></form></div>
<div class="switchline"><span>ارسال سفارش واقعی Bitpin</span><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="bitpin"><input type="hidden" name="enabled" value="<?=$ex['bitpin']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['bitpin']['live_execution_enabled']?'gray':'safe'?>"><?=$ex['bitpin']['live_execution_enabled']?'خاموش کردن Live':'روشن کردن Live'?></button></form></div>
<a class="btn warn" href="/admin/repair.php">تنظیم و عیب‌یابی Bitpin</a></div>

<div class="card"><h2>Nobitex</h2><div><span class="badge">API: <b class="<?=$ex['nobitex']['credentials_configured']?'ok':'bad'?>"><?=$ex['nobitex']['credentials_configured']?'تنظیم شده':'تنظیم نشده'?></b></span><span class="badge">Ed25519/Sodium: <b class="<?=$ex['nobitex']['sodium_available']?'ok':'bad'?>"><?=$ex['nobitex']['sodium_available']?'آماده':'غیرفعال'?></b></span><span class="badge">Bot: <b><?=$ex['nobitex']['bot_enabled']?'ON':'OFF'?></b></span><span class="badge">Live: <b><?=$ex['nobitex']['live_execution_enabled']?'ON':'OFF'?></b></span></div>
<p class="note">برای Trade فقط API Key با دسترسی‌های <b>READ + TRADE</b> بساز. دسترسی WITHDRAW لازم نیست و بهتر است فعال نباشد. Private Key فقط روی Backend رمزنگاری می‌شود و داخل اپ قرار نمی‌گیرد.</p>
<?php if(!$ex['nobitex']['sodium_available']):?><div class="err">برای امضای Ed25519 باید افزونه PHP Sodium روی هاست فعال باشد.</div><?php endif?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_nobitex"><label>Nobitex Public Key</label><input name="public_key" required autocomplete="off" spellcheck="false"><label>Nobitex Private Key</label><input type="password" name="private_key" required autocomplete="new-password" spellcheck="false"><button class="btn">تست و ذخیره امن کلیدها</button></form>
<?php if($ex['nobitex']['credentials_configured']):?><div class="row" style="margin-top:12px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_nobitex"><button class="btn gray">تست API نوبیتکس</button></form><form method="post" onsubmit="return confirm('کلید نوبیتکس حذف شود؟ ربات و Live آن نیز خاموش می‌شوند.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="delete_nobitex"><button class="btn danger">حذف کلیدها</button></form></div><?php endif?>
<div class="switchline"><span>ربات خودکار Nobitex</span><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['bot_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['bot_enabled']?'gray':'safe'?>"><?=$ex['nobitex']['bot_enabled']?'غیرفعال کردن':'فعال کردن'?></button></form></div>
<div class="switchline"><span>ارسال سفارش واقعی Nobitex</span><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=$ex['nobitex']['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$ex['nobitex']['live_execution_enabled']?'gray':'safe'?>"><?=$ex['nobitex']['live_execution_enabled']?'خاموش کردن Live':'روشن کردن Live'?></button></form></div>
</div></div>
<div class="card"><b>توقف اضطراری سراسری:</b> <?=$status['kill_switch']?'<span class="bad">فعال — هر دو صرافی متوقف‌اند</span>':'<span class="ok">خاموش</span>'?><p class="muted">خاموش/روشن کردن Bot هر صرافی مستقل است؛ Kill Switch سراسری روی هر دو اثر می‌گذارد.</p></div>
</div></body></html>
