<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Trading\BotController;
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

if (($_GET['ajax']??'')==='health') {
    header('Content-Type: application/json; charset=utf-8');
    $status=(new BotController())->status();
    $cron=is_array($status['cron_health']??null)?$status['cron_health']:[];
    $checks=[];
    foreach(['nobitex','bitpin'] as $exchange){
        $info=$status['exchanges'][$exchange];
        $ok=false;$text=$info['credentials_configured']?'در حال تست':'کلید تنظیم نشده';
        if($info['credentials_configured']){
            try{
                if($exchange==='nobitex') (new NobitexOrderService())->client()->test();
                else (new OrderService())->client()->wallets();
                $ok=true;$text='اتصال API سالم است';
            }catch(Throwable $e){$text=mb_substr($e->getMessage(),0,180);}
        }
        $checks[$exchange]=['ok'=>$ok,'text'=>$text];
    }
    echo json_encode(['ok'=>true,'checks'=>[
        'backend'=>['ok'=>true,'text'=>'Backend v'.Updater::currentVersion()],
        'database'=>['ok'=>true,'text'=>'MySQL متصل'],
        'nobitex'=>$checks['nobitex'],
        'bitpin'=>$checks['bitpin'],
        'cron'=>['ok'=>(bool)($cron['healthy']??false),'text'=>isset($cron['age_seconds'])?((int)$cron['age_seconds']).' ثانیه از آخرین اجرا':'هنوز اجرا نشده'],
        'https'=>['ok'=>!empty($_SERVER['HTTPS']),'text'=>!empty($_SERVER['HTTPS'])?'HTTPS فعال':'HTTPS تشخیص داده نشد'],
        'php'=>['ok'=>PHP_VERSION_ID>=80200,'text'=>'PHP '.PHP_VERSION],
        'sodium'=>['ok'=>function_exists('sodium_crypto_sign_detached'),'text'=>function_exists('sodium_crypto_sign_detached')?'امضای امن آماده':'PHP Sodium غیرفعال'],
        'devices'=>['ok'=>AppAccess::activeCount($pdo)>0,'text'=>AppAccess::activeCount($pdo).' دستگاه فعال'],
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$message='';$error='';$controller=new BotController();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/system.php?csrf_refresh=1',true,303);exit;
    }
    try{
        if((string)($_POST['action']??'')==='kill_switch'){
            $on=(string)($_POST['enabled']??'1')==='1';
            $controller->setKillSwitch($on);
            $message=$on?'توقف اضطراری سراسری فعال شد.':'توقف اضطراری برداشته شد.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$controller->status();
$kill=(bool)$status['kill_switch'];
$cron=is_array($status['cron_health']??null)?$status['cron_health']:[];
$version=Updater::currentVersion();
$csrf=h((string)$_SESSION['csrf']);
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سیستم — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.health-item{padding:12px;border:1px solid var(--line);border-radius:14px;background:#fafbfc;overflow:hidden}.health-item b{display:block;overflow-wrap:anywhere}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:6px;background:#a1a8b5}.dot.good{background:var(--green)}.dot.bad{background:var(--red)}@media(max-width:700px){.health-grid{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('system',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">کنترل و سلامت سیستم</div><h1>سیستم</h1><p>توقف اضطراری، سلامت اجرای خودکار، وضعیت سرور و ابزارهای فنی از اینجا کنترل می‌شوند.</p></div><span class="badge <?=$kill?'bad':'good'?>">توقف اضطراری <?=$kill?'روشن':'خاموش'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="panel-grid">
<section class="panel danger"><div class="panel-head"><div><h2>توقف اضطراری سراسری</h2><p>با روشن کردن این گزینه، ارسال سفارش جدید در همه صرافی‌ها متوقف می‌شود. برای مواقع خطا یا شرایط غیرعادی بازار استفاده کن.</p></div><span class="badge <?=$kill?'bad':'good'?>"><?=$kill?'فعال':'خاموش'?></span></div><div class="notice <?=$kill?'bad':'good'?>"><b><?=$kill?'سیستم در حالت توقف اضطراری است.':'سیستم در حالت عادی است.'?></b></div><form method="post" onsubmit="return confirm('وضعیت توقف اضطراری سراسری تغییر کند؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$kill?'0':'1'?>"><button class="btn <?=$kill?'safe':'danger'?>"><?=$kill?'برداشتن توقف اضطراری':'فعال کردن توقف اضطراری'?></button></form></section>

<section class="panel"><div class="panel-head"><div><h2>اجرای زمان‌بندی‌شده ربات</h2><p>Cron همان برنامه‌ای است که ربات را خودکار و دوره‌ای اجرا می‌کند. اگر مدت زیادی از آخرین اجرا گذشته باشد، این بخش هشدار می‌دهد.</p></div><span class="badge <?=($cron['healthy']??false)?'good':'bad'?>"><?=($cron['healthy']??false)?'سالم':'نیاز به بررسی'?></span></div><div class="metric-grid"><div class="metric"><span>وضعیت آخرین اجرا</span><b><?=h($cron['status']??'—')?></b></div><div class="metric"><span>چند ثانیه از آخرین اجرا گذشته</span><b><?=isset($cron['age_seconds'])?h($cron['age_seconds']).' ثانیه':'—'?></b></div><div class="metric"><span>نسخه Backend</span><b>v<?=h($version)?></b></div></div><div class="actions" style="margin-top:12px"><a class="btn secondary" href="/admin/cron-run.php">اجرای دستی و دیدن گزارش</a></div></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>بررسی سلامت سیستم</h2><p>اتصال Backend، دیتابیس، صرافی‌ها، HTTPS، PHP، امضای امن و دستگاه‌های متصل را یکجا تست می‌کند.</p></div><button class="btn secondary" type="button" onclick="loadHealth()">بررسی دوباره</button></div><div class="health-grid" id="health"><div class="health-item">در حال بررسی…</div></div></section>

<section class="panel"><div class="panel-head"><div><h2>ابزارهای فنی</h2><p>این ابزارها برای بروزرسانی، عیب‌یابی و ساخت نسخه اندروید هستند و در استفاده روزمره لازم نیست واردشان شوی.</p></div><span class="badge info">پیشرفته</span></div><div class="metric-grid"><div class="metric"><span>مرکز بروزرسانی</span><b>انتشار نسخه و آپدیت Backend</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/update/">باز کردن</a></div></div><div class="metric"><span>عیب‌یابی</span><b>تست و تعمیر وضعیت سیستم</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/repair.php">باز کردن</a></div></div><div class="metric"><span>امضای اندروید</span><b>بررسی امضای نسخه Release</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/signing.php">باز کردن</a></div></div></div></section>

<?php tradeAdminFooter($version); ?>
</div><script>function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}function card(name,x){return `<div class="health-item"><b><span class="dot ${x.ok?'good':'bad'}"></span>${name}</b><div class="muted" style="margin-top:5px">${esc(x.text)}</div></div>`;}async function loadHealth(){const el=document.getElementById('health');el.innerHTML='<div class="health-item">در حال بررسی…</div>';try{const r=await fetch('/admin/system.php?ajax=health',{cache:'no-store',credentials:'same-origin'});const j=await r.json(),c=j.checks;el.innerHTML=card('Backend',c.backend)+card('دیتابیس',c.database)+card('نوبیتکس',c.nobitex)+card('بیت‌پین',c.bitpin)+card('اجرای زمان‌بندی‌شده',c.cron)+card('HTTPS',c.https)+card('PHP',c.php)+card('امضای امن',c.sodium)+card('دستگاه‌ها',c.devices);}catch(e){el.innerHTML='<div class="health-item"><b class="bad-text">بررسی سلامت ناموفق بود</b></div>';}}loadHealth();</script></body></html>