<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Updater;

if (!Config::installed()) {
    header('Location: /install/');
    exit;
}

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

$state = Updater::state();
$check = null;
$error = '';
try {
    $check = Updater::check();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$current = Updater::currentVersion();
$latest = is_array($check) ? (string) ($check['latest_version'] ?? $current) : $current;
$available = is_array($check) && (bool) ($check['update_available'] ?? false);
$capital = strtoupper((string) Config::get('trading.capital_asset', 'TON'));
?><!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Update Center</title>
<style>
body{font-family:Tahoma,Arial;background:#f4f7fb;color:#172033;margin:0}.wrap{max-width:850px;margin:30px auto;padding:16px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;margin:12px 0;box-shadow:0 10px 30px #14213a0b}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.box{background:#f7f9fc;border-radius:14px;padding:15px}.ok{color:#087857;font-weight:700}.warn{color:#ad6b00;font-weight:700}.bad{color:#a11;font-weight:700}.back{display:inline-block;text-decoration:none;background:#1769ff;color:#fff;padding:10px 14px;border-radius:10px}.muted{color:#68748a;font-size:13px}.code{direction:ltr;font-family:monospace;background:#f2f5fa;padding:12px;border-radius:10px;overflow:auto}@media(max-width:700px){.grid{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<a class="back" href="/admin/">بازگشت به پنل</a>
<div class="card"><h1>🔄 Update Center</h1><p>این صفحه فقط وضعیت را نشان می‌دهد؛ نصب Backend توسط Cron به‌صورت خودکار انجام می‌شود و نیاز به فشردن دکمه ندارد.</p></div>
<div class="grid">
<div class="box"><div class="muted">نسخه نصب‌شده</div><h2><?=h($current)?></h2></div>
<div class="box"><div class="muted">آخرین نسخه GitHub</div><h2><?=h($latest)?></h2></div>
<div class="box"><div class="muted">سرمایه پایه</div><h2><?=h($capital)?></h2></div>
</div>
<div class="card"><h2>وضعیت آپدیت</h2>
<?php if($error):?><p class="bad">خطا در بررسی: <?=h($error)?></p><?php elseif($available):?><p class="warn">نسخه جدید پیدا شده؛ Cron در اجرای بعدی آن را نصب می‌کند.</p><?php else:?><p class="ok">Backend به‌روز است.</p><?php endif?>
<div class="code"><?=h(json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div>
</div>
<div class="card"><h2>رفتار خودکار</h2><p>هر ساعت نسخه بررسی می‌شود. قبل از جایگزینی فایل‌ها Backup ساخته می‌شود، checksum بسته بررسی می‌شود، Maintenance Lock فعال می‌شود و در صورت خطا Rollback انجام می‌شود.</p><p class="muted">Backupها در storage/backups نگهداری می‌شوند و اطلاعات حساس storage/config.php هیچ‌وقت با آپدیت جایگزین نمی‌شوند.</p></div>
</div></body></html>
