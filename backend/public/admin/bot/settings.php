<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Support\IranClock;
use Trade\Trading\BotController;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function f(mixed $v,int $d=2):string{return number_format((float)$v,$d,'.',',');}

$c=new BotController();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/settings.php?csrf_refresh=1',true,303);exit;}
    try{
        if((string)($_POST['action']??'')==='save_settings'){
            $c->updateSettings($_POST);
            $message='تنظیمات معاملات ذخیره شد. درصد واقعی خرید بعدی دوباره بر اساس محدودیت‌های فعلی محاسبه شد.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,900);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ هیچ تغییری انجام نشد.';

$status=$c->status();$s=$status['settings'];$cap=$status['exchanges']['nobitex']['portfolio_capacity']??[];$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);$clock=IranClock::nowPayload();
$effective=(float)($s['nobitex_effective_position_percent']??$cap['effective_position_percent']??0);
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.setting-group{border:1px solid var(--line);border-radius:18px;background:var(--surface);padding:15px;margin-top:12px}.setting-group>h2{margin:0 0 4px;font-size:16px}.setting-group>p{margin:0 0 13px;color:var(--muted);font-size:11px;line-height:1.9}.effect{display:block;margin-top:6px;padding:7px 8px;border-radius:9px;background:var(--surface2);font-size:9.5px;line-height:1.8;color:var(--muted)}.effect strong{color:var(--ink)}.important-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.important-card{padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--surface2)}.important-card b{display:block;font-size:13px}.important-card strong{display:block;color:var(--primary);font-size:20px;margin:6px 0}.important-card span{font-size:9.5px;color:var(--muted);line-height:1.8}.advanced-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}@media(max-width:760px){.important-grid{grid-template-columns:1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">TRADING / EVERYDAY SETTINGS</div><h1>تنظیمات معاملات</h1><p>فقط تنظیماتی که در استفاده روزمره لازم داری. هر گزینه می‌گوید چه کاری می‌کند و زیاد یا کم کردنش چه اثری دارد.</p></div><span class="badge info"><?=h($clock['jalali_datetime']??'')?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel soft"><div class="panel-head"><div><h2>سه عدد مهم</h2><p>اگر فقط می‌خواهی ربات را ساده مدیریت کنی، بیشتر از همه این سه مورد اهمیت دارند.</p></div><span class="badge good">ساده</span></div><div class="important-grid">
<div class="important-card"><b>سرمایه هدف هر خرید</b><strong><?=f($s['position_percent']??0,1)?>٪</strong><span>ربات برای هر فرصت جدید از این درصد شروع می‌کند؛ Learning و کنترل ریسک می‌توانند آن را کمتر کنند.</span></div>
<div class="important-card"><b>تعداد معامله هم‌زمان</b><strong><?=h($s['nobitex_max_positions']??0)?></strong><span>وقتی این تعداد پوزیشن پر شود، خرید عادی جدید متوقف می‌شود.</span></div>
<div class="important-card"><b>سقف کل سرمایه درگیر</b><strong><?=f($s['nobitex_portfolio_exposure_percent']??0,0)?>٪</strong><span>مجموع ارزش همه پوزیشن‌های باز نوبیتکس نباید از این درصد پورتفو بیشتر شود.</span></div>
</div><div class="notice info" style="margin-bottom:0"><b>خرید بعدی الان:</b> حداکثر حدود <?=f($effective,2)?>٪ از پورتفو؛ این عدد خودکار از تنظیمات بالا و محدودیت‌های هوشمند محاسبه می‌شود.</div></section>

<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_settings">
<section class="setting-group"><h2>۱) انتخاب بازار و سطح ریسک</h2><p>مشخص می‌کند ربات سرمایه را در چه نوع بازاری اولویت دهد و رفتار کلی مدیریت ریسک چقدر محافظه‌کار باشد.</p><div class="field-grid">
<div class="field"><label>بازار اصلی</label><select name="quote_asset"><option value="IRT" <?=($s['quote_asset']??'IRT')==='IRT'?'selected':''?>>تومان — IRT</option><option value="USDT" <?=($s['quote_asset']??'')==='USDT'?'selected':''?>>تتر — USDT</option></select><span class="effect"><strong>اثر:</strong> تومان یعنی بازارهای IRT اولویت دارند؛ تتر یعنی بازارهای USDT. موتور همچنان هر دو گروه قابل‌اجرا را می‌شناسد.</span></div>
<div class="field"><label>سطح ریسک کلی</label><select name="risk_profile"><option value="safe" <?=($s['risk_profile']??'')==='safe'?'selected':''?>>کم‌ریسک</option><option value="balanced" <?=($s['risk_profile']??'balanced')==='balanced'?'selected':''?>>متعادل</option><option value="aggressive" <?=($s['risk_profile']??'')==='aggressive'?'selected':''?>>پرریسک</option></select><span class="effect"><strong>اثر:</strong> کم‌ریسک محافظه‌کارتر است. «متعادل» برای استفاده عادی مناسب‌تر است. پرریسک آزادی بیشتری به حجم/ریسک می‌دهد.</span></div>
</div></section>

<section class="setting-group"><h2>۲) سرمایه هر خرید و ظرفیت پورتفو</h2><p>این بخش تعیین می‌کند هر خرید چقدر باشد و مجموع معاملات چقدر از کل سرمایه را درگیر کنند.</p><div class="field-grid">
<div class="field"><label>سرمایه هدف هر خرید — درصد</label><input type="number" step="0.1" min="0.5" max="20" name="position_percent" value="<?=h($s['position_percent'])?>"><span class="effect"><strong>مثال:</strong> اگر ۳٪ باشد، ربات از حدود ۳٪ ارزش پورتفو برای محاسبه خرید شروع می‌کند. Learning ممکن است آن را کمتر کند.</span></div>
<div class="field"><label>سقف مطلق یک معامله — درصد</label><input type="number" step="0.1" min="1" max="25" name="max_position_percent" value="<?=h($s['max_position_percent'])?>"><span class="effect"><strong>اثر:</strong> حتی اگر محاسبات دیگر عدد بیشتری بخواهند، یک پوزیشن جدید از این سقف عبور نمی‌کند.</span></div>
<div class="field"><label>سقف کل سرمایه درگیر — درصد</label><input type="number" step="1" min="10" max="90" name="nobitex_portfolio_exposure_percent" value="<?=h($s['nobitex_portfolio_exposure_percent'])?>"><span class="effect"><strong>اثر:</strong> کم‌کردن این عدد، پول نقد بیشتری بیرون از معاملات نگه می‌دارد. مثلاً ۶۰٪ یعنی حداقل حدود ۴۰٪ پورتفو آزاد می‌ماند.</span></div>
<div class="field"><label>حداکثر معاملات باز هم‌زمان</label><input type="number" min="1" max="20" name="nobitex_max_positions" value="<?=h($s['nobitex_max_positions'])?>"><span class="effect"><strong>اثر:</strong> عدد کمتر یعنی تمرکز بیشتر و پوزیشن‌های کمتر؛ عدد بیشتر یعنی تقسیم سرمایه بین فرصت‌های بیشتری.</span></div>
</div></section>

