<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Support\IranClock;
use Trade\Trading\NobitexDustConverter;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

$pdo=Database::connection();
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function readSetting(PDO $pdo,string $key,string $default):string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?$default:(string)$v;}
function writeSetting(PDO $pdo,string $key,string $value):void{$s=$pdo->prepare("INSERT INTO settings(key_name,value_text,updated_at) VALUES(:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}

$message='';$error='';$dustRunner=new NobitexDustConverter();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/settings.php?csrf_refresh=1',true,303);exit;}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_order_safety'){
            $max=is_numeric($_POST['max_buy_orders_per_hour']??null)?(int)$_POST['max_buy_orders_per_hour']:30;
            $max=max(5,min(120,$max));
            writeSetting($pdo,'nobitex_max_buy_orders_per_hour',(string)$max);
            $message='سقف ایمنی تعداد خریدهای نوبیتکس ذخیره شد.';
        }elseif($action==='save_dust'){
            $enabled=isset($_POST['dust_enabled'])&&(string)$_POST['dust_enabled']==='1';
            $min=is_numeric($_POST['dust_min_toman']??null)?(float)$_POST['dust_min_toman']:1000.0;
            $max=is_numeric($_POST['dust_max_toman']??null)?(float)$_POST['dust_max_toman']:100000.0;
            $hours=is_numeric($_POST['dust_cooldown_hours']??null)?(int)$_POST['dust_cooldown_hours']:6;
            $dustRunner->configure($enabled,$min,$max,$hours,$pdo);
            $message=$enabled?'تبدیل خودکار دارایی‌های خرد با محدودیت‌های ایمنی فعال شد.':'تبدیل خودکار دارایی‌های خرد خاموش شد.';
        }elseif($action==='run_dust'){
            $result=$dustRunner->run($pdo);
            $message='اجرای دستی تبدیل دارایی خرد: '.h((string)($result['status']??'unknown')).' — تبدیل‌شده: '.count($result['converted']??[]).'، ردشده: '.count($result['skipped']??[]).'، خطا: '.count($result['failed']??[]);
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,900);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ هیچ تغییری انجام نشد.';

$maxBuy=max(5,min(120,(int)readSetting($pdo,'nobitex_max_buy_orders_per_hour','30')));
$dust=$dustRunner->status($pdo);
$csrf=h((string)$_SESSION['csrf']);$version=Updater::currentVersion();$clock=IranClock::nowPayload();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات پیشرفته معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">TRADING / ADVANCED CONTROLS</div><h1>تنظیمات پیشرفته معاملات</h1><p>تنظیمات عملیاتی که برای مرتب ماندن صفحه اصلی معاملات جدا شده‌اند. تنظیمات اصلی ریسک همچنان در صفحه «معاملات» هستند.</p></div><span class="badge info"><?=h($clock['jalali_datetime']??'')?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=$message?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>تحلیل بازار نوبیتکس</h2><p>نسخه جدید دیگر «محدودیت تعداد بازار برای تحلیل» ندارد؛ همه بازارهای Spot قابل‌اجرای IRT/USDT با یک منطق یکسان تحلیل می‌شوند.</p></div><span class="badge good">FULL UNIVERSE</span></div>
<div class="simple-guide"><div><b>اسکن کامل</b><span>Order Book همه بازارهای قابل‌اجرا در هر چرخه بررسی می‌شود.</span></div><div><b>OHLC محدودشده توسط API</b><span>تاریخچه یک‌دقیقه‌ای با بودجه رسمی API به‌صورت Round‑Robin تازه می‌شود؛ بازار حذف نمی‌شود.</span></div><div><b>بدون Top‑N Gate</b><span>مرتب‌سازی فقط ترتیب مصرف سرمایه را تعیین می‌کند، نه اجازه تحلیل یا صلاحیت بازار.</span></div></div>
<div class="notice info">گزینه قدیمی <code>nobitex_scan_limit</code> عمداً دیگر به‌عنوان تنظیم نمایش داده نمی‌شود، چون موتور فعلی آن را نادیده می‌گیرد و محدودکردن آن خلاف Full‑Universe Engine است.</div></section>

<section class="panel soft"><div class="panel-head"><div><h2>سقف ایمنی خرید</h2><p>این محدودیت جلوی ارسال تعداد غیرعادی سفارش BUY در یک ساعت را می‌گیرد. Reprice همان سفارش و خروج‌های SELL در این شمارش قرار نمی‌گیرند.</p></div><span class="badge info">SAFETY</span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_order_safety"><div class="field-grid"><div class="field"><label>حداکثر خرید جدید در هر ساعت</label><input type="number" min="5" max="120" name="max_buy_orders_per_hour" value="<?=h($maxBuy)?>"><span class="field-help">پیش‌فرض ۳۰ است. این یک سقف اضطراری است و جایگزین کنترل تعداد پوزیشن، Pending، Exposure و Cooldown نیست.</span></div></div><div class="actions" style="margin-top:12px"><button class="btn">ذخیره سقف خرید</button></div></form></section>

<section class="panel"><div class="panel-head"><div><h2>تبدیل خودکار دارایی‌های خرد به تومان</h2><p>فقط دارایی‌ای فروخته می‌شود که هیچ پوزیشن باز یا سفارش Pending برای آن وجود نداشته باشد. هر اجرا حداکثر ۳ دارایی را پردازش می‌کند و Kill Switch/Live/Bot را رعایت می‌کند.</p></div><span class="badge <?=($dust['enabled']??false)?'good':'warn'?>"><?=($dust['enabled']??false)?'فعال':'خاموش'?></span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_dust"><div class="field-grid">
<div class="field"><label>وضعیت تبدیل خودکار</label><select name="dust_enabled"><option value="0" <?=!($dust['enabled']??false)?'selected':''?>>خاموش</option><option value="1" <?=($dust['enabled']??false)?'selected':''?>>فعال</option></select><span class="field-help">فعال‌سازی یعنی Cron در فاصله زمانی تعیین‌شده دارایی‌های خرد واجد شرایط را بررسی می‌کند.</span></div>
<div class="field"><label>حداقل ارزش دارایی خرد — تومان</label><input type="number" min="100" max="500000" step="100" name="dust_min_toman" value="<?=h($dust['min_toman']??1000)?>"></div>
<div class="field"><label>حداکثر ارزش دارایی خرد — تومان</label><input type="number" min="1000" max="5000000" step="1000" name="dust_max_toman" value="<?=h($dust['max_toman']??100000)?>"></div>
<div class="field"><label>فاصله بین بررسی‌ها — ساعت</label><input type="number" min="1" max="168" name="dust_cooldown_hours" value="<?=h($dust['cooldown_hours']??6)?>"><span class="field-help">آخرین اجرا: <?=h($dust['last_run_utc']??'هنوز اجرا نشده')?></span></div>
</div><div class="actions" style="margin-top:12px"><button class="btn">ذخیره تنظیمات تبدیل خرد</button></div></form>
<form method="post" style="margin-top:10px" onsubmit="return confirm('تبدیل دارایی‌های خرد واجد شرایط همین حالا اجرا شود؟ این عملیات می‌تواند سفارش فروش واقعی نوبیتکس ایجاد کند.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="run_dust"><button class="btn warning">اجرای دستی تبدیل خرد</button></form>
</section>

<section class="panel"><div class="panel-head"><div><h2>تنظیمات اصلی هنوز موجودند</h2><p>درصد هر ورود، سقف هر معامله، سقف Exposure کل، تعداد معاملات باز، Pending، Timeout، Cooldown، Stop Loss، Take Profit و حد زیان روزانه در صفحه اصلی معاملات باقی مانده‌اند.</p></div><a class="btn soft" href="/admin/bot/">باز کردن تنظیمات اصلی</a></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>