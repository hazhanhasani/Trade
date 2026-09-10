<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Trading\BotController;
use Trade\Trading\NobitexPortfolioIntelligence;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }

session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$pdo = Database::connection();
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n(mixed $v, int $d=2): string { return number_format((float)$v, $d, '.', ','); }
function onOff(bool $v): string { return $v ? 'ON' : 'OFF'; }
function pct(float $value, float $max): float { return $max > 0 ? max(0, min(100, ($value / $max) * 100)) : 0; }

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
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><title>Trade Cockpit</title><style>
    :root{--ink:#111827;--primary:#6246ea;--primary2:#8068f5;--green:#0b966d;--red:#d04444}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Tahoma,Arial,sans-serif;background:linear-gradient(150deg,#0f172a 0,#22264b 43%,#f2f4f8 43%);color:var(--ink)}.loginWrap{min-height:100vh;display:grid;place-items:center;padding:24px}.login{width:min(430px,100%);background:#fffffffa;border-radius:30px;padding:28px;box-shadow:0 28px 80px #05081655}.logo{width:58px;height:58px;border-radius:19px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-size:27px;font-weight:900}.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--primary);margin-top:18px}.login h1{margin:5px 0 4px;font-size:29px}.muted{color:#6b7280;font-size:13px;line-height:1.8}.secure{display:flex;gap:9px;align-items:center;background:#f1efff;color:#4435a6;border-radius:15px;padding:11px 12px;margin:18px 0}.secure i{width:9px;height:9px;border-radius:50%;background:var(--green)}.field{width:100%;border:1px solid #d7dce5;background:#fafbfc;padding:13px 14px;border-radius:14px;margin-top:9px;outline:none}.loginBtn{width:100%;border:0;border-radius:15px;padding:13px 16px;margin-top:14px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:800;cursor:pointer}.err{background:#fff0f0;color:#b42318;padding:11px 13px;border-radius:13px;margin-top:12px}
    </style></head><body><div class="loginWrap"><div class="login"><div class="logo">T</div><div class="eyebrow">SECURE TRADING CONTROL</div><h1>Trade Cockpit</h1><div class="muted">ورود به پنل مدیریت معاملات خودکار</div><div class="secure"><i></i><b>Admin Session • HTTPS • SameSite Strict</b></div><?php if($error!==''):?><div class="err"><?=h($error)?></div><?php endif?><form method="post"><input class="field" name="username" placeholder="نام کاربری" required autocomplete="username"><input class="field" type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password"><button class="loginBtn">ورود امن</button></form></div></div></body></html><?php
    exit;
}

NobitexSchema::ensure();
AppAccess::bootstrapLegacy($pdo);
AppAccess::cleanup($pdo);

