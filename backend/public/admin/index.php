<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Trading\OrderService;

if (!Config::installed()) {
    header('Location: /install/');
    exit;
}

session_name('trade_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']),
    'samesite' => 'Strict',
    'path' => '/admin',
]);
session_start();

$pdo = Database::connection();
$message = '';
$error = '';

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/');
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id,password_hash FROM admins WHERE username=:u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, (string) $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: /admin/');
            exit;
        }
        $error = 'نام کاربری یا رمز عبور صحیح نیست.';
    }
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود Trade</title><style>body{font-family:Tahoma,Arial;background:#f5f7fb;margin:0;color:#15203a}.box{max-width:380px;margin:12vh auto;background:#fff;padding:28px;border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 18px 45px #13213a12}input,button{width:100%;box-sizing:border-box;padding:13px;border-radius:11px;margin:7px 0}input{border:1px solid #ccd5e3}button{border:0;background:#1f6fff;color:#fff;font-weight:700}.err{background:#fff0f0;padding:10px;border-radius:10px;color:#a11}</style></head><body><div class="box"><h2>Trade</h2><p>ورود به پنل مانیتورینگ</p><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif?><form method="post"><input name="username" placeholder="نام کاربری" required autocomplete="username"><input type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password"><button>ورود</button></form></div></body></html><?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF');
    }
    if ((string) ($_POST['action'] ?? '') === 'test_bitpin') {
        try {
            $service = new OrderService();
            $client = $service->client();
            $wallets = $client->wallets();
            $service->syncTokens($client);
            $message = 'اتصال Bitpin موفق بود. اطلاعات کیف پول دریافت شد.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$credential = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='bitpin')")->fetchColumn();
$runs = $pdo->query('SELECT run_id,status,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade Admin</title><style>*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#172033;font-family:Tahoma,Arial}.wrap{max-width:1050px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px}.badge{padding:7px 11px;border-radius:20px;background:#fff;border:1px solid #e2e7f0}.card{background:#fff;border:1px solid #e4e9f2;border-radius:18px;padding:18px;box-shadow:0 8px 26px #14213a0b;margin-top:14px}.btn{border:0;border-radius:11px;padding:11px 15px;font-weight:700;cursor:pointer;background:#1769ff;color:#fff}.muted{color:#68748a}.msg{padding:12px;border-radius:12px;background:#ebfff4;margin:12px 0}.err{padding:12px;border-radius:12px;background:#fff0f0;color:#a11;margin:12px 0}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #edf0f5;text-align:right}@media(max-width:760px){.wrap{padding:14px}.top{align-items:flex-start;flex-direction:column}}</style></head><body><div class="wrap"><div class="top"><div><h1 style="margin-bottom:4px">Trade</h1><div class="muted">پنل مانیتورینگ cPanel — حالت فقط خواندنی</div></div><div><span class="badge">Bitpin: <?=$credential?'✅ متصل':'⚠️ بدون کلید'?></span> <a class="badge" href="?logout=1">خروج</a></div></div><?php if($message):?><div class="msg"><?=htmlspecialchars($message)?></div><?php endif?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif?><div class="card"><h3>وضعیت سرویس</h3><p>این نسخه فقط اطلاعات بازار، کیف پول و سفارش‌های موجود را می‌خواند و سفارش جدیدی به صرافی ارسال نمی‌کند.</p><?php if($credential):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['csrf'])?>"><input type="hidden" name="action" value="test_bitpin"><button class="btn">تست اتصال Bitpin</button></form><?php endif?></div><div class="card"><h3>آخرین Cron Runها</h3><table><tr><th>Run</th><th>وضعیت</th><th>شروع UTC</th><th>پایان</th></tr><?php foreach($runs as $r):?><tr><td><?=htmlspecialchars(substr((string)$r['run_id'],0,12))?></td><td><?=htmlspecialchars((string)$r['status'])?></td><td><?=htmlspecialchars((string)$r['started_at'])?></td><td><?=htmlspecialchars((string)($r['finished_at']??'-'))?></td></tr><?php endforeach?></table></div></div></body></html>
