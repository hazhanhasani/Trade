<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Support\IranClock;
use Trade\Trading\BotController;
use Trade\Trading\NobitexPortfolioIntelligence;
use Trade\Trading\NobitexPortfolioSnapshotCache;
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
function faDigits(string $value): string { return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹','.'=>'٫',','=>'٬','%'=>'٪']); }
function n(mixed $v, int $d=2): string { return faDigits(number_format((float)$v, $d, '.', ',')); }
function onOff(bool $v): string { return $v ? 'ON' : 'OFF'; }
function pct(float $value, float $max): float { return $max > 0 ? max(0, min(100, ($value / $max) * 100)) : 0; }
function iranUtc(mixed $value): string { try { return IranClock::formatUtc((string)$value); } catch (Throwable) { return faDigits((string)$value); } }

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
$exchanges = is_array($status['exchanges'] ?? null) ? $status['exchanges'] : [];
$version = Updater::currentVersion();
$kill = (bool)($status['kill_switch'] ?? false);
$cron = is_array($status['cron_health'] ?? null) ? $status['cron_health'] : [];
$deviceCount = AppAccess::activeCount($pdo);
$orders = $pdo->query('SELECT exchange_name,exchange_order_id,market_code,side,status,created_at FROM orders ORDER BY id DESC LIMIT 12')->fetchAll();
$runs = $pdo->query('SELECT run_id,status,started_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();
[$todayStart,$todayEnd] = IranClock::todayUtcRange();
$orderStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE created_at>=:start AND created_at<:end');
$orderStmt->execute([':start'=>$todayStart,':end'=>$todayEnd]);
$orderCountToday = (int)$orderStmt->fetchColumn();

try { $intelligence = (new NobitexPortfolioIntelligence())->snapshot($pdo); }
catch (Throwable $e) { $intelligence = ['error'=>mb_substr($e->getMessage(),0,240)]; }
try { $portfolioTruth = (new NobitexPortfolioSnapshotCache())->snapshot($pdo); }
catch (Throwable $e) { $portfolioTruth = ['status'=>'deferred','reason'=>'valuation_failed','message'=>mb_substr($e->getMessage(),0,240)]; }

$nobitex = is_array($exchanges['nobitex'] ?? null) ? $exchanges['nobitex'] : [];
$nbPerf = is_array($nobitex['performance'] ?? null) ? $nobitex['performance'] : [];
$perfIrt = is_array($nbPerf['by_quote']['IRT'] ?? null)
    ? $nbPerf['by_quote']['IRT']
    : (((string)($nbPerf['quote_asset'] ?? '') === 'IRT' || (string)($nbPerf['display_unit'] ?? '') === 'TOMAN') ? $nbPerf : []);
$nbCap = is_array($nobitex['portfolio_capacity'] ?? null) ? $nobitex['portfolio_capacity'] : [];
$active = (int)($nbCap['active_positions'] ?? $nobitex['active_position_count'] ?? 0);
$max = max(0, (int)($nbCap['max_positions'] ?? 0));
$pending = (int)($nbCap['pending_orders'] ?? 0);
$remaining = (int)($nbCap['remaining_position_slots'] ?? max(0,$max-$active));
$capPct = pct((float)$active, (float)$max);
$drawdown = (float)($intelligence['drawdown']['current_drawdown_percent'] ?? 0);
$multiplier = (float)($intelligence['position_size_multiplier'] ?? 1);
$samples = (int)($intelligence['realized_samples'] ?? 0);
$streak = (int)($intelligence['drawdown']['losing_streak'] ?? 0);
$cronHealthy = (bool)($cron['healthy'] ?? false);
$cronAge = isset($cron['age_seconds']) ? (int)$cron['age_seconds'] : null;

$walletTotal = is_numeric($portfolioTruth['wallet_total_toman'] ?? null) ? (float)$portfolioTruth['wallet_total_toman'] : null;
$cashAvailable = is_numeric($portfolioTruth['available_cash_by_quote']['IRT'] ?? null)
    ? (float)$portfolioTruth['available_cash_by_quote']['IRT']
    : null;
if ($cashAvailable === null) {
    $fallback = 0.0; $found = false;
    foreach ((array)($portfolioTruth['wallet_assets'] ?? []) as $assetRow) {
        if (!is_array($assetRow)) continue;
        if (!in_array(strtoupper((string)($assetRow['asset'] ?? '')), ['RLS','IRT'], true)) continue;
        if (!is_numeric($assetRow['available_value_toman'] ?? null)) continue;
        $fallback += (float)$assetRow['available_value_toman']; $found = true;
    }
    if ($found) $cashAvailable = $fallback;
}
$exposurePercent = is_numeric($portfolioTruth['exposure_percent'] ?? null) ? (float)$portfolioTruth['exposure_percent'] : null;
$cacheState = (string)($portfolioTruth['cache_state'] ?? $portfolioTruth['status'] ?? 'unknown');
$cacheAge = is_numeric($portfolioTruth['cache_age_seconds'] ?? null) ? (int)$portfolioTruth['cache_age_seconds'] : null;
$pnlToday = (float)($perfIrt['today_realized_pnl'] ?? 0);
$winRate = (float)($perfIrt['win_rate_percent'] ?? 0);
$closedPositions = (int)($perfIrt['closed_positions'] ?? 0);

require __DIR__ . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><title>داشبورد — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('dashboard', $version); ?>
<div class="page-head"><div><div class="page-eyebrow">OVERVIEW / READ ONLY</div><h1>داشبورد</h1><p>این صفحه فقط برای آمار و وضعیت لحظه‌ای است؛ هیچ تنظیم یا کنترل عملیاتی در داشبورد انجام نمی‌شود.</p></div></div>

<section class="hero-panel"><div class="page-eyebrow" style="color:#d8d4ff">PORTFOLIO SNAPSHOT</div><h2>وضعیت معاملات در یک نگاه</h2><p>Nobitex Execution • Multi-source Market Data • Portfolio Intelligence</p><div class="hero-pills"><span class="hero-pill">Nobitex Bot <?=onOff((bool)($nobitex['bot_enabled']??false))?></span><span class="hero-pill">Live <?=onOff((bool)($nobitex['live_execution_enabled']??false))?></span><span class="hero-pill">Cron <?=$cronHealthy?'HEALTHY':'CHECK'?></span><span class="hero-pill">Kill Switch <?=$kill?'ON':'OFF'?></span><span class="hero-pill">Backend v<?=h($version)?></span></div></section>

<div class="stat-grid">
<div class="stat-card live-truth-card"><span>ارزش کیف پول نوبیتکس</span><b><?=$walletTotal!==null?n($walletTotal,0):'—'?></b><small><?=$walletTotal!==null?'تومان • '.h($cacheState).' • '.($cacheAge!==null?n($cacheAge,0).' ثانیه':'—'):'داده معتبر کیف پول در دسترس نیست'?></small></div>
<div class="stat-card live-truth-card"><span>نقد قابل معامله نوبیتکس</span><b><?=$cashAvailable!==null?n($cashAvailable,0):'—'?></b><small>تومان • موجودی آزاد RLS/IRT در نوبیتکس</small></div>
<div class="stat-card live-truth-card"><span>PnL امروز ربات</span><b class="<?=$pnlToday>=0?'ok':'bad-text'?>"><?=n($pnlToday,0)?></b><small>تومان • PnL تحقق‌یافته دفتر معاملات Trade</small></div>
<div class="stat-card live-truth-card"><span>پوزیشن فعال ربات</span><b><?=$max>0?n($active,0).'/'.n($max,0):n($active,0)?></b><small><?=$max>0&&$active>=$max?'ظرفیت تکمیل؛ خرید جدید تا آزاد شدن اسلات متوقف است':n($remaining,0).' اسلات آزاد'?></small></div>
<div class="stat-card live-truth-card"><span>سفارش امروز Trade</span><b><?=n($orderCountToday,0)?></b><small>Order Ledger • روز جاری ایران</small></div>
<div class="stat-card live-truth-card"><span>Pending</span><b><?=n($pending,0)?>/<?=n($nbCap['max_pending_orders']??0,0)?></b><small>Watchdog <?=n($nbCap['pending_timeout_seconds']??60,0)?> ثانیه</small></div>
<div class="stat-card live-truth-card"><span>اکسپوژر ربات</span><b><?=$exposurePercent!==null?n($exposurePercent,1).'٪':'—'?></b><small>بر مبنای ارزش کامل کیف پول نوبیتکس</small></div>
<div class="stat-card live-truth-card"><span>Win Rate ربات</span><b><?=n($winRate,1)?>٪</b><small><?=n($closedPositions,0)?> معامله بسته‌شده</small></div>
</div>

<div class="panel-grid">
<section class="panel"><div class="panel-head"><div><h2>Nobitex</h2><p>Primary live execution gateway</p></div><span class="badge <?=($nobitex['credentials_configured']??false)?'good':'bad'?>">API <?=($nobitex['credentials_configured']??false)?'READY':'OFF'?></span></div><div class="status-line"><span class="badge <?=($nobitex['bot_enabled']??false)?'good':'bad'?>">BOT <?=onOff((bool)($nobitex['bot_enabled']??false))?></span><span class="badge <?=($nobitex['live_execution_enabled']??false)?'good':'bad'?>">LIVE <?=onOff((bool)($nobitex['live_execution_enabled']??false))?></span></div><div class="metric-grid" style="margin-top:12px"><div class="metric"><span>Active Positions</span><b><?=n($nobitex['active_position_count']??0,0)?></b></div><div class="metric"><span>Today PnL</span><b><?=n($perfIrt['today_realized_pnl']??0,2)?></b></div><div class="metric"><span>Win Rate</span><b><?=n($perfIrt['win_rate_percent']??0,1)?>٪</b></div></div><?php if($max>0):?><div style="margin-top:13px"><div class="progress"><i style="width:<?=round($capPct,2)?>%"></i></div><div class="progress-labels"><span><?=n($active,0)?> پوزیشن فعال</span><span><?=n($nbCap['remaining_position_slots']??0,0)?> اسلات آزاد</span></div></div><?php endif?></section>
<section class="panel"><div class="panel-head"><div><h2>Market Data</h2><p>Bitpin، آبان‌تتر، بیت۲۴ و تبدیل فقط ورودی تحلیلی هستند.</p></div><span class="badge info">READ ONLY</span></div><div class="status-line"><span class="badge info">NO WALLET</span><span class="badge info">NO ORDER</span><span class="badge info">NO LIVE EXECUTION</span></div><div class="metric-grid" style="margin-top:12px"><div class="metric"><span>Execution Venue</span><b>Nobitex only</b></div><div class="metric"><span>External Sources</span><b>4</b></div><div class="metric"><span>Role</span><b>Consensus</b></div></div></section>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>Portfolio Intelligence</h2><p>خلاصه آماری ریسک تطبیقی؛ تنظیمات Intelligence در ماژول مستقل خودش قرار دارد.</p></div><span class="badge info">READ ONLY</span></div><?php if(isset($intelligence['error'])):?><div class="notice bad">Snapshot در دسترس نیست: <?=h($intelligence['error'])?></div><?php else:?><div class="stat-grid" style="margin-top:0"><div class="stat-card"><span>Current Drawdown</span><b class="<?=$drawdown>=4?'warn-text':'ok'?>"><?=n($drawdown,2)?>٪</b></div><div class="stat-card"><span>Position Multiplier</span><b><?=n($multiplier*100,0)?>٪</b></div><div class="stat-card"><span>Realized Samples</span><b><?=n($samples,0)?></b></div><div class="stat-card"><span>Losing Streak</span><b class="<?=$streak>=3?'warn-text':''?>"><?=n($streak,0)?></b></div></div><?php endif?></section>

<div class="panel-grid">
<section class="panel"><div class="panel-head"><div><h2>آخرین سفارش‌ها</h2><p>۱۲ رکورد اخیر Order Ledger</p></div><span class="badge info"><?=n($orderCountToday,0)?> TODAY</span></div><div class="table-wrap"><table><thead><tr><th>Exchange</th><th>Market</th><th>Side</th><th>Status</th><th>زمان ایران</th></tr></thead><tbody><?php if($orders===[]):?><tr><td colspan="5" class="empty">هنوز سفارشی ثبت نشده است.</td></tr><?php endif?><?php foreach($orders as $o):?><tr><td><?=h($o['exchange_name'])?></td><td><b><?=h($o['market_code'])?></b></td><td><?=h(strtoupper((string)$o['side']))?></td><td><?=h($o['status'])?></td><td data-no-date-localize><?=h(iranUtc($o['created_at']))?></td></tr><?php endforeach?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><h2>آخرین اجراهای Cron</h2><p>فقط وضعیت اجرای موتور؛ کنترل Cron در بخش سیستم است.</p></div><span class="badge <?=$cronHealthy?'good':'bad'?>"><?=$cronHealthy?'HEALTHY':'CHECK'?></span></div><div class="table-wrap"><table><thead><tr><th>Run</th><th>Status</th><th>زمان ایران</th></tr></thead><tbody><?php if($runs===[]):?><tr><td colspan="3" class="empty">هنوز اجرایی ثبت نشده است.</td></tr><?php endif?><?php foreach($runs as $r):?><tr><td><?=h(substr((string)$r['run_id'],0,12))?></td><td><?=h($r['status'])?></td><td data-no-date-localize><?=h(iranUtc($r['started_at']))?></td></tr><?php endforeach?></tbody></table></div></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>System Snapshot</h2><p>شاخص‌های زیر فقط وضعیت را نمایش می‌دهند و هیچ عملیاتی اجرا نمی‌کنند.</p></div><span class="badge info">STATUS</span></div><div class="metric-grid"><div class="metric"><span>Backend</span><b>v<?=h($version)?></b></div><div class="metric"><span>Database</span><b class="ok">CONNECTED</b></div><div class="metric"><span>Cron</span><b class="<?=$cronHealthy?'ok':'bad-text'?>"><?=$cronHealthy?'HEALTHY':'CHECK'?></b><small class="muted"><?=$cronAge===null?'بدون سابقه':n($cronAge,0).' ثانیه از آخرین اجرا'?></small></div><div class="metric"><span>HTTPS</span><b class="<?=!empty($_SERVER['HTTPS'])?'ok':'warn-text'?>"><?=!empty($_SERVER['HTTPS'])?'ON':'CHECK'?></b></div><div class="metric"><span>Kill Switch</span><b class="<?=$kill?'bad-text':'ok'?>"><?=$kill?'ACTIVE':'OFF'?></b></div><div class="metric"><span>PHP</span><b><?=h(PHP_VERSION)?></b></div></div></section>

<?php tradeAdminFooter($version); ?>
</div></body></html>
