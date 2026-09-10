<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Security\AndroidSigningService;

if (!Config::installed()) {
    header('Location: /install/');
    exit;
}

session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$service = new AndroidSigningService();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionCsrf = (string) ($_SESSION['csrf'] ?? '');
    $postedCsrf = (string) ($_POST['csrf'] ?? '');
    if ($sessionCsrf === '' || $postedCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /admin/signing.php?csrf_refresh=1', true, 303);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'ensure') {
            $service->ensure();
            $message = 'هویت امضای دائمی Android آماده است. همین کلید برای همه نسخه‌های بعدی استفاده می‌شود.';
        } elseif ($action === 'download_recovery') {
            $archive = $service->createRecoveryArchive();
            try {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="Trade-Android-Signing-Recovery.zip"');
                header('Content-Length: ' . filesize($archive));
                header('Cache-Control: no-store, max-age=0');
                readfile($archive);
            } finally {
                @unlink($archive);
            }
            exit;
        }
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 500);
    }
}

if (isset($_GET['csrf_refresh'])) {
    $error = 'فرم امنیتی قدیمی بود و تازه‌سازی شد. لطفاً دوباره اقدام کن.';
}

$status = $service->status();
$csrf = h((string) $_SESSION['csrf']);
?><!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Android Signing — Trade</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:860px;margin:auto;padding:18px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center}.card{background:#fff;border:1px solid #e3e9f2;border-radius:20px;padding:20px;margin-top:14px;box-shadow:0 8px 28px #14213a0b}.ok{background:#edfff6;border-color:#b9e9d1}.warn{background:#fff7e8;border-color:#efd49d}.msg{background:#ebfff4;color:#176548;padding:12px;border-radius:12px;margin:12px 0}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px;margin:12px 0}.muted{color:#68748a;line-height:1.9}.btn{border:0;border-radius:11px;padding:12px 16px;font-weight:700;cursor:pointer;background:#1769ff;color:#fff;text-decoration:none;display:inline-block}.btn.secondary{background:#edf4ff;color:#1454aa}.btn.safe{background:#16784a}.mono{direction:ltr;text-align:left;word-break:break-all;background:#101827;color:#eef5ff;padding:13px;border-radius:12px;font:13px monospace}.row{display:flex;gap:9px;flex-wrap:wrap;align-items:center}h1,h2{margin-top:0}@media(max-width:650px){.wrap{padding:11px}.top{flex-direction:column;align-items:flex-start}.btn{width:100%;text-align:center}}
</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin-bottom:6px">امضای دائمی Android</h1><div class="muted">هویت ثابت APK برای آپدیت بدون حذف نسخه قبلی</div></div><a class="btn secondary" href="/admin/">بازگشت به پنل</a></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif;?>
<?php if($error):?><div class="err"><?=h($error)?></div><?php endif;?>

<div class="card <?=$status['configured']?'ok':'warn'?>">
<h2><?=$status['configured']?'✅ امضای ثابت آماده است':'⚠️ امضای ثابت هنوز ساخته نشده'?></h2>
<?php if($status['configured']):?>
<p class="muted">این هویت روی cPanel به‌صورت خصوصی نگهداری می‌شود و GitHub Actions فقط با OIDC کوتاه‌عمر و محدود به همین مخزن و workflowهای اصلی به آن دسترسی می‌گیرد.</p>
<div class="mono">Alias: <?=h($status['alias'])?>
Store type: <?=h($status['format'])?>
Certificate SHA-256: <?=h($status['cert_sha256'])?>
Created at: <?=h($status['created_at'])?></div>
<div class="row" style="margin-top:14px">
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="download_recovery"><button class="btn safe">دانلود بکاپ بازیابی امضا</button></form>
</div>
<p class="muted"><b>مهم:</b> فایل بازیابی را در جای امن و خارج از هاست نگه دار. اگر هم هاست و هم این بکاپ از دست بروند، نسخه‌های بعدی APK نمی‌توانند روی نسخه‌های نصب‌شده فعلی آپدیت شوند.</p>
<?php else:?>
<p class="muted">با ساخت هویت، یک کلید RSA دائمی و یک گواهی بلندمدت ایجاد می‌شود. کلید خصوصی داخل GitHub یا APK قرار نمی‌گیرد.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="ensure"><button class="btn">ساخت امضای ثابت</button></form>
<p class="muted">در حالت عادی لازم نیست این دکمه را بزنی؛ workflow امضای Android بعد از آپدیت cPanel می‌تواند همین هویت را خودکار بسازد.</p>
<?php endif;?>
</div>

<div class="card">
<h2>نحوه اتصال به GitHub Actions</h2>
<p class="muted">برای جلوگیری از ذخیره کلید خصوصی در مخزن عمومی، workflow از GitHub OIDC استفاده می‌کند. توکن فقط برای مخزن <code>hazhanhasani/Trade</code>، شاخه <code>main</code> و workflowهای امضا پذیرفته می‌شود. هر APK نهایی نیز قبل از انتشار با SHA-256 گواهی بررسی می‌شود و اگر امضا با نسخه قبلی فرق کند، انتشار متوقف می‌شود.</p>
</div>
</div></body></html>
