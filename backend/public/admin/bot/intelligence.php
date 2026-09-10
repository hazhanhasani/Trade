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
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>Intelligence — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.riskbar{height:10px;background:#e9ecf3;border-radius:999px;overflow:hidden;margin-top:12px;direction:ltr}.riskbar i{display:block;height:100%;background:linear-gradient(90deg,#cf3f4b,#ad6900,#0b936c);border-radius:999px}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('intelligence',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">RISK / INTELLIGENCE</div><h1>Portfolio Intelligence</h1><p>Drawdown-aware sizing، Correlation Guard و یادگیری محافظه‌کارانه از Net PnL واقعی.</p></div><span class="badge <?=$stateClass?>">SIZE <?=f($multPct,0)?>%</span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a class="active" href="/admin/bot/intelligence.php">Intelligence</a><a href="/admin/bot/rotation.php">Rotation</a><a href="/admin/tradingview.php">TradingView</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>Risk Intelligence</h2><p>آخرین محاسبه <?=h($intel['generated_at']??'—')?> • داده یادگیری فقط از معاملات بسته و Fee-aware</p></div><span class="badge <?=$stateClass?>">MULTIPLIER <?=f($multPct,0)?>%</span></div><div class="stat-grid"><div class="stat-card"><span>ضریب ورود بعدی</span><b><?=f($multPct,0)?>%</b></div><div class="stat-card"><span>Drawdown فعلی</span><b><?=f($dd['current_drawdown_percent']??null)?>%</b></div><div class="stat-card"><span>Max Drawdown</span><b><?=f($dd['max_drawdown_percent']??null)?>%</b></div><div class="stat-card"><span>زیان متوالی</span><b><?=h($dd['losing_streak']??0)?></b></div></div><div class="riskbar"><i style="width:<?=h(max(0,min(100,$multPct)))?>%"></i></div><div class="notice info">این ضریب اندازه ریسک تنظیم‌شده را افزایش نمی‌دهد؛ در Drawdown یا عملکرد ضعیف فقط حجم ورودهای جدید کاهش پیدا می‌کند.</div></section>

<section class="panel"><div class="panel-head"><div><h2>Portfolio & Correlation Guard</h2><p>تنوع پورتفو قبل از BUY خودکار بررسی می‌شود.</p></div><span class="badge info">GUARD</span></div><div class="stat-grid"><div class="stat-card"><span>پوزیشن فعال</span><b><?=h($intel['active_positions']??0)?></b></div><div class="stat-card"><span>IRT / USDT</span><b><?=h(($intel['active_by_quote']['IRT']??0).' / '.($intel['active_by_quote']['USDT']??0))?></b></div><div class="stat-card"><span>Correlation Threshold</span><b><?=f($corr['threshold']??null,2)?></b></div><div class="stat-card"><span>حداکثر همبستگی مجاز</span><b><?=h($corr['max_correlated_positions']??'—')?></b></div></div><div class="metric-grid" style="margin-top:10px"><div class="metric"><span>Minimum Samples</span><b><?=h($corr['minimum_samples']??'—')?></b></div><div class="metric"><span>Lookback</span><b><?=h($corr['lookback_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>Realized Samples</span><b><?=h($intel['realized_samples']??0)?></b></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>Strategy Learning</h2><p>Profileها از شرایط واقعی لحظه ورود ساخته می‌شوند؛ کمتر از ۵ معامله کاهش وزنی ایجاد نمی‌کند.</p></div><span class="badge info"><?=count($strategies)?> PROFILE</span></div><?php if($strategies===[]):?><div class="empty">هنوز نمونه بسته‌شده کافی وجود ندارد؛ وزن Strategy خنثی است.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Strategy</th><th>Profile</th><th>Trades</th><th>Win Rate</th><th>Avg Return</th><th>Profit Factor</th><th>Size Weight</th><th>Learning</th></tr></thead><tbody><?php foreach($strategies as $s):?><tr><td><?=h($s['strategy_key']??'—')?></td><td><?=h($s['profile_key']??'—')?></td><td><?=h($s['trades']??0)?></td><td><?=f(((float)($s['win_rate']??0))*100,1)?>%</td><td class="<?=((float)($s['average_return_percent']??0)>=0)?'ok':'bad-text'?>"><?=f($s['average_return_percent']??null,3)?>%</td><td><?=f($s['return_profit_factor']??null,2)?></td><td><?=f(((float)($s['size_multiplier']??1))*100,0)?>%</td><td><?=($s['learning_ready']??false)?'فعال':'نمونه کم'?></td></tr><?php endforeach?></tbody></table></div><?php endif?></section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>