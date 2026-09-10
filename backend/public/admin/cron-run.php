<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Updater;

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
function disabledFunction(string $name): bool {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return in_array($name, $disabled, true);
}
function tailText(string $path, int $maxBytes = 24000): string {
    if (!is_file($path) || !is_readable($path)) return '';
    $size = filesize($path);
    if (!is_int($size) || $size <= 0) return '';
    $fp = fopen($path, 'rb');
    if ($fp === false) return '';
    $offset = max(0, $size - $maxBytes);
    if ($offset > 0) fseek($fp, $offset);
    $text = stream_get_contents($fp);
    fclose($fp);
    return is_string($text) ? $text : '';
}

$cronPath = realpath(dirname(__DIR__, 2) . '/cron/tick.php') ?: dirname(__DIR__, 2) . '/cron/tick.php';
$phpVersionPath = '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php';
$phpCli = is_file($phpVersionPath) ? $phpVersionPath : (is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : (PHP_BINARY ?: '/usr/bin/php'));
$storageDir = dirname(__DIR__, 2) . '/storage';
$cronLog = $storageDir . '/cron.log';
$heartbeatPath = $storageDir . '/cron-heartbeat.json';
$command = escapeshellarg($phpCli) . ' ' . escapeshellarg($cronPath) . ' >> ' . escapeshellarg($cronLog) . ' 2>&1';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string) ($_POST['csrf'] ?? '');
    $session = (string) ($_SESSION['csrf'] ?? '');
    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        $error = 'درخواست امنیتی معتبر نیست. صفحه را تازه‌سازی و دوباره تلاش کن.';
    } elseif (disabledFunction('exec') || !function_exists('exec')) {
        $error = 'تابع exec روی هاست غیرفعال است. Command نمایش‌داده‌شده را از cPanel Cron Jobs اجرا کن.';
    } elseif (!is_file($cronPath)) {
        $error = 'فایل cron/tick.php پیدا نشد: ' . $cronPath;
    } elseif (!is_file($phpCli)) {
        $error = 'PHP CLI پیدا نشد: ' . $phpCli;
    } else {
        $output = [];
        $code = 0;
        exec($command . ' > /dev/null 2>&1 & echo $!', $output, $code);
        $pid = trim((string) ($output[0] ?? ''));
        if ($code === 0) {
            $message = 'Cron به‌صورت پس‌زمینه شروع شد' . ($pid !== '' ? ' — PID: ' . $pid : '') . '. 5 تا 15 ثانیه بعد «تازه‌سازی وضعیت» را بزن.';
        } else {
            $error = 'اجرای پس‌زمینه Cron شروع نشد. Exit code: ' . $code;
        }
    }
}

$heartbeat = null;
if (is_file($heartbeatPath)) {
    $raw = file_get_contents($heartbeatPath);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $heartbeat = $decoded;
    }
}
$heartbeatAge = null;
if (is_array($heartbeat) && !empty($heartbeat['started_at'])) {
    $ts = strtotime((string) $heartbeat['started_at']);
    if ($ts !== false) $heartbeatAge = max(0, time() - $ts);
}
$log = tailText($cronLog);
$currentVersion = Updater::currentVersion();
$canExec = function_exists('exec') && !disabledFunction('exec');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اجرای Cron — Trade</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:920px;margin:auto;padding:16px}.top{display:flex;justify-content:space-between;gap:10px;align-items:center}.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:17px;margin-top:14px}.btn{border:0;border-radius:11px;padding:11px 14px;background:#1769ff;color:#fff;font-weight:700;text-decoration:none;display:inline-block;cursor:pointer}.btn.alt{background:#eef4ff;color:#174f9f}.msg{padding:12px;border-radius:12px;background:#ecfff5;color:#12633f;margin-top:12px}.err{padding:12px;border-radius:12px;background:#fff0f0;color:#a11;margin-top:12px}.code{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;background:#101827;color:#eef5ff;padding:13px;border-radius:12px;font:12px monospace;max-height:420px;overflow:auto}.good{color:#087857}.bad{color:#b42318}.muted{color:#68748a;font-size:13px}.row{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:650px){.top{align-items:flex-start;flex-direction:column}.btn{width:100%;text-align:center}}</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin:0 0 4px">اجرای تست Cron</h1><div class="muted">Backend <?=h($currentVersion)?> — تشخیص اجرای واقعی CLI</div></div><div class="row"><a class="btn alt" href="/admin/repair.php">مرکز تعمیر</a><a class="btn alt" href="/admin/">پنل</a></div></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=h($error)?></div><?php endif;?>
<div class="card"><h2>اجرای دستی امن</h2><p>این دکمه همان <code>cron/tick.php</code> را با PHP CLI هاست در پس‌زمینه اجرا می‌کند؛ بنابراین نتیجه دقیقاً همان چیزی است که cPanel Cron باید اجرا کند.</p><form method="post"><input type="hidden" name="csrf" value="<?=h((string)$_SESSION['csrf'])?>"><button class="btn" type="submit" <?=$canExec?'':'disabled'?>>اجرای Cron همین حالا</button></form><?php if(!$canExec):?><p class="bad">exec در این هاست غیرفعال است؛ از Command زیر در cPanel استفاده کن.</p><?php endif;?></div>
<div class="card"><h2>Heartbeat</h2><?php if($heartbeat):?><p class="<?=$heartbeatAge!==null&&$heartbeatAge<=150?'good':'bad'?>">آخرین شروع واقعی Cron: <?=h($heartbeatAge===null?'نامشخص':$heartbeatAge.' ثانیه قبل')?> — وضعیت: <b><?=h($heartbeat['status']??'unknown')?></b></p><div class="code"><?=h(json_encode($heartbeat,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div><?php else:?><p class="bad">هنوز heartbeat ثبت نشده؛ یعنی نسخه دارای تشخیص جدید Cron هنوز اجرا نشده است.</p><?php endif;?></div>
<div class="card"><h2>Command صحیح cPanel</h2><div class="code"><?=h($command)?></div><p class="muted">Schedule را Every Minute بگذار. ستاره‌های زمان‌بندی را داخل Command ننویس.</p></div>
<div class="card"><h2>آخرین خروجی cron.log</h2><?php if($log!==''):?><div class="code"><?=h($log)?></div><?php else:?><p class="muted">cron.log فعلاً خالی است.</p><?php endif;?></div>
</div></body></html>
