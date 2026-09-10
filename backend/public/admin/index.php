<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Trading\BotController;
use Trade\Trading\NobitexOrderService;
use Trade\Trading\NobitexPortfolioIntelligence;
use Trade\Trading\NobitexSchema;
use Trade\Trading\OrderService;
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

$pdo = Database::connection();
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n(mixed $v, int $d=2): string { return number_format((float)$v, $d, '.', ','); }
function onOff(bool $v): string { return $v ? 'ON' : 'OFF'; }
function statusClass(bool $ok): string { return $ok ? 'ok' : 'bad'; }
function pct(float $value, float $max): float { return $max > 0 ? max(0, min(100, ($value / $max) * 100)) : 0; }

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/');
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id,password_hash FROM admins WHERE username=:u LIMIT 1');
        $stmt->execute([':u'=>$username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, (string)$admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: /admin/');
            exit;
        }
        $error = 'نام کاربری یا رمز عبور صحیح نیست.';
    }
    ?><!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="theme-color" content="#111827">
        <title>Trade Cockpit</title>
        <style>
            :root{--bg:#f2f4f8;--card:#fff;--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--primary:#5b3df5;--primary2:#7c5cfc;--green:#0c9b72;--red:#d84a4a;--soft:#f0edff}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Tahoma,Arial,sans-serif;background:linear-gradient(150deg,#0f172a 0,#22264b 43%,#f2f4f8 43%);color:var(--ink)}.loginWrap{min-height:100vh;display:grid;place-items:center;padding:24px}.login{width:min(430px,100%);background:#fffffffa;border:1px solid #ffffff55;border-radius:30px;padding:28px;box-shadow:0 28px 80px #05081655;backdrop-filter:blur(18px)}.logo{width:58px;height:58px;border-radius:19px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-size:27px;font-weight:900;box-shadow:0 12px 28px #5b3df544}.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--primary);margin-top:18px}.login h1{margin:5px 0 4px;font-size:29px}.muted{color:var(--muted);font-size:13px;line-height:1.8}.secure{display:flex;gap:9px;align-items:center;background:var(--soft);color:#4435a6;border-radius:15px;padding:11px 12px;margin:18px 0}.secure i{width:9px;height:9px;border-radius:50%;background:var(--green)}input,button{font:inherit}.field{width:100%;border:1px solid #d7dce5;background:#fafbfc;padding:13px 14px;border-radius:14px;margin-top:9px;outline:none}.field:focus{border-color:#8d7afd;box-shadow:0 0 0 4px #5b3df512}.loginBtn{width:100%;border:0;border-radius:15px;padding:13px 16px;margin-top:14px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:800;cursor:pointer;box-shadow:0 10px 25px #5b3df533}.err{background:#fff0f0;color:#b42318;padding:11px 13px;border-radius:13px;margin-top:12px}@media(max-width:540px){body{background:linear-gradient(160deg,#111827 0,#25244d 36%,#f2f4f8 36%)}.login{padding:22px;border-radius:25px}}
        </style>
    </head>
    <body>
    <div class="loginWrap"><div class="login">
        <div class="logo">T</div>
        <div class="eyebrow">SECURE TRADING CONTROL</div>
        <h1>Trade Cockpit</h1>
        <div class="muted">ورود به مرکز کنترل معاملات، ریسک، صرافی‌ها و اپلیکیشن</div>
        <div class="secure"><i></i><b>Admin Session • HTTPS • SameSite Strict</b></div>
        <?php if ($error !== ''): ?><div class="err"><?=h($error)?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <input class="field" name="username" placeholder="نام کاربری" required autocomplete="username">
            <input class="field" type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password">
            <button class="loginBtn">ورود امن به پنل</button>
        </form>
    </div></div>
    </body></html><?php
    exit;
}

NobitexSchema::ensure();
AppAccess::bootstrapLegacy($pdo);
AppAccess::cleanup($pdo);

