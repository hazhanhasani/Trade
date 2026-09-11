<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Observability\ErrorReporter;
use Trade\Observability\SystemObservability;
use Trade\Security\AppAccess;
use Trade\Support\IranClock;
use Trade\Trading\BotController;
use Trade\Trading\NobitexDustConverter;
use Trade\Trading\NobitexOrderService;
use Trade\Trading\OrderService;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

$pdo=Database::connection();
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

if (($_GET['ajax']??'')==='live') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'data'=>(new SystemObservability())->snapshot()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
if (($_GET['ajax']??'')==='health') {
    header('Content-Type: application/json; charset=utf-8');
    $status=(new BotController())->status();$cron=is_array($status['cron_health']??null)?$status['cron_health']:[];$checks=[];
    foreach(['nobitex','bitpin'] as $exchange){
        $info=$status['exchanges'][$exchange];$ok=false;$text='کلید تنظیم نشده';
        $configured=(bool)($info['credentials_configured']??false);$enabled=(bool)($info['bot_enabled']??false);
        if(!$configured){
            $text='کلید تنظیم نشده';
        }elseif(!$enabled){
            // A disabled exchange must not create authenticated traffic just because
            // the System page refreshes. Manual/authentication tests remain in Repair.
            $ok=true;$text='ربات غیرفعال است — تست خودکار API انجام نشد؛ برای تست واقعی از «مرکز تعمیر» استفاده کن.';
        }else{
            $text='در حال تست';
            try{if($exchange==='nobitex')(new NobitexOrderService())->client()->test();else(new OrderService())->client()->wallets();$ok=true;$text='اتصال API سالم است';}
            catch(Throwable $e){$text=mb_substr($e->getMessage(),0,180);ErrorReporter::captureThrowable($e,'warning','exchange_health_check',['exchange'=>$exchange,'health_probe'=>'automatic_enabled_exchange']);}
        }
        $checks[$exchange]=['ok'=>$ok,'text'=>$text,'tested'=>$configured&&$enabled,'bot_enabled'=>$enabled];
    }
    $obs=(new SystemObservability())->snapshot();
    echo json_encode(['ok'=>true,'checks'=>[
        'backend'=>['ok'=>true,'text'=>'Backend v'.Updater::currentVersion()],
        'database'=>['ok'=>(bool)($obs['database']['ok']??false),'text'=>($obs['database']['ok']??false)?'MySQL • '.($obs['database']['latency_ms']??'—').' ms':'خطای دیتابیس'],
        'nobitex'=>$checks['nobitex'],'bitpin'=>$checks['bitpin'],
        'cron'=>['ok'=>(bool)($cron['healthy']??false),'text'=>isset($cron['age_seconds'])?((int)$cron['age_seconds']).' ثانیه از آخرین اجرا':'هنوز اجرا نشده'],
        'https'=>['ok'=>(bool)($obs['host']['https']??false),'text'=>($obs['host']['https']??false)?'HTTPS فعال':'HTTPS تشخیص داده نشد'],
        'php'=>['ok'=>PHP_VERSION_ID>=80200,'text'=>'PHP '.PHP_VERSION],
        'sodium'=>['ok'=>function_exists('sodium_crypto_sign_detached'),'text'=>function_exists('sodium_crypto_sign_detached')?'امضای امن آماده':'PHP Sodium غیرفعال'],
        'storage'=>['ok'=>(bool)($obs['disk']['storage_writable']??false),'text'=>($obs['disk']['storage_writable']??false)?'Storage قابل نوشتن است':'Storage قابل نوشتن نیست'],
        'devices'=>['ok'=>AppAccess::activeCount($pdo)>0,'text'=>AppAccess::activeCount($pdo).' دستگاه فعال'],
    ],'time_iran'=>IranClock::nowPayload()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

$message='';$error='';$controller=new BotController();$dustService=new NobitexDustConverter();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/system.php?csrf_refresh=1',true,303);exit;}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='kill_switch'){$on=(string)($_POST['enabled']??'1')==='1';$controller->setKillSwitch($on);$message=$on?'توقف اضطراری سراسری فعال شد.':'توقف اضطراری برداشته شد.';}
        elseif($action==='dust_settings'){
            $enabled=(string)($_POST['enabled']??'0')==='1';$min=(float)($_POST['min_toman']??1000);$max=(float)($_POST['max_toman']??100000);$hours=(int)($_POST['cooldown_hours']??6);
            $dustService->configure($enabled,$min,$max,$hours,$pdo);$message=$enabled?'تبدیل دارایی خرد فعال شد؛ فقط دارایی خارج از معامله بررسی می‌شود.':'تبدیل دارایی خرد خاموش شد.';
        } elseif($action==='dust_run'){$result=$dustService->run($pdo);$message='بررسی دارایی خرد انجام شد: '.count($result['converted']??[]).' تبدیل.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);ErrorReporter::captureThrowable($e,'error','admin_system_action');}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$controller->status();$kill=(bool)$status['kill_switch'];$cron=is_array($status['cron_health']??null)?$status['cron_health']:[];$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);$obs=(new SystemObservability())->snapshot();$dust=$dustService->status($pdo);
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سیستم — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.health-item{padding:12px;border:1px solid var(--line);border-radius:14px;background:var(--surface2);overflow:hidden}.health-item b{display:block;overflow-wrap:anywhere}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:6px;background:#a1a8b5}.dot.good{background:var(--green)}.dot.bad{background:var(--red)}@media(max-width:700px){.health-grid{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('system',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">کنترل، هاست و سلامت سیستم</div><h1>سیستم</h1><p>سلامت هاست، Cron، دیتابیس، خطاها، توقف اضطراری و اتوماسیون دارایی خرد در یک بخش.</p></div><span class="badge <?=$kill?'bad':'good'?>">توقف اضطراری <?=$kill?'روشن':'خاموش'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="stat-grid">
<div class="stat-card"><span>فضای دیسک مصرف‌شده</span><b id="diskPct"><?=h($obs['disk']['used_percent']??'—')?>%</b><small id="diskFree">—</small></div>
<div class="stat-card"><span>حافظه PHP</span><b id="memoryUse">—</b><small><?=h($obs['php']['memory_limit']??'—')?> سقف PHP</small></div>
<div class="stat-card"><span>Load یک دقیقه</span><b id="load1"><?=h($obs['host']['load_1m']??'—')?></b><small id="hostUptime">—</small></div>
<div class="stat-card"><span>تاخیر دیتابیس</span><b id="dbLatency"><?=h($obs['database']['latency_ms']??'—')?> ms</b><small><?=($obs['database']['ok']??false)?'متصل':'خطا'?></small></div>
<div class="stat-card"><span>خطاهای ۶۰ دقیقه</span><b class="bad-text" id="errorCount"><?=h($obs['errors_last_60m']['total']??0)?></b><small><a href="/admin/logs.php">مشاهده لاگ زنده</a></small></div>
<div class="stat-card"><span>هشدار بله در صف</span><b id="baleQueue"><?=h($obs['bale_system_alerts']['pending']??0)?></b><small>ارسال مجدد خودکار</small></div>
<div class="stat-card"><span>Cron</span><b id="cronLive" class="<?=($obs['cron']['healthy']??false)?'ok':'bad-text'?>"><?=h($obs['cron']['status']??'—')?></b><small id="cronAge"><?=h($obs['cron']['age_seconds']??'—')?> ثانیه</small></div>
<div class="stat-card"><span>زمان ایران</span><b style="font-size:14px" id="serverIranTime"><?=h($obs['time_iran']['jalali_datetime']??'—')?></b><small>تقویم شمسی / Asia/Tehran</small></div>
</div>

<div class="panel-grid">
<section class="panel danger"><div class="panel-head"><div><h2>توقف اضطراری سراسری</h2><p>روشن کردن این گزینه ورود خرید جدید را متوقف می‌کند. خروج‌های کاهش ریسک همچنان می‌توانند انجام شوند.</p></div><span class="badge <?=$kill?'bad':'good'?>"><?=$kill?'فعال':'خاموش'?></span></div><div class="notice <?=$kill?'bad':'good'?>"><b><?=$kill?'سیستم در حالت توقف اضطراری است.':'سیستم در حالت عادی است.'?></b></div><form method="post" onsubmit="return confirm('وضعیت توقف اضطراری سراسری تغییر کند؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$kill?'0':'1'?>"><button class="btn <?=$kill?'safe':'danger'?>"><?=$kill?'برداشتن توقف اضطراری':'فعال کردن توقف اضطراری'?></button></form></section>
<section class="panel"><div class="panel-head"><div><h2>اجرای زمان‌بندی‌شده ربات</h2><p>وضعیت Cron به‌صورت زنده پایش می‌شود؛ اگر بیش از سه دقیقه عقب بیفتد هشدار می‌گیرد.</p></div><span class="badge <?=($cron['healthy']??false)?'good':'bad'?>"><?=($cron['healthy']??false)?'سالم':'نیاز به بررسی'?></span></div><div class="metric-grid"><div class="metric"><span>وضعیت</span><b><?=h($cron['status']??'—')?></b></div><div class="metric"><span>سن آخرین اجرا</span><b><?=isset($cron['age_seconds'])?h($cron['age_seconds']).' ثانیه':'—'?></b></div><div class="metric"><span>Backend</span><b>v<?=h($version)?></b></div></div><div class="actions" style="margin-top:12px"><a class="btn secondary" href="/admin/cron-run.php">اجرای دستی و گزارش</a></div></section>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>تبدیل خودکار دارایی‌های خرد به تومان</h2><p>فقط دارایی‌هایی بررسی می‌شوند که هیچ پوزیشن باز یا سفارش در انتظار ندارند. حداکثر ۳ دارایی در هر نوبت؛ قیمت فروش Market با محدودکننده قیمت محافظت می‌شود.</p></div><span class="badge <?=$dust['enabled']?'good':'warn'?>"><?=$dust['enabled']?'فعال':'خاموش'?></span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="dust_settings"><div class="field-grid"><div class="field"><label>وضعیت</label><select name="enabled"><option value="0" <?=$dust['enabled']?'':'selected'?>>خاموش</option><option value="1" <?=$dust['enabled']?'selected':''?>>فعال</option></select><span class="field-help">به‌صورت پیش‌فرض خاموش است تا حد مالی را خودت انتخاب کنی.</span></div><div class="field"><label>حداقل ارزش دارایی خرد (تومان)</label><input type="number" min="100" step="100" name="min_toman" value="<?=h($dust['min_toman'])?>"></div><div class="field"><label>حداکثر ارزش دارایی خرد (تومان)</label><input type="number" min="1000" step="1000" name="max_toman" value="<?=h($dust['max_toman'])?>"></div><div class="field"><label>فاصله اجرای خودکار (ساعت)</label><input type="number" min="1" max="168" name="cooldown_hours" value="<?=h($dust['cooldown_hours'])?>"></div></div><div class="actions" style="margin-top:12px"><button class="btn safe">ذخیره تنظیمات</button></div></form><?php if($dust['enabled']):?><form method="post" style="margin-top:8px" onsubmit="return confirm('همین حالا دارایی‌های خرد واجد شرایط بررسی و در صورت امکان فروخته شوند؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="dust_run"><button class="btn warning">اجرای دستی تبدیل خرد</button></form><?php endif?></section>

<section class="panel"><div class="panel-head"><div><h2>بررسی سلامت اتصال‌ها</h2><p>تست واقعی Backend، دیتابیس و API صرافی‌های فعال. صرافی غیرفعال خودکار به API وصل نمی‌شود؛ تست دستی از مرکز تعمیر انجام می‌شود.</p></div><button class="btn secondary" type="button" onclick="loadHealth()">بررسی دوباره</button></div><div class="health-grid" id="health"><div class="health-item">در حال بررسی…</div></div></section>

<section class="panel"><div class="panel-head"><div><h2>ابزارهای فنی</h2><p>عیب‌یابی، لاگ حساس، دستگاه‌ها، بروزرسانی و امضای اندروید.</p></div><span class="badge info">پیشرفته</span></div><div class="metric-grid"><div class="metric"><span>لاگ و خطا</span><b>پایش زنده و هشدار بله</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/logs.php">باز کردن</a></div></div><div class="metric"><span>دستگاه‌ها</span><b>توکن‌ها و اتصال Android</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/devices.php">باز کردن</a></div></div><div class="metric"><span>بروزرسانی</span><b>نسخه Backend و Release</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/update/">باز کردن</a></div></div><div class="metric"><span>عیب‌یابی</span><b>تست و تعمیر سیستم</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/repair.php">باز کردن</a></div></div><div class="metric"><span>امضای اندروید</span><b>هویت نسخه Release</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/signing.php">باز کردن</a></div></div></div></section>

<?php tradeAdminFooter($version); ?>
</div><script>
function esc(s){return TradeUI.escapeHtml(s)}function card(name,x){return `<div class="health-item"><b><span class="dot ${x.ok?'good':'bad'}"></span>${esc(name)}</b><div class="muted" style="margin-top:5px">${esc(x.text)}</div></div>`;}
async function loadHealth(){const el=document.getElementById('health');el.innerHTML='<div class="health-item">در حال بررسی…</div>';try{const j=await TradeUI.json('/admin/system.php?ajax=health'),c=j.checks;el.innerHTML=card('Backend',c.backend)+card('دیتابیس',c.database)+card('نوبیتکس',c.nobitex)+card('بیت‌پین',c.bitpin)+card('Cron',c.cron)+card('HTTPS',c.https)+card('PHP',c.php)+card('امضای امن',c.sodium)+card('Storage',c.storage)+card('دستگاه‌ها',c.devices);}catch(e){el.innerHTML='<div class="health-item"><b class="bad-text">بررسی سلامت ناموفق بود</b></div>';}}
function live(d){TradeUI.setText('#diskPct',(d.disk?.used_percent??'—')+'%');TradeUI.setText('#diskFree','آزاد: '+TradeUI.formatBytes(d.disk?.free_bytes));TradeUI.setText('#memoryUse',TradeUI.formatBytes(d.php?.memory_usage_bytes));TradeUI.setText('#load1',d.host?.load_1m??'—');TradeUI.setText('#hostUptime',d.host?.uptime_seconds==null?'Uptime نامشخص':'Uptime '+TradeUI.formatNumber(d.host.uptime_seconds/3600,1)+' ساعت');TradeUI.setText('#dbLatency',(d.database?.latency_ms??'—')+' ms');TradeUI.setText('#errorCount',d.errors_last_60m?.total??0);TradeUI.setText('#baleQueue',d.bale_system_alerts?.pending??0);TradeUI.setText('#cronLive',d.cron?.status??'—');TradeUI.setText('#cronAge',(d.cron?.age_seconds??'—')+' ثانیه');TradeUI.setText('#serverIranTime',TradeUI.iranFromPayload(d.time_iran));}
window.addEventListener('DOMContentLoaded',()=>{loadHealth();TradeUI.poll(async()=>{const j=await TradeUI.json('/admin/system.php?ajax=live');live(j.data);},5000);setInterval(loadHealth,60000);});
</script></body></html>
