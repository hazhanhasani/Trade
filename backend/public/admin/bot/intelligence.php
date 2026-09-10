<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexPortfolioIntelligence;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function f(mixed $value, int $digits=2): string { return is_numeric($value) ? number_format((float)$value, $digits, '.', ',') : '—'; }

try { NobitexSchema::ensure(); $intel=(new NobitexPortfolioIntelligence())->snapshot(); $error=''; }
catch (Throwable $e) { $intel=[]; $error=mb_substr($e->getMessage(),0,800); }

$dd=is_array($intel['drawdown']??null)?$intel['drawdown']:[];
$corr=is_array($intel['correlation_guard']??null)?$intel['correlation_guard']:[];
$strategies=is_array($intel['strategy_performance']??null)?$intel['strategy_performance']:[];
$mult=(float)($intel['position_size_multiplier']??1.0);$multPct=$mult*100.0;$stateClass=$mult>=0.95?'good':($mult>=0.75?'warn':'bad');
$version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>ریسک هوشمند — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.riskbar{height:10px;background:#e9ecf3;border-radius:999px;overflow:hidden;margin-top:12px;direction:ltr}.riskbar i{display:block;height:100%;background:linear-gradient(90deg,#cf3f4b,#ad6900,#0b936c);border-radius:999px}details.help-box{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 12px}details.help-box summary{cursor:pointer;font-weight:800;font-size:11px}details.help-box p{color:var(--muted);font-size:10px;line-height:1.9;margin:9px 0 0}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('intelligence',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">کنترل خودکار ریسک</div><h1>مدیریت ریسک هوشمند</h1><p>این بخش فقط توضیح می‌دهد ربات بر اساس افت سرمایه، عملکرد واقعی معاملات و شباهت حرکت ارزها چقدر حجم خریدهای بعدی را کم می‌کند.</p></div><span class="badge <?=$stateClass?>">حجم خرید بعدی <?=f($multPct,0)?>٪</span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a class="active" href="/admin/bot/intelligence.php">ریسک هوشمند</a><a href="/admin/bot/rotation.php">تعویض فرصت‌ها</a><a href="/admin/tradingview.php">سیگنال TradingView</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>ریسک فعلی ربات</h2><p>آخرین محاسبه: <?=h($intel['generated_at']??'—')?> • یادگیری فقط از معاملات بسته‌شده و سود/زیان خالص واقعی انجام می‌شود.</p></div><span class="badge <?=$stateClass?>">ضریب حجم <?=f($multPct,0)?>٪</span></div><div class="stat-grid"><div class="stat-card"><span>حجم خرید بعدی نسبت به حالت عادی</span><b><?=f($multPct,0)?>٪</b></div><div class="stat-card"><span>افت فعلی سرمایه از آخرین اوج</span><b><?=f($dd['current_drawdown_percent']??null)?>٪</b></div><div class="stat-card"><span>بیشترین افت ثبت‌شده</span><b><?=f($dd['max_drawdown_percent']??null)?>٪</b></div><div class="stat-card"><span>تعداد زیان‌های پشت‌سرهم</span><b><?=h($dd['losing_streak']??0)?></b></div></div><div class="riskbar"><i style="width:<?=h(max(0,min(100,$multPct)))?>%"></i></div><div class="notice info">این سیستم هیچ‌وقت حجم تعیین‌شده را بیشتر نمی‌کند؛ اگر عملکرد ضعیف شود یا افت سرمایه بالا برود، فقط حجم خریدهای جدید را کاهش می‌دهد.</div></section>

<section class="panel"><div class="panel-head"><div><h2>جلوگیری از تمرکز بیش‌ازحد</h2><p>قبل از خرید، ربات بررسی می‌کند تعداد زیادی ارز با رفتار بسیار شبیه به هم وارد پورتفو نشده باشند.</p></div><span class="badge info">محافظ تنوع</span></div><div class="stat-grid"><div class="stat-card"><span>معاملات باز</span><b><?=h($intel['active_positions']??0)?></b></div><div class="stat-card"><span>معاملات تومان / تتر</span><b><?=h(($intel['active_by_quote']['IRT']??0).' / '.($intel['active_by_quote']['USDT']??0))?></b></div><div class="stat-card"><span>حد شباهت حرکت ارزها</span><b><?=f($corr['threshold']??null,2)?></b></div><div class="stat-card"><span>حداکثر معاملات خیلی مشابه</span><b><?=h($corr['max_correlated_positions']??'—')?></b></div></div><div class="metric-grid" style="margin-top:10px"><div class="metric"><span>حداقل داده لازم برای مقایسه</span><b><?=h($corr['minimum_samples']??'—')?></b></div><div class="metric"><span>بازه زمانی بررسی</span><b><?=h($corr['lookback_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>معاملات بسته بررسی‌شده</span><b><?=h($intel['realized_samples']??0)?></b></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>یادگیری از عملکرد واقعی</h2><p>ربات بررسی می‌کند کدام شرایط ورود در معاملات قبلی بهتر یا بدتر نتیجه داده‌اند. تا وقتی حداقل ۵ نمونه وجود نداشته باشد، وزن آن الگو را تغییر نمی‌دهد.</p></div><span class="badge info"><?=count($strategies)?> الگو</span></div><?php if($strategies===[]):?><div class="empty">هنوز تعداد معامله بسته‌شده برای یادگیری کافی نیست؛ ربات فعلاً وزن خنثی استفاده می‌کند.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>نوع استراتژی</th><th>شرایط بازار</th><th>تعداد معامله</th><th>موفقیت</th><th>میانگین بازده</th><th>نسبت سود به زیان</th><th>ضریب حجم</th><th>وضعیت یادگیری</th></tr></thead><tbody><?php foreach($strategies as $s):?><tr><td><?=h($s['strategy_key']??'—')?></td><td><?=h($s['profile_key']??'—')?></td><td><?=h($s['trades']??0)?></td><td><?=f(((float)($s['win_rate']??0))*100,1)?>٪</td><td class="<?=((float)($s['average_return_percent']??0)>=0)?'ok':'bad-text'?>"><?=f($s['average_return_percent']??null,3)?>٪</td><td><?=f($s['return_profit_factor']??null,2)?></td><td><?=f(((float)($s['size_multiplier']??1))*100,0)?>٪</td><td><?=($s['learning_ready']??false)?'فعال':'نمونه کم'?></td></tr><?php endforeach?></tbody></table></div><?php endif?>
<details class="help-box"><summary>این اعداد را چطور بخوانم؟</summary><p><b>افت سرمایه از اوج</b> یعنی سرمایه نسبت به بالاترین مقدار اخیر چقدر پایین آمده است. <b>ضریب حجم</b> اگر ۸۰٪ باشد یعنی خرید بعدی ۲۰٪ کوچک‌تر از حالت عادی انجام می‌شود. <b>شباهت حرکت ارزها</b> برای این است که چند ارز بسیار مشابه هم‌زمان ریسک یکسانی به پورتفو وارد نکنند.</p></details></section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>