$controller = new BotController();
$status = $controller->status();
$exchanges = $status['exchanges'];
$version = Updater::currentVersion();
$kill = (bool)$status['kill_switch'];
$cron = is_array($status['cron_health'] ?? null) ? $status['cron_health'] : [];
$deviceCount = AppAccess::activeCount($pdo);
$orders = $pdo->query('SELECT exchange_name,exchange_order_id,market_code,side,status,created_at FROM orders ORDER BY id DESC LIMIT 12')->fetchAll();
$runs = $pdo->query('SELECT run_id,status,started_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
$orderCountToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE created_at >= UTC_DATE()")->fetchColumn();

try { $intelligence = (new NobitexPortfolioIntelligence())->snapshot($pdo); }
catch (Throwable $e) { $intelligence = ['error'=>mb_substr($e->getMessage(),0,240)]; }

$nobitex = $exchanges['nobitex'];
$bitpin = $exchanges['bitpin'];
$nbPerf = is_array($nobitex['performance'] ?? null) ? $nobitex['performance'] : [];
$bpPerf = is_array($bitpin['performance'] ?? null) ? $bitpin['performance'] : [];
$nbCap = is_array($nobitex['portfolio_capacity'] ?? null) ? $nobitex['portfolio_capacity'] : [];
$active = (int)($nbCap['active_positions'] ?? $nobitex['active_position_count'] ?? 0);
$max = max(0, (int)($nbCap['max_positions'] ?? 0));
$pending = (int)($nbCap['pending_orders'] ?? 0);
$capPct = pct((float)$active, (float)$max);
$drawdown = (float)($intelligence['drawdown']['current_drawdown_percent'] ?? 0);
$multiplier = (float)($intelligence['position_size_multiplier'] ?? 1);
$samples = (int)($intelligence['realized_samples'] ?? 0);
$streak = (int)($intelligence['drawdown']['losing_streak'] ?? 0);
$cronHealthy = (bool)($cron['healthy'] ?? false);
$cronAge = isset($cron['age_seconds']) ? (int)$cron['age_seconds'] : null;

require __DIR__ . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><title>داشبورد — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('dashboard', $version); ?>
<div class="page-head"><div><div class="page-eyebrow">OVERVIEW / READ ONLY</div><h1>داشبورد</h1><p>این صفحه فقط برای آمار و وضعیت لحظه‌ای است؛ هیچ تنظیم یا کنترل عملیاتی در داشبورد انجام نمی‌شود.</p></div></div>

<section class="hero-panel"><div class="page-eyebrow" style="color:#d8d4ff">PORTFOLIO SNAPSHOT</div><h2>وضعیت معاملات در یک نگاه</h2><p>Nobitex + Bitpin • Portfolio Intelligence • Smart Candidate Fallback</p><div class="hero-pills"><span class="hero-pill">Nobitex Bot <?=onOff((bool)$nobitex['bot_enabled'])?></span><span class="hero-pill">Live <?=onOff((bool)$nobitex['live_execution_enabled'])?></span><span class="hero-pill">Cron <?=$cronHealthy?'HEALTHY':'CHECK'?></span><span class="hero-pill">Kill Switch <?=$kill?'ON':'OFF'?></span><span class="hero-pill">Backend v<?=h($version)?></span></div></section>

<div class="stat-grid">
<div class="stat-card"><span>PnL امروز Nobitex</span><b class="<?=((float)($nbPerf['today_realized_pnl']??0)>=0)?'ok':'bad-text'?>"><?=n($nbPerf['today_realized_pnl']??0,2)?></b><small><?=h($nbPerf['quote_asset']??'IRT')?></small></div>
<div class="stat-card"><span>PnL کل Nobitex</span><b class="<?=((float)($nbPerf['total_realized_pnl']??0)>=0)?'ok':'bad-text'?>"><?=n($nbPerf['total_realized_pnl']??0,2)?></b><small><?=h($nbPerf['quote_asset']??'IRT')?></small></div>
<div class="stat-card"><span>Win Rate</span><b><?=n($nbPerf['win_rate_percent']??0,1)?>%</b><small>معاملات بسته‌شده</small></div>
<div class="stat-card"><span>پوزیشن فعال</span><b><?=$active?><?=$max>0?'/'.$max:''?></b><small><?=h($nbCap['remaining_position_slots']??0)?> اسلات آزاد</small></div>
<div class="stat-card"><span>سفارش امروز</span><b><?=$orderCountToday?></b><small>Order Ledger</small></div>
<div class="stat-card"><span>Pending</span><b><?=$pending?>/<?=h($nbCap['max_pending_orders']??0)?></b><small>Watchdog <?=h($nbCap['pending_timeout_seconds']??60)?>s</small></div>
<div class="stat-card"><span>Risk Multiplier</span><b class="<?=$multiplier<0.8?'warn-text':'ok'?>"><?=n($multiplier*100,0)?>%</b><small>Portfolio Intelligence</small></div>
<div class="stat-card"><span>Android Devices</span><b><?=$deviceCount?></b><small>اتصال فعال</small></div>
</div>

<div class="panel-grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name): $e=$exchanges[$key]; $perf=is_array($e['performance']??null)?$e['performance']:[]; $cap=is_array($e['portfolio_capacity']??null)?$e['portfolio_capacity']:[]; ?>
<section class="panel"><div class="panel-head"><div><h2><?=$name?></h2><p><?=$key==='nobitex'?'Primary live execution gateway':'Secondary exchange gateway'?></p></div><span class="badge <?=$e['credentials_configured']?'good':'bad'?>">API <?=$e['credentials_configured']?'READY':'OFF'?></span></div><div class="status-line"><span class="badge <?=$e['bot_enabled']?'good':'bad'?>">BOT <?=onOff((bool)$e['bot_enabled'])?></span><span class="badge <?=$e['live_execution_enabled']?'good':'bad'?>">LIVE <?=onOff((bool)$e['live_execution_enabled'])?></span></div><div class="metric-grid" style="margin-top:12px"><div class="metric"><span>Active Positions</span><b><?=h($e['active_position_count']??0)?></b></div><div class="metric"><span>Today PnL</span><b><?=n($perf['today_realized_pnl']??0,2)?></b></div><div class="metric"><span>Win Rate</span><b><?=n($perf['win_rate_percent']??0,1)?>%</b></div></div><?php if($key==='nobitex'&&$max>0):?><div style="margin-top:13px"><div class="progress"><i style="width:<?=$capPct?>%"></i></div><div class="progress-labels"><span><?=$active?> پوزیشن فعال</span><span><?=h($cap['remaining_position_slots']??0)?> اسلات آزاد</span></div></div><?php endif?></section>
<?php endforeach; ?>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>Portfolio Intelligence</h2><p>خلاصه آماری ریسک تطبیقی؛ تنظیمات Intelligence در ماژول مستقل خودش قرار دارد.</p></div><span class="badge info">READ ONLY</span></div><?php if(isset($intelligence['error'])):?><div class="notice bad">Snapshot در دسترس نیست: <?=h($intelligence['error'])?></div><?php else:?><div class="stat-grid" style="margin-top:0"><div class="stat-card"><span>Current Drawdown</span><b class="<?=$drawdown>=4?'warn-text':'ok'?>"><?=n($drawdown,2)?>%</b></div><div class="stat-card"><span>Position Multiplier</span><b><?=n($multiplier*100,0)?>%</b></div><div class="stat-card"><span>Realized Samples</span><b><?=$samples?></b></div><div class="stat-card"><span>Losing Streak</span><b class="<?=$streak>=3?'warn-text':''?>"><?=$streak?></b></div></div><?php endif?></section>

<div class="panel-grid">
<section class="panel"><div class="panel-head"><div><h2>آخرین سفارش‌ها</h2><p>۱۲ رکورد اخیر Order Ledger</p></div><span class="badge info"><?=$orderCountToday?> TODAY</span></div><div class="table-wrap"><table><thead><tr><th>Exchange</th><th>Market</th><th>Side</th><th>Status</th><th>UTC</th></tr></thead><tbody><?php if($orders===[]):?><tr><td colspan="5" class="empty">هنوز سفارشی ثبت نشده است.</td></tr><?php endif?><?php foreach($orders as $o):?><tr><td><?=h($o['exchange_name'])?></td><td><b><?=h($o['market_code'])?></b></td><td><?=h(strtoupper((string)$o['side']))?></td><td><?=h($o['status'])?></td><td><?=h($o['created_at'])?></td></tr><?php endforeach?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><h2>آخرین اجراهای Cron</h2><p>فقط وضعیت اجرای موتور؛ کنترل Cron در بخش سیستم است.</p></div><span class="badge <?=$cronHealthy?'good':'bad'?>"><?=$cronHealthy?'HEALTHY':'CHECK'?></span></div><div class="table-wrap"><table><thead><tr><th>Run</th><th>Status</th><th>UTC</th></tr></thead><tbody><?php if($runs===[]):?><tr><td colspan="3" class="empty">هنوز اجرایی ثبت نشده است.</td></tr><?php endif?><?php foreach($runs as $r):?><tr><td><?=h(substr((string)$r['run_id'],0,12))?></td><td><?=h($r['status'])?></td><td><?=h($r['started_at'])?></td></tr><?php endforeach?></tbody></table></div></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>System Snapshot</h2><p>شاخص‌های زیر فقط وضعیت را نمایش می‌دهند و هیچ عملیاتی اجرا نمی‌کنند.</p></div><span class="badge info">STATUS</span></div><div class="metric-grid"><div class="metric"><span>Backend</span><b>v<?=h($version)?></b></div><div class="metric"><span>Database</span><b class="ok">CONNECTED</b></div><div class="metric"><span>Cron</span><b class="<?=$cronHealthy?'ok':'bad-text'?>"><?=$cronHealthy?'HEALTHY':'CHECK'?></b><small class="muted"><?=$cronAge===null?'بدون سابقه':$cronAge.' ثانیه از آخرین اجرا'?></small></div><div class="metric"><span>HTTPS</span><b class="<?=!empty($_SERVER['HTTPS'])?'ok':'warn-text'?>"><?=!empty($_SERVER['HTTPS'])?'ON':'CHECK'?></b></div><div class="metric"><span>Kill Switch</span><b class="<?=$kill?'bad-text':'ok'?>"><?=$kill?'ACTIVE':'OFF'?></b></div><div class="metric"><span>PHP</span><b><?=h(PHP_VERSION)?></b></div></div></section>

<?php tradeAdminFooter($version); ?>
</div></body></html>