<section class="setting-group"><h2>۳) حفاظت از سرمایه و خروج</h2><p>حدهای اصلی برای جلوگیری از بزرگ‌شدن زیان و تعیین هدف پایه سود.</p><div class="field-grid">
<div class="field"><label>حد ضرر هر معامله — درصد</label><input type="number" step="0.1" min="0.5" max="15" name="stop_loss_percent" value="<?=h($s['stop_loss_percent'])?>"><span class="effect"><strong>اثر:</strong> عدد کمتر، خروج زیان را سریع‌تر می‌کند. این حد در کنار Adaptive Exit و سیگنال برگشتی کار می‌کند.</span></div>
<div class="field"><label>هدف سود پایه — درصد</label><input type="number" step="0.1" min="0.5" max="50" name="take_profit_percent" value="<?=h($s['take_profit_percent'])?>"><span class="effect"><strong>اثر:</strong> هدف پایه است؛ Trailing Profit و قفل سود ممکن است براساس بازار خروج متفاوتی انتخاب کنند.</span></div>
<div class="field"><label>حداکثر زیان روزانه — درصد</label><input type="number" step="0.1" min="1" max="15" name="daily_loss_limit_percent" value="<?=h($s['daily_loss_limit_percent'])?>"><span class="effect"><strong>اثر:</strong> اگر زیان روز به این حد برسد، BUY جدید متوقف می‌شود؛ SELL برای خروج و حفاظت همچنان مجاز است.</span></div>
<div class="field"><label>استراحت همان ارز بعد از معامله — دقیقه</label><input type="number" min="1" max="1440" name="cooldown_minutes" value="<?=h($s['cooldown_minutes'])?>"><span class="effect"><strong>اثر:</strong> جلوی ورود دوباره و سریع روی همان ارز را می‌گیرد و معامله بیش‌ازحد را کاهش می‌دهد.</span></div>
</div></section>

