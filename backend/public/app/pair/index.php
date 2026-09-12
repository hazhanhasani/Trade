<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$raw = strtoupper(trim((string)($_GET['code'] ?? '')));
$code = preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
$valid = strlen($code) === 10;
$safeCode = htmlspecialchars($valid ? $code : 'کد نامعتبر', ENT_QUOTES, 'UTF-8');
http_response_code($valid ? 200 : 400);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>اتصال امن Trade</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#151827;font-family:Tahoma,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:20px}.card{width:min(520px,100%);background:#fff;border:1px solid #e5e8f0;border-radius:24px;padding:24px;box-shadow:0 16px 50px rgba(21,24,39,.08)}h1{margin:0 0 8px;font-size:24px}.muted{color:#747b8e;line-height:1.9}.code{direction:ltr;text-align:center;font:900 30px monospace;letter-spacing:5px;background:#151827;color:#fff;border-radius:16px;padding:16px;margin:18px 0;user-select:all}.notice{background:#edf4ff;color:#244c93;border-radius:14px;padding:14px;line-height:1.9}.bad{background:#fff0f0;color:#a52c33}strong{font-weight:800}
</style>
</head>
<body>
<main class="card">
<h1>اتصال امن Trade</h1>
<?php if($valid): ?>
<p class="muted">اگر اپ Trade نصب و تأیید شده باشد، Android این لینک را مستقیماً با اپ باز می‌کند. اگر این صفحه را می‌بینی، کد زیر را داخل اپ در بخش «وارد کردن کد اتصال» وارد کن.</p>
<div class="code"><?=$safeCode?></div>
<div class="notice"><strong>این کد فقط ۱۰ دقیقه اعتبار دارد.</strong><br>این صفحه کد را مصرف نمی‌کند و هیچ توکن یا کلید صرافی نمایش نمی‌دهد.</div>
<?php else: ?>
<div class="notice bad"><strong>لینک اتصال معتبر نیست.</strong><br>از پنل مدیریت یک کد اتصال جدید بساز.</div>
<?php endif; ?>
</main>
</body>
</html>