if (($_GET['ajax'] ?? '') === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    $status = (new BotController())->status();
    $cron = $pdo->query("SELECT status,started_at,TIMESTAMPDIFF(SECOND,started_at,UTC_TIMESTAMP()) age_seconds FROM bot_runs ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $age = $cron ? (int)$cron['age_seconds'] : null;
    $cronOk = $age !== null && $age <= 180;
    $checks = [];
    foreach (['bitpin','nobitex'] as $exchange) {
        $info = $status['exchanges'][$exchange];
        $ok = false;
        $text = $info['credentials_configured'] ? 'در حال تست' : 'کلید تنظیم نشده';
        if ($info['credentials_configured']) {
            try {
                if ($exchange === 'bitpin') {
                    $service = new OrderService();
                    $client = $service->client();
                    $client->wallets();
                    $service->syncTokens($client);
                } else {
                    (new NobitexOrderService())->client()->test();
                }
                $ok = true;
                $text = 'API و Wallet سالم';
            } catch (Throwable $e) {
                $text = mb_substr($e->getMessage(), 0, 180);
            }
        }
        $checks[$exchange] = ['ok'=>$ok,'text'=>$text];
    }
    echo json_encode(['ok'=>true,'checks'=>[
        'backend'=>['ok'=>true,'text'=>'Backend v'.Updater::currentVersion()],
        'database'=>['ok'=>true,'text'=>'MySQL متصل'],
        'nobitex'=>$checks['nobitex'],
        'bitpin'=>$checks['bitpin'],
        'cron'=>['ok'=>$cronOk,'text'=>$age===null?'هنوز اجرا نشده':($cronOk?'فعال — '.$age.' ثانیه قبل':'آخرین اجرا '.$age.' ثانیه قبل')],
        'https'=>['ok'=>!empty($_SERVER['HTTPS']),'text'=>!empty($_SERVER['HTTPS'])?'HTTPS فعال':'HTTPS تشخیص داده نشد'],
        'php'=>['ok'=>PHP_VERSION_ID>=80200,'text'=>'PHP '.PHP_VERSION],
        'sodium'=>['ok'=>function_exists('sodium_crypto_sign_detached'),'text'=>function_exists('sodium_crypto_sign_detached')?'Ed25519 آماده':'PHP Sodium غیرفعال'],
        'app_tokens'=>['ok'=>AppAccess::activeCount($pdo)>0,'text'=>AppAccess::activeCount($pdo).' اتصال فعال'],
    ],'time_utc'=>gmdate(DATE_ATOM)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$message = '';
$error = '';
$pairCode = '';
$pairLink = '';
$issuedToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf'] ?? '');
    $session = (string)($_SESSION['csrf'] ?? '');
    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /admin/?csrf_refresh=1', true, 303);
        exit;
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_pairing') {
            $pair = AppAccess::createPairing($pdo, (int)$_SESSION['admin_id'], trim((string)($_POST['label'] ?? 'Android phone')));
            $pairCode = (string)$pair['code'];
            $pairLink = 'trade://pair?server=' . rawurlencode((string)Config::get('app.url', 'https://rado-taxi.sbs')) . '&code=' . rawurlencode($pairCode);
            $message = 'کد اتصال ۱۰ دقیقه‌ای ساخته شد.';
        } elseif ($action === 'issue_token') {
            $issued = AppAccess::issueToken($pdo, trim((string)($_POST['label'] ?? 'Manual token')), (int)$_SESSION['admin_id']);
            $issuedToken = (string)$issued['token'];
            $message = 'توکن جدید ساخته شد؛ فقط همین یک‌بار نمایش داده می‌شود.';
        } elseif ($action === 'revoke_token') {
            AppAccess::revoke($pdo, (int)($_POST['token_id'] ?? 0));
            $message = 'اتصال دستگاه لغو شد.';
        } elseif ($action === 'kill_switch') {
            $on = (string)($_POST['enabled'] ?? '1') === '1';
            (new BotController())->setKillSwitch($on);
            $message = $on ? 'توقف اضطراری هر دو صرافی فعال شد.' : 'توقف اضطراری برداشته شد.';
        } elseif ($action === 'test_exchange') {
            $exchange = strtolower((string)($_POST['exchange'] ?? ''));
            if ($exchange === 'bitpin') {
                $service = new OrderService();
                $client = $service->client();
                $client->wallets();
                $service->syncTokens($client);
            } elseif ($exchange === 'nobitex') {
                (new NobitexOrderService())->client()->test();
            } else {
                throw new InvalidArgumentException('صرافی نامعتبر است.');
            }
            $message = 'اتصال ' . ($exchange === 'nobitex' ? 'Nobitex' : 'Bitpin') . ' موفق بود.';
        }
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 700);
    }
}
if (isset($_GET['csrf_refresh'])) $error = 'فرم امنیتی قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$controller = new BotController();
$status = $controller->status();
$exchanges = $status['exchanges'];
$kill = (bool)$status['kill_switch'];
$version = Updater::currentVersion();
$tokens = AppAccess::tokens($pdo);
$orders = $pdo->query('SELECT exchange_name,exchange_order_id,market_code,side,status,created_at FROM orders ORDER BY id DESC LIMIT 12')->fetchAll();
$runs = $pdo->query('SELECT run_id,status,started_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
$csrf = h((string)$_SESSION['csrf']);

try {
    $intelligence = (new NobitexPortfolioIntelligence())->snapshot($pdo);
} catch (Throwable $e) {
    $intelligence = ['error'=>mb_substr($e->getMessage(),0,240)];
}

$nobitex = $exchanges['nobitex'];
$bitpin = $exchanges['bitpin'];
$nbPerf = is_array($nobitex['performance'] ?? null) ? $nobitex['performance'] : [];
$nbCap = is_array($nobitex['portfolio_capacity'] ?? null) ? $nobitex['portfolio_capacity'] : [];
$bpPerf = is_array($bitpin['performance'] ?? null) ? $bitpin['performance'] : [];
$active = (int)($nbCap['active_positions'] ?? $nobitex['active_position_count'] ?? 0);
$max = max(0, (int)($nbCap['max_positions'] ?? 0));
$usedPct = pct((float)$active, (float)$max);
$intelDrawdown = (float)($intelligence['drawdown']['current_drawdown_percent'] ?? 0);
$intelMultiplier = (float)($intelligence['position_size_multiplier'] ?? 1);
$intelSamples = (int)($intelligence['realized_samples'] ?? 0);
$intelStreak = (int)($intelligence['drawdown']['losing_streak'] ?? 0);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#111827">
<title>Trade Cockpit</title>
<style>
:root{--bg:#f2f4f8;--card:#fff;--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--primary:#5b3df5;--primary2:#7c5cfc;--blue:#2563eb;--cyan:#0891b2;--green:#0c9b72;--red:#d84a4a;--amber:#b96c08;--soft:#f0edff;--greenSoft:#e9f8f2;--redSoft:#ffeeee;--amberSoft:#fff6e5;--shadow:0 14px 40px #1820390d}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}.app{max-width:1360px;margin:auto;padding:18px}.topbar{position:sticky;top:0;z-index:20;margin:-18px -18px 18px;padding:14px 18px;background:#f2f4f8e8;backdrop-filter:blur(18px);border-bottom:1px solid #e6e8ed}.toprow{display:flex;justify-content:space-between;align-items:center;gap:14px}.brand{display:flex;gap:11px;align-items:center}.logo{width:47px;height:47px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-size:22px;font-weight:900;box-shadow:0 10px 24px #5b3df533}.brand h1{font-size:20px;margin:0}.muted{color:var(--muted);font-size:12px;line-height:1.7}.nav{display:flex;gap:7px;flex-wrap:wrap}.nav a{padding:9px 11px;border-radius:12px;text-decoration:none;color:#4b5563;background:#fff;border:1px solid var(--line);font-size:12px;font-weight:700}.nav a.primary{background:var(--soft);color:var(--primary);border-color:#d9d1ff}.hero{display:grid;grid-template-columns:1.35fr .65fr;gap:14px}.heroMain{border-radius:28px;padding:22px;color:#fff;background:linear-gradient(125deg,#111827,#30256f 60%,#5b3df5);box-shadow:0 22px 55px #11182725;min-height:210px;display:flex;flex-direction:column;justify-content:space-between}.heroHead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.heroEyebrow{font-size:11px;letter-spacing:1px;color:#d8d4ff;font-weight:800}.hero h2{font-size:29px;margin:6px 0}.pills{display:flex;gap:7px;flex-wrap:wrap}.pill{padding:7px 9px;border-radius:999px;background:#ffffff18;color:#fff;font-size:11px;font-weight:800;border:1px solid #ffffff15}.heroSide{display:grid;grid-template-columns:1fr 1fr;gap:10px}.kpi{background:#fff;border:1px solid var(--line);border-radius:20px;padding:15px;box-shadow:var(--shadow)}.kpi span{display:block;color:var(--muted);font-size:11px}.kpi b{display:block;margin-top:8px;font-size:19px}.kpi small{display:block;margin-top:5px;color:var(--muted)}.ok{color:var(--green)!important}.bad{color:var(--red)!important}.warn{color:var(--amber)!important}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:18px;margin-top:14px;box-shadow:var(--shadow)}.sectionHead{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:14px}.sectionHead h2{margin:0;font-size:17px}.tag{padding:6px 9px;border-radius:999px;background:var(--soft);color:var(--primary);font-size:10px;font-weight:900}.exchange{position:relative;overflow:hidden}.exchange:after{content:"";position:absolute;width:150px;height:150px;border-radius:50%;left:-70px;top:-70px;background:#5b3df509}.exchangeTop{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.exchangeName{font-size:21px;font-weight:900}.statusRow{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.status{padding:6px 8px;border-radius:999px;font-size:10px;font-weight:800}.status.good{background:var(--greenSoft);color:var(--green)}.status.off{background:#f1f3f6;color:#667085}.status.danger{background:var(--redSoft);color:var(--red)}.capacity{margin-top:14px}.bar{height:9px;background:#edf0f5;border-radius:999px;overflow:hidden}.bar>i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),var(--cyan))}.capacityLabels{display:flex;justify-content:space-between;margin-top:7px;color:var(--muted);font-size:11px}.miniGrid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:12px}.mini{background:#f7f8fb;border-radius:13px;padding:10px}.mini span{font-size:10px;color:var(--muted);display:block}.mini b{display:block;margin-top:5px;font-size:13px}.btn{border:0;border-radius:13px;padding:10px 13px;background:var(--primary);color:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn.gray{background:#626b7e}.btn.safe{background:var(--green)}.btn.danger{background:var(--red)}.btn.soft{background:var(--soft);color:var(--primary)}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}.actions form{margin:0}.intelHero{background:linear-gradient(135deg,#fbfaff,#f0edff);border-color:#ddd5ff}.intelMetrics{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.intelMetric{background:#fff;border:1px solid #e4dfff;border-radius:16px;padding:12px}.intelMetric span{font-size:10px;color:var(--muted);display:block}.intelMetric b{display:block;margin-top:6px;font-size:16px}.notice{padding:12px 13px;border-radius:14px;margin:11px 0}.notice.good{background:var(--greenSoft);color:#087657}.notice.bad{background:var(--redSoft);color:#b42318}.pairBox{background:linear-gradient(135deg,#eef5ff,#f4f0ff);border:1px solid #dae3ff;border-radius:18px;padding:14px;margin-top:12px}.code{font:900 28px monospace;letter-spacing:4px;text-align:center;direction:ltr;margin-bottom:11px}.secret{direction:ltr;word-break:break-all;background:#111827;color:#e5e7eb;padding:12px;border-radius:12px;font-family:monospace;margin-top:9px}.field{padding:10px 12px;border:1px solid #d6dbe4;border-radius:12px;background:#fafbfc;max-width:250px}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:10px;border-bottom:1px solid #eef0f4;text-align:right;white-space:nowrap}th{color:var(--muted);font-weight:700}.healthgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.health{padding:12px;border:1px solid var(--line);border-radius:14px;background:#fafbfc}.health b{display:block;margin-bottom:4px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#9aa5b5;margin-left:6px}.dot.ok{background:var(--green)}.dot.bad{background:var(--red)}.empty{color:var(--muted);text-align:center;padding:18px}.dangerZone{border-color:#ffd6d6;background:linear-gradient(135deg,#fff,#fff5f5)}details summary{cursor:pointer;font-weight:700}.footer{padding:18px 2px;text-align:center;color:var(--muted);font-size:11px}@media(max-width:980px){.hero{grid-template-columns:1fr}.heroSide{grid-template-columns:repeat(4,1fr)}.grid2{grid-template-columns:1fr}.grid3{grid-template-columns:1fr 1fr}.intelMetrics{grid-template-columns:1fr 1fr}}@media(max-width:700px){.app{padding:11px}.topbar{margin:-11px -11px 12px;padding:11px}.toprow{align-items:flex-start}.nav{max-width:55%;justify-content:flex-end}.nav a{padding:8px 9px}.heroMain{min-height:190px;padding:18px}.hero h2{font-size:25px}.heroSide{grid-template-columns:1fr 1fr}.grid3,.healthgrid{grid-template-columns:1fr}.intelMetrics{grid-template-columns:1fr 1fr}.miniGrid{grid-template-columns:1fr 1fr}.actions .btn,.actions form,.actions form .btn{width:100%}.field{max-width:none;width:100%}}@media(max-width:430px){.brand .muted{display:none}.logo{width:42px;height:42px}.brand h1{font-size:17px}.nav{max-width:58%;gap:5px}.nav a{font-size:10px}.heroSide{grid-template-columns:1fr 1fr}.kpi{padding:12px}.intelMetrics{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="app">
    <div class="topbar"><div class="toprow">
        <div class="brand"><div class="logo">T</div><div><h1>Trade Cockpit</h1><div class="muted">Backend v<?=h($version)?> • مرکز کنترل معاملات واقعی</div></div></div>
        <div class="nav">
            <a class="primary" href="/admin/">داشبورد</a>
            <a href="/admin/bot/">ربات</a>
            <a href="/admin/bot/intelligence.php">Intelligence</a>
            <a href="/admin/bot/rotation.php">Rotation</a>
            <a href="/admin/exchanges.php">صرافی‌ها</a>
            <a href="/admin/update/">آپدیت</a>
            <a href="?logout=1">خروج</a>
        </div>
    </div></div>

    <?php if ($message !== ''): ?><div class="notice good"><b><?=h($message)?></b></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice bad"><b><?=h($error)?></b></div><?php endif; ?>

    <section class="hero">
        <div class="heroMain">
            <div class="heroHead">
                <div><div class="heroEyebrow">LIVE TRADING CONTROL</div><h2>موتور معاملات در یک نگاه</h2><div style="color:#d1d5db;line-height:1.8">Nobitex + Bitpin • Portfolio Intelligence v2 • Smart Candidate Fallback</div></div>
                <div class="pill"><?=$kill?'KILL SWITCH ON':'SYSTEM READY'?></div>
            </div>
            <div class="pills">
                <span class="pill">Nobitex <?=onOff((bool)$nobitex['bot_enabled'])?></span>
                <span class="pill">Live <?=onOff((bool)$nobitex['live_execution_enabled'])?></span>
                <span class="pill">Android API <?=AppAccess::activeCount($pdo)>0?'CONNECTED':'WAITING'?></span>
                <span class="pill">v<?=h($version)?></span>
            </div>
        </div>
        <div class="heroSide">
            <div class="kpi"><span>PnL امروز Nobitex</span><b class="<?=((float)($nbPerf['today_realized_pnl']??0)>=0)?'ok':'bad'?>"><?=n($nbPerf['today_realized_pnl']??0,2)?></b><small><?=h($nbPerf['quote_asset']??'IRT')?></small></div>
            <div class="kpi"><span>Win Rate</span><b class="<?=((float)($nbPerf['win_rate_percent']??0)>=50)?'ok':'warn'?>"><?=n($nbPerf['win_rate_percent']??0,1)?>%</b><small>معاملات بسته‌شده</small></div>
            <div class="kpi"><span>پوزیشن فعال</span><b><?=$active?><?=$max>0?'/'.$max:''?></b><small><?=h($nbCap['remaining_position_slots']??0)?> اسلات آزاد</small></div>
            <div class="kpi"><span>Risk Multiplier</span><b class="<?=$intelMultiplier<0.8?'warn':'ok'?>"><?=n($intelMultiplier*100,0)?>%</b><small>Portfolio Intelligence</small></div>
        </div>
    </section>

    <div class="grid2">
        <?php foreach (['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name):
            $e=$exchanges[$key];
            $perf=is_array($e['performance']??null)?$e['performance']:[];
            $cap=is_array($e['portfolio_capacity']??null)?$e['portfolio_capacity']:[];
            $pos=(int)($cap['active_positions']??$e['active_position_count']??0);
            $posMax=(int)($cap['max_positions']??0);
            $capPct=pct((float)$pos,(float)$posMax);
        ?>
        <section class="card exchange">
            <div class="exchangeTop">
                <div><div class="exchangeName"><?=$name?></div><div class="muted">Execution Gateway • <?=h($perf['quote_asset']??($key==='nobitex'?'IRT':'—'))?></div></div>
                <span class="tag"><?=$key==='nobitex'?'PRIMARY':'SECONDARY'?></span>
            </div>
            <div class="statusRow">
                <span class="status <?=$e['credentials_configured']?'good':'danger'?>">API <?=$e['credentials_configured']?'READY':'OFF'?></span>
                <span class="status <?=$e['bot_enabled']?'good':'off'?>">BOT <?=onOff((bool)$e['bot_enabled'])?></span>
                <span class="status <?=$e['live_execution_enabled']?'good':'off'?>">LIVE <?=onOff((bool)$e['live_execution_enabled'])?></span>
            </div>
            <?php if ($key==='nobitex' && $posMax>0): ?>
            <div class="capacity"><div class="bar"><i style="width:<?=$capPct?>%"></i></div><div class="capacityLabels"><span><?=$pos?> پوزیشن فعال</span><span><?=$posMax-$pos?> اسلات آزاد</span></div></div>
            <div class="miniGrid">
                <div class="mini"><span>ورود هوشمند</span><b><?=n($cap['effective_position_percent']??0,1)?>%</b></div>
                <div class="mini"><span>Exposure سقف</span><b><?=n($cap['portfolio_exposure_limit_percent']??0,1)?>%</b></div>
                <div class="mini"><span>Pending</span><b><?=h(($cap['pending_orders']??0).'/'.($cap['max_pending_orders']??0))?></b></div>
            </div>
            <?php else: ?>
            <div class="miniGrid"><div class="mini"><span>Active Positions</span><b><?=h($pos)?></b></div><div class="mini"><span>Total PnL</span><b><?=n($perf['total_realized_pnl']??0,2)?></b></div><div class="mini"><span>Win Rate</span><b><?=n($perf['win_rate_percent']??0,1)?>%</b></div></div>
            <?php endif; ?>
            <div class="actions">
                <a class="btn soft" href="/admin/exchanges.php">مدیریت <?=$name?></a>
                <?php if ($e['credentials_configured']): ?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_exchange"><input type="hidden" name="exchange" value="<?=$key?>"><button class="btn gray">تست API</button></form><?php endif; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>

    <section class="card intelHero">
        <div class="sectionHead"><div><h2>Portfolio Intelligence v2</h2><div class="muted">ریسک تطبیقی بر اساس Drawdown، Losing Streak، Strategy performance و Correlation</div></div><a class="btn soft" href="/admin/bot/intelligence.php">باز کردن مرکز Intelligence</a></div>
        <?php if (isset($intelligence['error'])): ?>
            <div class="notice bad">Intelligence snapshot در دسترس نیست: <?=h($intelligence['error'])?></div>
        <?php else: ?>
        <div class="intelMetrics">
            <div class="intelMetric"><span>Current Drawdown</span><b class="<?=$intelDrawdown>=4?'warn':'ok'?>"><?=n($intelDrawdown,2)?>%</b></div>
            <div class="intelMetric"><span>Position Multiplier</span><b><?=n($intelMultiplier*100,0)?>%</b></div>
            <div class="intelMetric"><span>Realized Samples</span><b><?=$intelSamples?></b></div>
            <div class="intelMetric"><span>Losing Streak</span><b class="<?=$intelStreak>=3?'warn':''?>"><?=$intelStreak?></b></div>
        </div>
        <div class="miniGrid">
            <div class="mini"><span>Correlation Threshold</span><b><?=n($intelligence['correlation_guard']['threshold']??0.86,2)?></b></div>
            <div class="mini"><span>Max Correlated</span><b><?=h($intelligence['correlation_guard']['max_correlated_positions']??2)?></b></div>
            <div class="mini"><span>Fallback</span><b class="ok">Smart v1</b></div>
        </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="sectionHead"><div><h2>System Health</h2><div class="muted">بررسی زنده Backend، دیتابیس، API، Cron و امنیت</div></div><button class="btn gray" onclick="loadHealth()">بررسی دوباره</button></div>
        <div class="healthgrid" id="health"><div class="health">در حال بررسی…</div></div>
    </section>

    <div class="grid2">
        <section class="card">
            <div class="sectionHead"><div><h2>اتصال Android</h2><div class="muted">Pairing یک‌بارمصرف؛ بدون تایپ Token در اپ</div></div><span class="tag"><?=AppAccess::activeCount($pdo)?> DEVICE</span></div>
            <form method="post" class="actions">
                <input type="hidden" name="csrf" value="<?=$csrf?>">
                <input type="hidden" name="action" value="create_pairing">
                <input class="field" name="label" value="Android phone" maxlength="120">
                <button class="btn">ساخت کد اتصال ۱۰ دقیقه‌ای</button>
            </form>
            <?php if ($pairCode !== ''): ?><div class="pairBox"><div class="code"><?=h($pairCode)?></div><a class="btn safe" style="width:100%" href="<?=h($pairLink)?>">اتصال خودکار به اپ</a></div><?php endif; ?>
            <details style="margin-top:14px"><summary>ساخت توکن دستی اضطراری</summary><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="issue_token"><input class="field" name="label" value="Manual Android token"><button class="btn gray">ساخت توکن</button></form><?php if ($issuedToken !== ''): ?><div class="secret"><?=h($issuedToken)?></div><?php endif; ?></details>
        </section>

        <section class="card dangerZone">
            <div class="sectionHead"><div><h2>Kill Switch سراسری</h2><div class="muted">روی سفارش‌های جدید هر دو صرافی اثر می‌گذارد؛ خروج‌های ایمنی بر اساس منطق سرویس مدیریت می‌شوند.</div></div><span class="status <?=$kill?'danger':'good'?>"><?=$kill?'ACTIVE':'OFF'?></span></div>
            <p class="<?=$kill?'bad':'ok'?>"><b><?=$kill?'توقف اضطراری روشن است':'سیستم در حالت عادی است'?></b></p>
            <form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$kill?'0':'1'?>"><button class="btn <?=$kill?'safe':'danger'?>" style="width:100%"><?=$kill?'برداشتن توقف اضطراری':'فعال‌کردن توقف اضطراری'?></button></form>
        </section>
    </div>

    <div class="grid2">
        <section class="card">
            <div class="sectionHead"><h2>دستگاه‌های متصل</h2><span class="tag"><?=count($tokens)?> TOKEN</span></div>
            <div class="scroll"><table><thead><tr><th>نام</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
            <?php if ($tokens === []): ?><tr><td colspan="3" class="empty">دستگاهی ثبت نشده است.</td></tr><?php endif; ?>
            <?php foreach ($tokens as $t): ?><tr><td><?=h($t['label'])?></td><td><span class="status <?=$t['revoked_at']?'off':'good'?>"><?=$t['revoked_at']?'لغوشده':'فعال'?></span></td><td><?php if (!$t['revoked_at']): ?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="<?=h($t['id'])?>"><button class="btn danger">لغو</button></form><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card">
            <div class="sectionHead"><h2>آخرین Cronها</h2><span class="tag">LIVE ENGINE</span></div>
            <div class="scroll"><table><thead><tr><th>Run</th><th>Status</th><th>UTC</th></tr></thead><tbody>
            <?php foreach ($runs as $r): ?><tr><td><?=h(substr((string)$r['run_id'],0,10))?></td><td><?=h($r['status'])?></td><td><?=h($r['started_at'])?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>

    <section class="card">
        <div class="sectionHead"><div><h2>آخرین سفارش‌ها</h2><div class="muted">نمایش وضعیت واقعی Order Ledger</div></div><a class="btn soft" href="/admin/bot/">جزئیات ربات</a></div>
        <div class="scroll"><table><thead><tr><th>Exchange</th><th>ID</th><th>Market</th><th>Side</th><th>Status</th><th>UTC</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr><td><?=h($o['exchange_name'])?></td><td><?=h($o['exchange_order_id']??'-')?></td><td><b><?=h($o['market_code'])?></b></td><td><?=h(strtoupper((string)$o['side']))?></td><td><?=h($o['status'])?></td><td><?=h($o['created_at'])?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <div class="footer">Trade Cockpit • Backend v<?=h($version)?> • UI/UX Cockpit v2</div>
</div>
<script>
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function healthCard(name,x){return `<div class="health"><b><span class="dot ${x.ok?'ok':'bad'}"></span>${name}</b><div class="muted">${esc(x.text)}</div></div>`;}
async function loadHealth(){
    const el=document.getElementById('health');
    try{
        const r=await fetch('?ajax=health',{cache:'no-store',credentials:'same-origin'});
        const j=await r.json(),c=j.checks;
        el.innerHTML=healthCard('Backend',c.backend)+healthCard('Database',c.database)+healthCard('Nobitex',c.nobitex)+healthCard('Bitpin',c.bitpin)+healthCard('Cron',c.cron)+healthCard('HTTPS',c.https)+healthCard('PHP',c.php)+healthCard('Sodium/Ed25519',c.sodium)+healthCard('Android App',c.app_tokens);
    }catch(e){el.innerHTML='<div class="health"><b class="bad">Health check failed</b><div class="muted">امکان دریافت وضعیت زنده وجود نداشت.</div></div>';}
}
loadHealth();
</script>
</body>
</html>
