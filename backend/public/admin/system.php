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
                $ok=true;$text='API پاسخ سالم داد';
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
        'sodium'=>['ok'=>function_exists('sodium_crypto_sign_detached'),'text'=>function_exists('sodium_crypto_sign_detached')?'Ed25519 آماده':'PHP Sodium غیرفعال'],
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
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سیستم — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.health-item{padding:12px;border:1px solid var(--line);border-radius:14px;background:#fafbfc}.health-item b{display:block}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:6px;background:#a1a8b5}.dot.good{background:var(--green)}.dot.bad{background:var(--red)}@media(max-width:700px){.health-grid{grid-template-columns:1fr 1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('system',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">SYSTEM / OPERATIONS</div><h1>سیستم</h1><p>کنترل‌های سراسری، سلامت Backend، Cron و ابزارهای نگهداری فقط در این بخش قرار می‌گیرند.</p></div><span class="badge <?=$kill?'bad':'good'?>">KILL SWITCH <?=$kill?'ON':'OFF'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="panel-grid">
<section class="panel danger"><div class="panel-head"><div><h2>توقف اضطراری سراسری</h2><p>Kill Switch ارسال سفارش جدید را در همه صرافی‌ها متوقف می‌کند.</p></div><span class="badge <?=$kill?'bad':'good'?>"><?=$kill?'ACTIVE':'OFF'?></span></div><div class="notice <?=$kill?'bad':'good'?>"><b><?=$kill?'سیستم در حالت توقف اضطراری است.':'سیستم در حالت عادی است.'?></b></div><form method="post" onsubmit="return confirm('وضعیت Kill Switch سراسری تغییر کند؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$kill?'0':'1'?>"><button class="btn <?=$kill?'safe':'danger'?>"><?=$kill?'برداشتن توقف اضطراری':'فعال‌کردن توقف اضطراری'?></button></form></section>

<section class="panel"><div class="panel-head"><div><h2>Cron Engine</h2><p>ساختار Cron فعلی تغییر نکرده؛ اینجا فقط وضعیت و دسترسی به ابزار اجرای دستی نمایش داده می‌شود.</p></div><span class="badge <?=($cron['healthy']??false)?'good':'bad'?>"><?=($cron['healthy']??false)?'HEALTHY':'CHECK'?></span></div><div class="metric-grid"><div class="metric"><span>Status</span><b><?=h($cron['status']??'—')?></b></div><div class="metric"><span>Age</span><b><?=isset($cron['age_seconds'])?h($cron['age_seconds']).'s':'—'?></b></div><div class="metric"><span>Backend</span><b>v<?=h($version)?></b></div></div><div class="actions" style="margin-top:12px"><a class="btn secondary" href="/admin/cron-run.php">اجرای دستی / گزارش Cron</a></div></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>System Health</h2><p>تست Backend، Database، APIها، HTTPS، PHP، Sodium و اتصال دستگاه‌ها.</p></div><button class="btn secondary" type="button" onclick="loadHealth()">بررسی دوباره</button></div><div class="health-grid" id="health"><div class="health-item">در حال بررسی…</div></div></section>

<section class="panel"><div class="panel-head"><div><h2>ابزارهای نگهداری</h2><p>ابزارهای فنی از منوی اصلی حذف شده‌اند و به‌صورت زیرمجموعه سیستم سازمان‌دهی شده‌اند.</p></div><span class="badge info">ADVANCED</span></div><div class="metric-grid"><div class="metric"><span>Update Center</span><b>انتشار و بروزرسانی</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/update/">باز کردن</a></div></div><div class="metric"><span>Diagnostics</span><b>Repair / Health</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/repair.php">باز کردن</a></div></div><div class="metric"><span>Android Signing</span><b>Release Signing</b><div class="actions" style="margin-top:8px"><a class="btn soft" href="/admin/signing.php">باز کردن</a></div></div></div></section>

<?php tradeAdminFooter($version); ?>
</div><script>function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}function card(name,x){return `<div class="health-item"><b><span class="dot ${x.ok?'good':'bad'}"></span>${name}</b><div class="muted" style="margin-top:5px">${esc(x.text)}</div></div>`;}async function loadHealth(){const el=document.getElementById('health');el.innerHTML='<div class="health-item">در حال بررسی…</div>';try{const r=await fetch('/admin/system.php?ajax=health',{cache:'no-store',credentials:'same-origin'});const j=await r.json(),c=j.checks;el.innerHTML=card('Backend',c.backend)+card('Database',c.database)+card('Nobitex',c.nobitex)+card('Bitpin',c.bitpin)+card('Cron',c.cron)+card('HTTPS',c.https)+card('PHP',c.php)+card('Sodium',c.sodium)+card('Devices',c.devices);}catch(e){el.innerHTML='<div class="health-item"><b class="bad-text">Health check failed</b></div>';}}loadHealth();</script></body></html>