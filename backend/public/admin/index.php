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
    'secure' => true,
    'samesite' => 'Strict',
    'path' => '/admin',
]);
session_start();

$pdo = Database::connection();
$message = '';
$error = '';

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

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
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود Trade</title><style>body{font-family:Tahoma,Arial;background:#f5f7fb;margin:0;color:#15203a}.box{max-width:380px;margin:12vh auto;background:#fff;padding:28px;border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 18px 45px #13213a12}input,button{width:100%;box-sizing:border-box;padding:13px;border-radius:11px;margin:7px 0}input{border:1px solid #ccd5e3}button{border:0;background:#1f6fff;color:#fff;font-weight:700}.err{background:#fff0f0;padding:10px;border-radius:10px;color:#a11}</style></head><body><div class="box"><h2>Trade</h2><p>ورود به پنل مدیریت</p><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif?><form method="post"><input name="username" placeholder="نام کاربری" required autocomplete="username"><input type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password"><button>ورود</button></form></div></body></html><?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $service = new OrderService();
        if ($action === 'test_bitpin') {
            $client = $service->client();
            $client->wallets();
            $service->syncTokens($client);
            $message = 'اتصال Bitpin موفق بود و اطلاعات کیف پول دریافت شد.';
        } elseif ($action === 'kill_switch') {
            $enabled = (string) ($_POST['enabled'] ?? '1') === '1';
            $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('kill_switch',:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
            $stmt->execute([':v' => $enabled ? '1' : '0']);
            $message = $enabled ? 'Kill Switch فعال شد؛ سفارش جدید متوقف است.' : 'Kill Switch غیرفعال شد.';
        } elseif ($action === 'create_order') {
            $payload = [
                'market' => $_POST['market'] ?? '',
                'amount1' => $_POST['amount1'] ?? '',
                'price' => $_POST['price'] ?? '',
                'type' => $_POST['type'] ?? '',
                'mode' => $_POST['mode'] ?? 'limit',
            ];
            $result = $service->create($payload, 'web_admin');
            $exchange = is_array($result['exchange'] ?? null) ? $result['exchange'] : [];
            $exchangeId = (string) ($exchange['id'] ?? $exchange['order_id'] ?? '');
            $message = $exchangeId !== '' ? 'سفارش واقعی ثبت شد. شناسه: ' . $exchangeId : 'سفارش واقعی ثبت شد.';
        } elseif ($action === 'cancel_order') {
            $orderId = trim((string) ($_POST['order_id'] ?? ''));
            if ($orderId === '') throw new InvalidArgumentException('شناسه سفارش لازم است.');
            $service->cancel($orderId);
            $message = 'درخواست لغو سفارش ارسال شد.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$credential = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='bitpin')")->fetchColumn();
$killSwitch = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1';
$live = (bool) Config::get('trading.enabled', false);
$maxOrderValue = (float) Config::get('trading.max_order_value', 0);
$maxOrdersHour = (int) Config::get('trading.max_orders_per_hour', 10);
$runs = $pdo->query('SELECT run_id,status,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
$localOrders = $pdo->query('SELECT local_id,exchange_order_id,market_code,side,order_mode,amount,price,status,created_at FROM orders ORDER BY id DESC LIMIT 12')->fetchAll();
$csrf = htmlspecialchars((string) $_SESSION['csrf']);
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade Admin</title><style>*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#172033;font-family:Tahoma,Arial}.wrap{max-width:1120px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px}.badges{display:flex;gap:8px;flex-wrap:wrap}.badge{padding:7px 11px;border-radius:20px;background:#fff;border:1px solid #e2e7f0;text-decoration:none;color:#172033}.card{background:#fff;border:1px solid #e4e9f2;border-radius:18px;padding:18px;box-shadow:0 8px 26px #14213a0b;margin-top:14px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.formgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.btn{border:0;border-radius:11px;padding:11px 15px;font-weight:700;cursor:pointer;background:#1769ff;color:#fff}.btn.danger{background:#c62828}.btn.safe{background:#16784a}.muted{color:#68748a}.msg{padding:12px;border-radius:12px;background:#ebfff4;margin:12px 0}.err{padding:12px;border-radius:12px;background:#fff0f0;color:#a11;margin:12px 0}input,select{width:100%;padding:11px;border:1px solid #ccd5e3;border-radius:10px;background:#fff}label{font-size:12px;color:#68748a;display:block;margin-bottom:5px}table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:9px;border-bottom:1px solid #edf0f5;text-align:right;white-space:nowrap}.scroll{overflow:auto}.live{color:#16784a;font-weight:700}.stopped{color:#b42318;font-weight:700}@media(max-width:760px){.wrap{padding:14px}.top{align-items:flex-start;flex-direction:column}.grid,.formgrid{grid-template-columns:1fr}}</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin-bottom:4px">Trade</h1><div class="muted">rado-taxi.sbs — پنل مدیریت Bitpin</div></div><div class="badges"><span class="badge">Bitpin: <?=$credential?'✅ متصل':'⚠️ بدون کلید'?></span><span class="badge">Mode: <?=$live?'LIVE':'DISABLED'?></span><a class="badge" href="?logout=1">خروج</a></div></div>
<?php if($message):?><div class="msg"><?=htmlspecialchars($message)?></div><?php endif?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif?>
<div class="grid">
<div class="card"><h3>وضعیت Live</h3><p class="<?=$killSwitch?'stopped':'live'?>"><?=$killSwitch?'⛔ Kill Switch روشن است':'● اجرای سفارش فعال است'?></p><p class="muted">حداکثر ارزش سفارش: <?=$maxOrderValue>0?htmlspecialchars((string)$maxOrderValue):'بدون سقف برنامه'?> — سقف ساعتی: <?=htmlspecialchars((string)$maxOrdersHour)?></p><div style="display:flex;gap:8px;flex-wrap:wrap"><?php if($credential):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_bitpin"><button class="btn">تست Bitpin</button></form><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$killSwitch?'0':'1'?>"><button class="btn <?=$killSwitch?'safe':'danger'?>"><?=$killSwitch?'فعال‌کردن سفارش‌ها':'توقف فوری سفارش‌ها'?></button></form></div></div>
<div class="card"><h3>لغو سفارش</h3><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="cancel_order"><label>Exchange Order ID</label><input name="order_id" required placeholder="شناسه سفارش بیت‌پین"><button class="btn danger" style="margin-top:10px">لغو سفارش</button></form></div>
</div>
<div class="card"><h3>ثبت سفارش واقعی</h3><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create_order"><div class="formgrid"><div><label>Bitpin Market ID</label><input type="number" min="1" name="market" required></div><div><label>Amount 1</label><input type="number" min="0" step="any" name="amount1" required></div><div><label>Price</label><input type="number" min="0" step="any" name="price" required></div><div><label>Side</label><select name="type"><option value="buy">Buy</option><option value="sell">Sell</option></select></div><div><label>Mode</label><select name="mode"><option value="limit">Limit</option><option value="market">Market</option></select></div><div style="display:flex;align-items:end"><button class="btn" style="width:100%" <?=$live&&$credential&&!$killSwitch?'':'disabled'?>>ارسال سفارش Live</button></div></div></form></div>
<div class="card"><h3>آخرین سفارش‌های ثبت‌شده در Trade</h3><div class="scroll"><table><tr><th>Exchange ID</th><th>Market</th><th>Side</th><th>Mode</th><th>Amount</th><th>Price</th><th>Status</th><th>UTC</th></tr><?php foreach($localOrders as $o):?><tr><td><?=htmlspecialchars((string)($o['exchange_order_id']??'-'))?></td><td><?=htmlspecialchars((string)$o['market_code'])?></td><td><?=htmlspecialchars((string)$o['side'])?></td><td><?=htmlspecialchars((string)$o['order_mode'])?></td><td><?=htmlspecialchars((string)$o['amount'])?></td><td><?=htmlspecialchars((string)($o['price']??'-'))?></td><td><?=htmlspecialchars((string)$o['status'])?></td><td><?=htmlspecialchars((string)$o['created_at'])?></td></tr><?php endforeach?></table></div></div>
<div class="card"><h3>آخرین Cron Runها</h3><div class="scroll"><table><tr><th>Run</th><th>وضعیت</th><th>شروع UTC</th><th>پایان</th></tr><?php foreach($runs as $r):?><tr><td><?=htmlspecialchars(substr((string)$r['run_id'],0,12))?></td><td><?=htmlspecialchars((string)$r['status'])?></td><td><?=htmlspecialchars((string)$r['started_at'])?></td><td><?=htmlspecialchars((string)($r['finished_at']??'-'))?></td></tr><?php endforeach?></table></div></div>
</div></body></html>
