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
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/advanced.php?csrf_refresh=1',true,303);exit;}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_order_safety'){
            $max=is_numeric($_POST['max_buy_orders_per_hour']??null)?(int)$_POST['max_buy_orders_per_hour']:30;
            $max=max(5,min(120,$max));
            writeSetting($pdo,'nobitex_max_buy_orders_per_hour',(string)$max);
            $message='سقف ایمنی تعداد خریدهای جدید ذخیره شد.';
        }elseif($action==='save_dust'){
            $enabled=(string)($_POST['dust_enabled']??'0')==='1';
            $min=is_numeric($_POST['dust_min_toman']??null)?(float)$_POST['dust_min_toman']:1000.0;
            $max=is_numeric($_POST['dust_max_toman']??null)?(float)$_POST['dust_max_toman']:100000.0;
            $hours=is_numeric($_POST['dust_cooldown_hours']??null)?(int)$_POST['dust_cooldown_hours']:6;
            $dustRunner->configure($enabled,$min,$max,$hours,$pdo);
            $message=$enabled?'تبدیل خودکار دارایی‌های خرد فعال شد.':'تبدیل خودکار دارایی‌های خرد خاموش شد.';
        }elseif($action==='run_dust'){
            $result=$dustRunner->run($pdo);
            $message='تبدیل دستی تمام شد؛ تبدیل‌شده: '.count($result['converted']??[]).'، ردشده: '.count($result['skipped']??[]).'، خطا: '.count($result['failed']??[]).'.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,900);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ هیچ تغییری انجام نشد.';

$maxBuy=max(5,min(120,(int)readSetting($pdo,'nobitex_max_buy_orders_per_hour','30')));
$dust=$dustRunner->status($pdo);$csrf=h((string)$_SESSION['csrf']);$version=Updater::currentVersion();$clock=IranClock::nowPayload();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات پیشرفته معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">TRADING / ADVANCED</div><h1>تنظیمات پیشرفته</h1><p>گزینه‌هایی که معمولاً لازم نیست تغییرشان بدهی. تنظیمات اصلی و قابل‌فهم در «تنظیمات معاملات» هستند.</p></div><span class="badge info"><?=h($clock['jalali_datetime']??'')?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>اسکن بازار</h2><p>ربات همه بازارهای Spot قابل‌اجرای IRT/USDT نوبیتکس را بررسی می‌کند. دیگر گزینه‌ای برای محدودکردن تحلیل به چند بازار اول وجود ندارد.</p></div><span class="badge good">همه بازارها</span></div><div class="simple-guide"><div><b>Order Book</b><span>همه بازارهای قابل اجرا در چرخه بررسی می‌شوند.</span></div><div><b>OHLC</b><span>تاریخچه طبق محدودیت رسمی API به‌صورت نوبتی تازه می‌شود.</span></div><div><b>رتبه‌بندی</b><span>فقط اولویت مصرف سرمایه را مشخص می‌کند، نه اینکه بازارهای دیگر تحلیل نشوند.</span></div></div></section>

<section class="panel soft"><div class="panel-head"><div><h2>ترمز اضطراری تعداد خرید</h2><p>اگر به‌خاطر باگ یا شرایط غیرعادی، ربات بخواهد تعداد زیادی BUY جدید در یک ساعت بفرستد، این سقف جلوی آن را می‌گیرد. فروش‌ها و Reprice همان سفارش جزو این شمارش نیستند.</p></div><span class="badge info">ایمنی</span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_order_safety"><div class="field-grid"><div class="field"><label>حداکثر خرید جدید در یک ساعت</label><input type="number" min="5" max="120" name="max_buy_orders_per_hour" value="<?=h($maxBuy)?>"><span class="field-help">پیش‌فرض ۳۰. این سقف معمولاً نباید دستکاری شود؛ تعداد پوزیشن و سرمایه درگیر از صفحه تنظیمات اصلی کنترل می‌شوند.</span></div></div><div class="actions" style="margin-top:12px"><button class="btn">ذخیره</button></div></form></section>

<section class="panel"><div class="panel-head"><div><h2>تبدیل دارایی‌های خرد به تومان</h2><p>فقط دارایی‌ای بررسی می‌شود که هیچ پوزیشن باز یا سفارش در انتظار برای آن وجود نداشته باشد. این بخش می‌تواند فروش واقعی ایجاد کند.</p></div><span class="badge <?=($dust['enabled']??false)?'good':'warn'?>"><?=($dust['enabled']??false)?'فعال':'خاموش'?></span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_dust"><div class="field-grid">
<div class="field"><label>تبدیل خودکار</label><select name="dust_enabled"><option value="0" <?=!($dust['enabled']??false)?'selected':''?>>خاموش</option><option value="1" <?=($dust['enabled']??false)?'selected':''?>>روشن</option></select><span class="field-help">فقط دارایی خارج از معامله فعال واجد شرایط است.</span></div>
<div class="field"><label>کمترین ارزش برای تبدیل — تومان</label><input type="number" min="100" max="500000" step="100" name="dust_min_toman" value="<?=h($dust['min_toman']??1000)?>"></div>
<div class="field"><label>بیشترین ارزش برای تبدیل — تومان</label><input type="number" min="1000" max="5000000" step="1000" name="dust_max_toman" value="<?=h($dust['max_toman']??100000)?>"></div>
<div class="field"><label>فاصله بررسی خودکار — ساعت</label><input type="number" min="1" max="168" name="dust_cooldown_hours" value="<?=h($dust['cooldown_hours']??6)?>"></div>
</div><div class="actions" style="margin-top:12px"><button class="btn">ذخیره تنظیمات</button></div></form>
<form method="post" style="margin-top:10px" onsubmit="return confirm('تبدیل دارایی‌های خرد واجد شرایط همین حالا اجرا شود؟ ممکن است سفارش فروش واقعی ایجاد شود.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="run_dust"><button class="btn warning">اجرای دستی تبدیل خرد</button></form></section>

<section class="panel"><div class="panel-head"><div><h2>دنبال تنظیم حجم و ریسک هستی؟</h2><p>حجم هر خرید، تعداد معاملات، حد کل سرمایه، حد ضرر و حد سود در صفحه ساده قرار دارند.</p></div><a class="btn safe" href="/admin/bot/settings.php">رفتن به تنظیمات معاملات</a></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>