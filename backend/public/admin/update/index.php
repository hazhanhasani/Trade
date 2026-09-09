<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Updater;

if (!Config::installed()) {
    header('Location: /install/');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_name('trade_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict',
    'path' => '/admin',
]);
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/');
    exit;
}
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /admin/update/?csrf=refresh');
        exit;
    }

    try {
        $result = Updater::updateNow();
        $status = (string) ($result['status'] ?? 'unknown');
        if ($status === 'updated') {
            $message = 'Backend با موفقیت به نسخه ' . (string) ($result['current_version'] ?? Updater::currentVersion()) . ' ارتقا یافت.';
        } elseif ($status === 'up_to_date') {
            $message = 'نسخه نصب‌شده همین حالا بررسی شد و به‌روز است.';
        } else {
            $error = 'بررسی/نصب انجام شد اما وضعیت Updater برابر ' . $status . ' است. جزئیات پایین صفحه نمایش داده می‌شود.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$state = Updater::state();
$check = null;
$checkError = '';
try {
    $check = Updater::check();
} catch (Throwable $e) {
    $checkError = $e->getMessage();
}

$current = Updater::currentVersion();
$latest = is_array($check) ? (string) ($check['latest_version'] ?? $current) : (string) ($state['latest_version'] ?? $current);
$available = is_array($check) && (bool) ($check['update_available'] ?? false);
$capital = strtoupper((string) Config::get('trading.capital_asset', 'GRAM'));
$diag = Updater::diagnostics();
$interval = (int) ($diag['check_interval_seconds'] ?? 300);
$csrf = h((string) $_SESSION['csrf']);
?><!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Update Center</title>
<style>
*{box-sizing:border-box}body{font-family:Tahoma,Arial;background:#f4f7fb;color:#172033;margin:0}.wrap{max-width:920px;margin:24px auto;padding:16px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;margin:12px 0;box-shadow:0 10px 30px #14213a0b}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.box{background:#f7f9fc;border-radius:14px;padding:15px}.ok{color:#087857;font-weight:700}.warn{color:#ad6b00;font-weight:700}.bad{color:#a11;font-weight:700}.back,.btn{display:inline-block;text-decoration:none;background:#1769ff;color:#fff;padding:11px 15px;border-radius:10px;border:0;font-weight:700;cursor:pointer}.btn.safe{background:#16784a}.muted{color:#68748a;font-size:13px}.code{direction:ltr;text-align:left;font-family:monospace;background:#f2f5fa;padding:12px;border-radius:10px;overflow:auto;white-space:pre-wrap;word-break:break-all}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.pill{padding:7px 10px;border-radius:999px;background:#f2f5fa;font-size:13px}.msg{background:#ecfff4;color:#176548;padding:12px;border-radius:12px}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px}@media(max-width:700px){.grid{grid-template-columns:1fr}.wrap{padding:11px}}
</style></head><body><div class="wrap">
<div class="row"><a class="back" href="/admin/">بازگشت به پنل</a><span class="pill">Backend v<?=h($current)?></span><span class="pill">Auto Update: <?=($diag['auto_enabled']??false)?'فعال':'غیرفعال'?></span></div>

<?php if(isset($_GET['csrf'])):?><div class="err" style="margin-top:12px">فرم قدیمی بود؛ توکن امنیتی تازه شد. دوباره دکمه را بزن.</div><?php endif?>
<?php if($message):?><div class="msg" style="margin-top:12px"><?=h($message)?></div><?php endif?>
<?php if($error):?><div class="err" style="margin-top:12px"><?=h($error)?></div><?php endif?>

<div class="card"><h1 style="margin-top:0">🔄 Update Center</h1><p>نسخه نصب‌شده، نسخه GitHub، آخرین وضعیت Auto Update و پیش‌نیازهای نصب در همین صفحه قابل مشاهده‌اند. Cron هر دقیقه اجرا می‌شود ولی بررسی Release حداکثر هر <?=h((string)round($interval/60,1))?> دقیقه انجام می‌شود.</p></div>

<div class="grid">
<div class="box"><div class="muted">نسخه نصب‌شده</div><h2><?=h($current)?></h2></div>
<div class="box"><div class="muted">آخرین نسخه GitHub</div><h2><?=h($latest)?></h2></div>
<div class="box"><div class="muted">دارایی اصلی</div><h2><?=h($capital)?></h2></div>
</div>

<div class="card"><h2>وضعیت انتشار</h2>
<?php if($checkError):?><p class="bad">GitHub Manifest قابل بررسی نبود: <?=h($checkError)?></p><?php elseif($available):?><p class="warn">نسخه جدید <?=h($latest)?> موجود است و Auto Update باید آن را نصب کند.</p><?php else:?><p class="ok">نسخه نصب‌شده با Release فعلی هماهنگ است.</p><?php endif?>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn safe" type="submit">بررسی و نصب همین حالا</button></form>
<p class="muted">این دکمه فقط راه بازیابی دستی است؛ Auto Update همچنان مستقل از این صفحه از طریق Cron فعال می‌ماند.</p>
</div>

<div class="card"><h2>سلامت Auto Updater</h2><div class="row"><span class="pill">cURL: <?=($diag['curl_available']??false)?'✅':'❌'?></span><span class="pill">ZIP: <?=($diag['zip_available']??false)?'✅':'❌'?></span><span class="pill">Storage Writable: <?=($diag['storage_writable']??false)?'✅':'❌'?></span><span class="pill">Interval: <?=h((string)$interval)?>s</span></div>
<h3>آخرین وضعیت ثبت‌شده</h3><div class="code"><?=h(json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></div>

<div class="card"><h2>رفتار خودکار</h2><p>Updater ابتدا manifest را می‌گیرد، نسخه را مقایسه می‌کند، ZIP را دانلود و SHA-256 را بررسی می‌کند، Backup می‌سازد و سپس فایل‌ها را جایگزین می‌کند. اگر Update در همان Cron انجام شود، موتور ترید تا اجرای Cron بعدی صبر می‌کند تا هیچ پردازش Live با فایل‌های دو نسخه مختلف اجرا نشود.</p><p class="muted">storage/config.php و اطلاعات محرمانه با آپدیت جایگزین نمی‌شوند.</p></div>
</div></body></html>
