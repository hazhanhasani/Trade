<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;
use Trade\Trading\OrderService;

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

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$pdo = Database::connection();
$message = '';
$error = '';
$bitpinStatus = 'نامشخص';
$bitpinOk = false;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function testBitpinConnection(): array
{
    $service = new OrderService();
    $client = $service->client();

    // Force a clean API-key authentication so a stale access/refresh token
    // can never hide whether the saved API credentials themselves are valid.
    $client->authenticate();
    $service->syncTokens($client);
    $wallets = $client->wallets();
    $service->syncTokens($client);

    return $wallets;
}

function detectEgressIp(): ?string
{
    if (!extension_loaded('curl')) {
        return null;
    }

    $ch = curl_init('https://api.ipify.org');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: text/plain', 'User-Agent: Trade/1.0-repair'],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($body) || $status !== 200) {
        return null;
    }

    $ip = trim($body);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'test_current') {
            testBitpinConnection();
            $message = 'اتصال Bitpin با ورود مجدد کامل موفق بود و Wallet API پاسخ داد.';
        } elseif ($action === 'reset_tokens') {
            $pdo->exec("UPDATE exchange_credentials SET access_token_enc=NULL,refresh_token_enc=NULL,updated_at=UTC_TIMESTAMP() WHERE exchange_name='bitpin'");
            testBitpinConnection();
            $message = 'توکن‌های قدیمی پاک شدند و ورود مجدد Bitpin با موفقیت انجام شد.';
        } elseif ($action === 'save_credentials') {
            $apiKey = trim((string) ($_POST['api_key'] ?? ''));
            $secretKey = trim((string) ($_POST['secret_key'] ?? ''));

            if ($apiKey === '' || $secretKey === '') {
                throw new InvalidArgumentException('هر دو مقدار API Key و Secret Key لازم هستند.');
            }
            if (strlen($apiKey) > 1000 || strlen($secretKey) > 1000) {
                throw new InvalidArgumentException('طول کلید API معتبر نیست.');
            }

            $encryptionKey = (string) Config::require('app.encryption_key');
            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO exchange_credentials (exchange_name,api_key_enc,secret_key_enc,access_token_enc,refresh_token_enc,created_at,updated_at)
                     VALUES ('bitpin',:api,:secret,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE
                        api_key_enc=VALUES(api_key_enc),
                        secret_key_enc=VALUES(secret_key_enc),
                        access_token_enc=NULL,
                        refresh_token_enc=NULL,
                        updated_at=UTC_TIMESTAMP()"
                );
                $stmt->execute([
                    ':api' => Crypto::encrypt($apiKey, $encryptionKey),
                    ':secret' => Crypto::encrypt($secretKey, $encryptionKey),
                ]);

                testBitpinConnection();
                $pdo->commit();
                $message = 'کلیدهای جدید ذخیره و با Bitpin با موفقیت تأیید شدند.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 500);
    }
}

$credentialExists = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='bitpin')")->fetchColumn();
if ($credentialExists) {
    try {
        $service = new OrderService();
        $client = $service->client();
        $client->wallets();
        $service->syncTokens($client);
        $bitpinOk = true;
        $bitpinStatus = 'متصل و سالم';
    } catch (Throwable $e) {
        $bitpinStatus = mb_substr($e->getMessage(), 0, 300);
    }
} else {
    $bitpinStatus = 'کلید API ذخیره نشده است';
}

