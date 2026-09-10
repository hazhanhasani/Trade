<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexPortfolioIntelligence;
use Trade\Trading\NobitexSchema;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function f(mixed $value, int $digits=2): string { return is_numeric($value) ? number_format((float)$value, $digits, '.', ',') : '—'; }

try {
    NobitexSchema::ensure();
    $intel=(new NobitexPortfolioIntelligence())->snapshot();
    $error='';
} catch (Throwable $e) {
    $intel=[];
    $error=mb_substr($e->getMessage(),0,800);
}

$dd=is_array($intel['drawdown']??null)?$intel['drawdown']:[];
$corr=is_array($intel['correlation_guard']??null)?$intel['correlation_guard']:[];
$strategies=is_array($intel['strategy_performance']??null)?$intel['strategy_performance']:[];
$mult=(float)($intel['position_size_multiplier']??1.0);
$multPct=$mult*100.0;
$stateClass=$mult>=0.95?'good':($mult>=0.75?'warn':'bad');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>Portfolio Intelligence — Trade</title>
<style>
:root{--bg:#f6f7fb;--card:#fff;--ink:#171a24;--muted:#747b8e;--line:#e5e8f0;--violet:#6941ff;--green:#0b936c;--red:#cf3f4b;--amber:#ad6900;--greenSoft:#eaf8f3;--redSoft:#fff0f1;--amberSoft:#fff7e8;--violetSoft:#f1edff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:22px}.top,.sectionTitle{display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;align-items:center;gap:12px}.logo{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,#6941ff,#8b5cf6);color:white;font-size:21px;font-weight:900}.top h1,.sectionTitle h2{margin:0}.top h1{font-size:23px}.muted{color:var(--muted);font-size:12px;line-height:1.8}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{padding:10px 13px;border-radius:12px;text-decoration:none;color:#fff;background:var(--violet);font-weight:700;font-size:12px}.btn.gray{background:#667085}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:17px;margin-top:14px;box-shadow:0 10px 34px #1b274008}.hero{background:linear-gradient(135deg,#fff,#f7f4ff)}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:14px}.metric{padding:13px;border:1px solid var(--line);border-radius:14px;background:#fff}.metric span{display:block;font-size:10px;color:var(--muted)}.metric b{display:block;margin-top:6px;font-size:17px}.badge{display:inline-block;padding:7px 10px;border-radius:999px;font-size:11px;font-weight:800}.good{color:var(--green)}.warn{color:var(--amber)}.bad{color:var(--red)}.badge.good{background:var(--greenSoft)}.badge.warn{background:var(--amberSoft)}.badge.bad{background:var(--redSoft)}.bar{height:10px;background:#e9ecf3;border-radius:999px;overflow:hidden;margin-top:10px;direction:ltr}.bar i{display:block;height:100%;background:linear-gradient(90deg,#cf3f4b,#ad6900,#0b936c);border-radius:999px}.notice{background:var(--violetSoft);padding:13px 15px;border-radius:14px;margin-top:14px;line-height:1.9;font-size:12px}.error{background:var(--redSoft);color:var(--red);padding:13px;border-radius:14px;margin-top:14px}.scroll{overflow:auto;margin-top:10px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:right;padding:10px;border-bottom:1px solid #edf0f4;white-space:nowrap}th{color:var(--muted)}.empty{padding:20px;text-align:center;color:var(--muted)}@media(max-width:820px){.grid{grid-template-columns:1fr 1fr}.top{align-items:flex-start;flex-direction:column}}@media(max-width:480px){.wrap{padding:12px}.grid{grid-template-columns:1fr}.actions,.actions .btn{width:100%}.actions .btn{text-align:center}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand"><div class="logo">AI</div><div><h1>Portfolio Intelligence v2</h1><div class="muted">یادگیری محافظه‌کارانه از Net PnL واقعی • Drawdown-aware sizing • Correlation Guard</div></div></div><div class="actions"><a class="btn" href="/admin/bot/intelligence.php">بروزرسانی</a><a class="btn gray" href="/admin/bot/rotation.php">Rotation</a><a class="btn gray" href="/admin/bot/">ربات معامله‌گر</a></div></div>
<?php if($error!==''):?><div class="error"><?=h($error)?></div><?php else:?>
<div class="card hero"><div class="sectionTitle"><div><h2>Risk Intelligence</h2><div class="muted">آخرین محاسبه <?=h($intel['generated_at']??'—')?> • داده یادگیری فقط از معاملات بسته و Fee-aware</div></div><span class="badge <?=$stateClass?>">SIZE <?=f($multPct,0)?>%</span></div>
<div class="grid"><div class="metric"><span>ضریب ورود بعدی</span><b><?=f($multPct,0)?>%</b></div><div class="metric"><span>Drawdown فعلی</span><b><?=f($dd['current_drawdown_percent']??null)?>%</b></div><div class="metric"><span>Max Drawdown</span><b><?=f($dd['max_drawdown_percent']??null)?>%</b></div><div class="metric"><span>زیان متوالی</span><b><?=h($dd['losing_streak']??0)?></b></div></div>
<div class="bar"><i style="width:<?=h(max(0,min(100,$multPct)))?>%"></i></div>
<div class="notice">این ضریب هرگز اندازه ریسک تنظیم‌شده را افزایش نمی‌دهد. در Drawdown یا عملکرد ضعیفِ پایدار فقط حجم ورودهای جدید کم می‌شود؛ Stop Loss، Take Profit و SELLها با این لایه مسدود نمی‌شوند.</div></div>

<div class="card"><div class="sectionTitle"><h2>Portfolio & Correlation Guard</h2><span class="muted">تنوع قبل از BUY خودکار کنترل می‌شود</span></div><div class="grid"><div class="metric"><span>پوزیشن فعال</span><b><?=h($intel['active_positions']??0)?></b></div><div class="metric"><span>IRT / USDT</span><b><?=h(($intel['active_by_quote']['IRT']??0).' / '.($intel['active_by_quote']['USDT']??0))?></b></div><div class="metric"><span>Correlation Threshold</span><b><?=f($corr['threshold']??null,2)?></b></div><div class="metric"><span>حداکثر همبستگی مجاز</span><b><?=h($corr['max_correlated_positions']??'—')?></b></div></div><div class="grid"><div class="metric"><span>حداقل نمونه Correlation</span><b><?=h($corr['minimum_samples']??'—')?></b></div><div class="metric"><span>Lookback</span><b><?=h($corr['lookback_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>Realized Samples</span><b><?=h($intel['realized_samples']??0)?></b></div><div class="metric"><span>Strategy Multiplier</span><b><?=f(((float)($intel['strategy_multiplier']??1))*100,0)?>%</b></div></div></div>

<div class="card"><div class="sectionTitle"><div><h2>Strategy Learning</h2><div class="muted">Profileها از مشخصات واقعی لحظه ورود ساخته می‌شوند؛ کمتر از ۵ معامله هیچ کاهش وزنی ایجاد نمی‌کند.</div></div></div><?php if($strategies===[]):?><div class="empty">هنوز معامله بسته‌شده کافی از ورودی‌های Portfolio Intelligence ثبت نشده است؛ وزن Strategy فعلاً خنثی است.</div><?php else:?><div class="scroll"><table><thead><tr><th>Strategy</th><th>Profile</th><th>Trades</th><th>Win Rate</th><th>Avg Return</th><th>Profit Factor</th><th>Size Weight</th><th>Learning</th></tr></thead><tbody><?php foreach($strategies as $s):?><tr><td><?=h($s['strategy_key']??'—')?></td><td><?=h($s['profile_key']??'—')?></td><td><?=h($s['trades']??0)?></td><td><?=f(((float)($s['win_rate']??0))*100,1)?>%</td><td class="<?=((float)($s['average_return_percent']??0)>=0)?'good':'bad'?>"><?=f($s['average_return_percent']??null,3)?>%</td><td><?=f($s['return_profit_factor']??null,2)?></td><td><?=f(((float)($s['size_multiplier']??1))*100,0)?>%</td><td><?=($s['learning_ready']??false)?'فعال':'نمونه کم'?></td></tr><?php endforeach?></tbody></table></div><?php endif?></div>
<?php endif?></div></body></html>
