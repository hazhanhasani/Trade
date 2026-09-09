<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
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
$pairCode = '';
$pairLink = '';
$issuedToken = '';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

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
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود Trade</title><style>body{font-family:Tahoma,Arial;background:#f5f7fb;margin:0;color:#15203a}.box{max-width:380px;margin:12vh auto;background:#fff;padding:28px;border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 18px 45px #13213a12}input,button{width:100%;box-sizing:border-box;padding:13px;border-radius:11px;margin:7px 0}input{border:1px solid #ccd5e3}button{border:0;background:#1f6fff;color:#fff;font-weight:700}.err{background:#fff0f0;padding:10px;border-radius:10px;color:#a11}</style></head><body><div class="box"><h2>Trade</h2><p>ورود به پنل مدیریت</p><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?><form method="post"><input name="username" placeholder="نام کاربری" required autocomplete="username"><input type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password"><button>ورود</button></form></div></body></html><?php
    exit;
}

AppAccess::bootstrapLegacy($pdo);
AppAccess::cleanup($pdo);

if (($_GET['ajax'] ?? '') === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $credential = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='bitpin')")->fetchColumn();
    $cron = $pdo->query("SELECT status,started_at,finished_at,TIMESTAMPDIFF(SECOND,started_at,UTC_TIMESTAMP()) AS age_seconds FROM bot_runs ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $cronAge = $cron ? (int) $cron['age_seconds'] : null;
    $cronOk = $cronAge !== null && $cronAge <= 180 && in_array((string) $cron['status'], ['success','running'], true);

    $bitpinOk = false;
    $bitpinMessage = $credential ? 'در حال بررسی' : 'کلید API تنظیم نشده';
    if ($credential) {
        try {
            $service = new OrderService();
            $client = $service->client();
            $client->wallets();
            $service->syncTokens($client);
            $bitpinOk = true;
            $bitpinMessage = 'اتصال و Wallet API سالم است';
        } catch (Throwable $e) {
            $bitpinMessage = mb_substr($e->getMessage(), 0, 180);
        }
    }

    echo json_encode([
        'ok' => true,
        'checks' => [
            'database' => ['ok' => true, 'text' => 'MySQL متصل'],
            'https' => ['ok' => !empty($_SERVER['HTTPS']), 'text' => !empty($_SERVER['HTTPS']) ? 'HTTPS فعال' : 'HTTPS تشخیص داده نشد'],
            'php' => ['ok' => PHP_VERSION_ID >= 80200, 'text' => 'PHP ' . PHP_VERSION],
            'bitpin' => ['ok' => $bitpinOk, 'text' => $bitpinMessage],
            'cron' => ['ok' => $cronOk, 'text' => $cronAge === null ? 'هنوز اجرا نشده' : 'آخرین اجرا ' . $cronAge . ' ثانیه قبل'],
            'app_tokens' => ['ok' => AppAccess::activeCount($pdo) > 0, 'text' => AppAccess::activeCount($pdo) . ' اتصال فعال'],
        ],
        'capital_asset' => strtoupper((string) Config::get('trading.capital_asset', 'TON')),
        'time_utc' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_pairing') {
            $label = trim((string) ($_POST['label'] ?? 'Android app'));
            $pair = AppAccess::createPairing($pdo, (int) $_SESSION['admin_id'], $label);
            $pairCode = (string) $pair['code'];
            $serverUrl = (string) Config::get('app.url', 'https://rado-taxi.sbs');
            $pairLink = 'trade://pair?server=' . rawurlencode($serverUrl) . '&code=' . rawurlencode($pairCode);
            $message = 'کد اتصال جدید ساخته شد و ۱۰ دقیقه اعتبار دارد.';
        } elseif ($action === 'issue_token') {
            $issued = AppAccess::issueToken($pdo, trim((string) ($_POST['label'] ?? 'Manual token')), (int) $_SESSION['admin_id']);
            $issuedToken = (string) $issued['token'];
            $message = 'توکن جدید ساخته شد؛ فقط همین یک‌بار نمایش داده می‌شود.';
        } elseif ($action === 'revoke_token') {
            $tokenId = (int) ($_POST['token_id'] ?? 0);
            if ($tokenId <= 0) throw new InvalidArgumentException('شناسه اتصال معتبر نیست.');
            AppAccess::revoke($pdo, $tokenId);
            $message = 'اتصال انتخاب‌شده لغو شد.';
        } elseif ($action === 'test_bitpin') {
            $service = new OrderService();
            $client = $service->client();
            $client->wallets();
            $service->syncTokens($client);
            $message = 'اتصال Bitpin موفق بود و Wallet API پاسخ داد.';
        } elseif ($action === 'kill_switch') {
            $enabled = (string) ($_POST['enabled'] ?? '1') === '1';
            $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('kill_switch',:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
            $stmt->execute([':v' => $enabled ? '1' : '0']);
            $message = $enabled ? 'توقف اضطراری فعال شد.' : 'توقف اضطراری غیرفعال شد.';
        } elseif ($action === 'cancel_order') {
            $orderId = trim((string) ($_POST['order_id'] ?? ''));
            if ($orderId === '') throw new InvalidArgumentException('شناسه سفارش لازم است.');
            $service = new OrderService();
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
$capitalAsset = strtoupper((string) Config::get('trading.capital_asset', 'TON'));
$runs = $pdo->query('SELECT run_id,status,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
$localOrders = $pdo->query('SELECT exchange_order_id,market_code,side,order_mode,amount,price,status,created_at FROM orders ORDER BY id DESC LIMIT 10')->fetchAll();
$appTokens = AppAccess::tokens($pdo);
$csrf = h((string) $_SESSION['csrf']);
?><!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px}.muted{color:#68748a}.badges{display:flex;gap:8px;flex-wrap:wrap}.badge{padding:7px 11px;border-radius:999px;background:#fff;border:1px solid #e2e7f0;color:#172033;text-decoration:none}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.healthgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.card{background:#fff;border:1px solid #e3e9f2;border-radius:20px;padding:18px;box-shadow:0 8px 28px #14213a0b;margin-top:14px}.smart{background:linear-gradient(135deg,#f8fbff,#eef5ff)}.health{border:1px solid #e3e9f2;border-radius:14px;padding:12px;background:#fff}.health b{display:block;margin-bottom:5px}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#9aa5b5;margin-left:6px}.dot.ok{background:#14a06f}.dot.bad{background:#d23b3b}.btn{border:0;border-radius:11px;padding:11px 15px;font-weight:700;cursor:pointer;background:#1769ff;color:#fff;text-decoration:none;display:inline-block}.btn.secondary{background:#edf4ff;color:#1454aa}.btn.danger{background:#c62828}.btn.safe{background:#16784a}.btn:disabled{opacity:.45}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.msg,.err,.pairbox,.tokenbox{padding:13px;border-radius:13px;margin:12px 0}.msg{background:#ebfff4;color:#176548}.err{background:#fff0f0;color:#a11}.pairbox{background:#eef5ff;border:1px solid #cfe0ff}.tokenbox{background:#fff8e7;border:1px solid #f2d17e}.code{font:800 28px monospace;letter-spacing:4px;direction:ltr;text-align:center;padding:10px}.secret{font-family:monospace;direction:ltr;word-break:break-all;background:#fff;padding:10px;border-radius:9px;border:1px dashed #d1b45d}input{width:100%;padding:11px;border:1px solid #ccd5e3;border-radius:10px;background:#fff}.mini{max-width:250px}table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:9px;border-bottom:1px solid #edf0f5;text-align:right;white-space:nowrap}.scroll{overflow:auto}.status-ok{color:#087857;font-weight:700}.status-bad{color:#a11;font-weight:700}details summary{cursor:pointer;font-weight:700;padding:4px 0}@media(max-width:780px){.wrap{padding:13px}.top{align-items:flex-start;flex-direction:column}.grid,.healthgrid{grid-template-columns:1fr}.code{font-size:23px;letter-spacing:2px}}
</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin:0 0 5px">Trade Control Center</h1><div class="muted">مدیریت هوشمند rado-taxi.sbs</div></div><div class="badges"><span class="badge">Bitpin <?=$credential?'✅':'⚠️'?></span><span class="badge">سرمایه: <?=h($capitalAsset)?></span><span class="badge">Mode: <?=$live?'LIVE':'DISABLED'?></span><a class="badge" href="?logout=1">خروج</a></div></div>

<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif?>
<?php if($error):?><div class="err"><?=h($error)?></div><?php endif?>

<div class="card smart">
<h2 style="margin-top:0">🩺 وضعیت هوشمند سیستم</h2>
<p class="muted">پنل هنگام باز شدن خودش دیتابیس، HTTPS، PHP، Bitpin، Cron و اتصال اپ را بررسی می‌کند.</p>
<div class="healthgrid" id="healthGrid">
<div class="health"><b><span class="dot"></span>دیتابیس</b><span>در حال بررسی…</span></div>
<div class="health"><b><span class="dot"></span>Bitpin API</b><span>در حال بررسی…</span></div>
<div class="health"><b><span class="dot"></span>Cron</b><span>در حال بررسی…</span></div>
<div class="health"><b><span class="dot"></span>HTTPS</b><span>در حال بررسی…</span></div>
<div class="health"><b><span class="dot"></span>PHP</b><span>در حال بررسی…</span></div>
<div class="health"><b><span class="dot"></span>اپ‌ها</b><span>در حال بررسی…</span></div>
</div>
<div class="row" style="margin-top:12px"><button class="btn secondary" type="button" onclick="loadHealth()">بررسی دوباره</button><span class="muted" id="healthTime"></span></div>
</div>

<div class="grid">
<div class="card">
<h2 style="margin-top:0">📱 اتصال خودکار اپ</h2>
<p>دیگر لازم نیست App API Token قدیمی را نگه داری. یک کد یک‌بارمصرف بساز و اپ خودش توکن جدید دریافت می‌کند.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create_pairing"><label class="muted">نام این دستگاه</label><input class="mini" name="label" value="Android phone" maxlength="120"><div style="margin-top:10px"><button class="btn">ساخت کد اتصال ۱۰ دقیقه‌ای</button></div></form>
<?php if($pairCode):?><div class="pairbox"><div class="muted">کد اتصال:</div><div class="code" id="pairCode"><?=h($pairCode)?></div><div class="row"><button type="button" class="btn secondary" onclick="copyText('pairCode')">کپی کد</button><a class="btn safe" href="<?=h($pairLink)?>">اتصال خودکار به اپ</a></div><p class="muted" style="margin-bottom:0">اگر پنل را روی همان گوشی باز کرده‌ای، «اتصال خودکار به اپ» را بزن. کد فقط یک‌بار و تا ۱۰ دقیقه قابل استفاده است.</p></div><?php endif?>
<details style="margin-top:14px"><summary>روش دستی اضطراری</summary><p class="muted">اگر Deep Link روی مرورگر باز نشد، یک توکن جدید بساز و فقط یک‌بار داخل اپ وارد کن.</p><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="issue_token"><input class="mini" name="label" value="Manual Android token" maxlength="120"><div style="margin-top:8px"><button class="btn secondary">ساخت توکن جدید</button></div></form></details>
<?php if($issuedToken):?><div class="tokenbox"><b>توکن جدید — فقط همین یک‌بار:</b><div class="secret" id="issuedToken"><?=h($issuedToken)?></div><button type="button" class="btn secondary" style="margin-top:8px" onclick="copyText('issuedToken')">کپی توکن</button></div><?php endif?>
</div>

<div class="card">
<h2 style="margin-top:0">🛡️ کنترل سرویس</h2>
<p class="<?=$killSwitch?'status-bad':'status-ok'?>"><?=$killSwitch?'⛔ توقف اضطراری روشن است':'✅ توقف اضطراری خاموش است'?></p>
<p class="muted">وضعیت Bitpin و Cron در بخش هوشمند بالا خودکار بررسی می‌شود.</p>
<div class="row"><?php if($credential):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_bitpin"><button class="btn secondary">تست فوری Bitpin</button></form><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$killSwitch?'0':'1'?>"><button class="btn <?=$killSwitch?'safe':'danger'?>"><?=$killSwitch?'خاموش‌کردن توقف اضطراری':'توقف اضطراری'?></button></form></div>
</div>
</div>

<div class="card">
<h2 style="margin-top:0">🔑 دستگاه‌ها و توکن‌های اپ</h2>
<p class="muted">هر دستگاه توکن مستقل دارد. گم‌شدن یک توکن دیگر نیازی به نصب مجدد یا قطع همه دستگاه‌ها ندارد.</p>
<div class="scroll"><table><tr><th>نام</th><th>ساخته‌شده</th><th>آخرین استفاده</th><th>وضعیت</th><th></th></tr><?php foreach($appTokens as $t):?><tr><td><?=h($t['label'])?></td><td><?=h($t['created_at'])?></td><td><?=h($t['last_used_at']??'-')?></td><td><?=$t['revoked_at']?'<span class="status-bad">لغوشده</span>':'<span class="status-ok">فعال</span>'?></td><td><?php if(!$t['revoked_at']):?><form method="post" onsubmit="return confirm('این اتصال لغو شود؟')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="<?=h($t['id'])?>"><button class="btn danger" style="padding:7px 10px">لغو</button></form><?php endif?></td></tr><?php endforeach?></table></div>
</div>

<div class="grid">
<div class="card"><h2 style="margin-top:0">⏱️ آخرین Cronها</h2><div class="scroll"><table><tr><th>Run</th><th>وضعیت</th><th>شروع UTC</th></tr><?php foreach($runs as $r):?><tr><td><?=h(substr((string)$r['run_id'],0,12))?></td><td><?=h($r['status'])?></td><td><?=h($r['started_at'])?></td></tr><?php endforeach?></table></div></div>
<div class="card"><h2 style="margin-top:0">📋 آخرین سفارش‌های ثبت‌شده</h2><div class="scroll"><table><tr><th>ID</th><th>Market</th><th>Side</th><th>Status</th></tr><?php foreach($localOrders as $o):?><tr><td><?=h($o['exchange_order_id']??'-')?></td><td><?=h($o['market_code'])?></td><td><?=h($o['side'])?></td><td><?=h($o['status'])?></td></tr><?php endforeach?></table></div><details style="margin-top:12px"><summary>لغو سفارش با شناسه</summary><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="cancel_order"><input name="order_id" required placeholder="Exchange Order ID"><button class="btn danger" style="margin-top:8px">ارسال درخواست لغو</button></form></details></div>
</div>
</div>
<script>
function copyText(id){const el=document.getElementById(id);if(!el)return;navigator.clipboard.writeText(el.textContent.trim()).then(()=>{});}
function healthCard(label,item){const cls=item.ok?'ok':'bad';return `<div class="health"><b><span class="dot ${cls}"></span>${label}</b><span>${escapeHtml(item.text)}</span></div>`;}
function escapeHtml(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
async function loadHealth(){const grid=document.getElementById('healthGrid');try{const r=await fetch('?ajax=health',{cache:'no-store',credentials:'same-origin'});const j=await r.json();const c=j.checks;grid.innerHTML=healthCard('دیتابیس',c.database)+healthCard('Bitpin API',c.bitpin)+healthCard('Cron',c.cron)+healthCard('HTTPS',c.https)+healthCard('PHP',c.php)+healthCard('اپ‌ها',c.app_tokens);document.getElementById('healthTime').textContent='آخرین بررسی: '+new Date().toLocaleTimeString('fa-IR');}catch(e){grid.innerHTML='<div class="health"><b><span class="dot bad"></span>خطا</b><span>بررسی خودکار پنل ناموفق بود.</span></div>';}}
loadHealth();
</script>
</body></html>