$lastRun = $pdo->query("SELECT status,started_at,finished_at,TIMESTAMPDIFF(SECOND,started_at,UTC_TIMESTAMP()) AS age_seconds FROM bot_runs ORDER BY id DESC LIMIT 1")->fetch() ?: null;
$cronPath = realpath(dirname(__DIR__, 2) . '/cron/tick.php') ?: dirname(__DIR__, 2) . '/cron/tick.php';
$phpVersionPath = '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php';
$phpCli = is_file($phpVersionPath) ? $phpVersionPath : (is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : (PHP_BINARY ?: '/usr/bin/php'));
$cronCommand = '* * * * * ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($cronPath) . ' >/dev/null 2>&1';
$egressIp = detectEgressIp();
$csrf = h((string) $_SESSION['csrf']);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Repair Center</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:900px;margin:auto;padding:18px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center}.card{background:#fff;border:1px solid #e3e9f2;border-radius:20px;padding:18px;margin-top:14px;box-shadow:0 8px 28px #14213a0b}.good{background:#ecfff5;border-color:#bfe8d3}.bad{background:#fff4f4;border-color:#f3c8c8}.msg{background:#ebfff4;color:#176548;padding:12px;border-radius:12px;margin:12px 0}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px;margin:12px 0}.muted{color:#68748a;font-size:13px}.btn{border:0;border-radius:11px;padding:11px 14px;font-weight:700;cursor:pointer;background:#1769ff;color:#fff;text-decoration:none;display:inline-block}.btn.secondary{background:#edf4ff;color:#1454aa}.btn.warn{background:#9b5d00}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}input{width:100%;padding:12px;border:1px solid #ccd5e3;border-radius:10px;margin:6px 0 12px}.code{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;background:#101827;color:#eef5ff;padding:13px;border-radius:12px;font-family:monospace;font-size:12px}.status{font-weight:700}.ok{color:#087857}.no{color:#b42318}h1,h2{margin-top:0}@media(max-width:650px){.wrap{padding:11px}.top{align-items:flex-start;flex-direction:column}.btn{width:100%;text-align:center}}
</style>
</head>
<body><div class="wrap">
<div class="top"><div><h1 style="margin-bottom:5px">مرکز تعمیر Trade</h1><div class="muted">Bitpin Authentication + Cron Diagnostics</div></div><a class="btn secondary" href="/admin/">بازگشت به پنل</a></div>

<?php if ($message): ?><div class="msg"><?=h($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="err"><?=h($error)?></div><?php endif; ?>

<div class="card <?=$bitpinOk ? 'good' : 'bad'?>">
<h2>Bitpin API</h2>
<p class="status <?=$bitpinOk ? 'ok' : 'no'?>"><?=$bitpinOk ? '● ' : '● '?><?=h($bitpinStatus)?></p>
<p class="muted">در تست این صفحه ابتدا توکن‌های تازه با API Key/Secret گرفته می‌شوند؛ بنابراین نتیجه، اعتبار واقعی کلیدهای ذخیره‌شده را مشخص می‌کند.</p>
<div class="row">
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_current"><button class="btn" type="submit">ورود مجدد و تست Bitpin</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="reset_tokens"><button class="btn warn" type="submit">پاک‌سازی توکن‌های قدیمی و ورود مجدد</button></form>
</div>
<?php if ($egressIp): ?><p class="muted">IP خروجی این هاست: <b dir="ltr"><?=h($egressIp)?></b> — اگر برای API در Bitpin محدودیت IP گذاشته‌ای، همین IP باید مجاز باشد.</p><?php else: ?><p class="muted">IP خروجی هاست خودکار تشخیص داده نشد. اگر Bitpin محدودیت IP دارد، IP خروجی cPanel را در Bitpin بررسی کن.</p><?php endif; ?>
</div>

<div class="card">
<h2>جایگزینی API Key / Secret Key</h2>
<p class="muted">مقادیر قبلی نمایش داده نمی‌شوند. کلید جدید فقط در صورت موفق شدن Authentication و Wallet API ذخیره می‌شود؛ در صورت خطا، تغییرات Rollback می‌شوند.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input type="hidden" name="action" value="save_credentials">
<label>Bitpin API Key</label>
<input name="api_key" required autocomplete="off" spellcheck="false" dir="ltr">
<label>Bitpin Secret Key</label>
<input type="password" name="secret_key" required autocomplete="new-password" spellcheck="false" dir="ltr">
<button class="btn" type="submit">ذخیره، ورود مجدد و تست</button>
</form>
</div>

<div class="card <?= $lastRun ? '' : 'bad' ?>">
<h2>Cron</h2>
<?php if ($lastRun): ?>
<p>آخرین اجرا: <b><?=h($lastRun['status'])?></b> — <?=h($lastRun['age_seconds'])?> ثانیه قبل</p>
<?php else: ?>
<p class="status no">● هنوز هیچ اجرای Cron ثبت نشده است.</p>
<?php endif; ?>
<p>در cPanel → <b>Cron Jobs</b> این دستور را با زمان‌بندی Every Minute ثبت کن:</p>
<div class="code"><?=h($cronCommand)?></div>
<p class="muted">مسیر Tick روی همین نصب به‌صورت خودکار محاسبه شده است. اجرای Cron برای ربات Auto Trading و Update خودکار Backend لازم است.</p>
</div>
</div></body></html>