<section class="setting-group"><h2>۴) سفارش‌های در انتظار</h2><p>این دو گزینه مربوط به حالتی هستند که سفارش به صرافی رسیده ولی هنوز کامل Fill نشده است.</p><div class="field-grid">
<div class="field"><label>حداکثر سفارش در انتظار</label><input type="number" min="1" max="5" name="nobitex_max_pending_orders" value="<?=h($s['nobitex_max_pending_orders'])?>"><span class="effect"><strong>اثر:</strong> سقف کمتر، ربات را از پخش‌کردن چند سفارش نیمه‌کاره هم‌زمان بازمی‌دارد.</span></div>
<div class="field"><label>حداکثر زمان انتظار — ثانیه</label><input type="number" min="30" max="300" step="5" name="nobitex_pending_timeout_seconds" value="<?=h($s['nobitex_pending_timeout_seconds'])?>"><span class="effect"><strong>اثر:</strong> بعد از این زمان، Watchdog وضعیت سفارش را دوباره بررسی می‌کند و در صورت نیاز Cancel/Reprice ایمن انجام می‌دهد.</span></div>
<div class="field"><label>درصد واقعی خرید بعدی</label><input type="text" value="<?=h(f($effective,2))?>٪ — خودکار" disabled><span class="effect"><strong>قابل ویرایش نیست:</strong> نتیجه‌ی سرمایه هدف، سقف کل پورتفو، تعداد پوزیشن‌ها و Learning است.</span></div>
</div></section>

<div class="actions" style="margin-top:14px"><button class="btn safe">ذخیره تنظیمات معاملات</button><a class="btn secondary" href="/admin/bot/">برگشت به معاملات</a></div>
</form>

<section class="panel" style="margin-top:16px"><div class="advanced-row"><div><h2 style="margin:0 0 5px">تنظیمات فنی و کم‌استفاده</h2><div class="muted">سقف اضطراری تعداد BUY، تبدیل دارایی خرد و توضیحات Full‑Universe از صفحه روزمره جدا شده‌اند.</div></div><a class="btn soft" href="/admin/bot/advanced.php">باز کردن تنظیمات پیشرفته</a></div></section>

<details class="panel"><summary style="cursor:pointer;font-weight:900">واژه‌ها یعنی چه؟</summary><div class="simple-guide" style="margin-top:12px"><div><b>پوزیشن</b><span>ارزی که ربات خریده و هنوز کامل نفروخته است.</span></div><div><b>Exposure / سرمایه درگیر</b><span>درصدی از کل پورتفو که داخل پوزیشن‌های باز قرار دارد.</span></div><div><b>Pending</b><span>سفارش ارسال شده ولی هنوز صرافی اجرای کامل آن را تأیید نکرده است.</span></div><div><b>Fill</b><span>مقداری از سفارش که واقعاً در صرافی معامله شده است.</span></div><div><b>Cooldown</b><span>مدت استراحت قبل از ورود دوباره روی همان ارز.</span></div><div><b>Trailing Profit</b><span>بعد از سود، حد خروج همراه رشد قیمت بالا می‌آید تا بخشی از سود حفظ شود.</span></div></div></details>
<?php tradeAdminFooter($version); ?>
</div></body></html>