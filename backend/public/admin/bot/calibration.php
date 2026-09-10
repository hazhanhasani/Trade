<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexEdgeCalibration;
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
function strategyFa(string $key): string { return match($key) {
    'trend_momentum_v1'=>'روند و مومنتوم',
    'breakout_v1'=>'شکست محدوده',
    'mean_reversion_v1'=>'بازگشت به میانگین',
    default=>$key,
}; }
function regimeFa(string $key): string { return match($key) {
    'trending_up'=>'روند صعودی', 'trending_down'=>'روند نزولی',
    'breakout_up'=>'شکست صعودی', 'breakout_down'=>'شکست نزولی',
    'ranging'=>'بازار رنج', 'high_volatility'=>'نوسان شدید',
    'uncertain'=>'نامطمئن', default=>$key,
}; }

try { NobitexSchema::ensure(); $cal=(new NobitexEdgeCalibration())->snapshot(); $error=''; }
catch (Throwable $e) { $cal=[]; $error=mb_substr($e->getMessage(),0,800); }

$profiles=is_array($cal['profiles']??null)?$cal['profiles']:[];
$penalized=(int)($cal['penalized_profiles']??0);
$maxPenalty=(float)($cal['maximum_active_penalty_percent']??0.0);
$version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>کالیبراسیون Edge — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.cal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.cal-card{border:1px solid var(--line);background:#fff;border-radius:17px;padding:14px;min-width:0}.cal-card h3{margin:0 0 4px;font-size:14px}.cal-card p{margin:0;color:var(--muted);font-size:10px}.numbers{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-top:12px}.num{background:var(--surface2);border-radius:11px;padding:9px;min-width:0}.num span{display:block;color:var(--muted);font-size:9px;line-height:1.6}.num b{display:block;margin-top:4px;font-size:11px;overflow-wrap:anywhere}.bar{height:9px;background:#e9ecf3;border-radius:999px;overflow:hidden;margin-top:10px;direction:ltr}.bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#0b936c,#ad6900,#cf3f4b)}.explain{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.explain>div{background:var(--surface2);border-radius:13px;padding:11px;font-size:10px;line-height:1.9}@media(max-width:800px){.cal-grid,.explain{grid-template-columns:1fr}.numbers{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:380px){.numbers{grid-template-columns:1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('calibration',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">ADAPTIVE EDGE CALIBRATION V1</div><h1>کالیبراسیون Edge</h1><p>پیش‌بینی سود قبل از ورود با بازده خالص واقعی مقایسه می‌شود تا ربات در پروفایل‌هایی که مرتباً سود را بیش‌برآورد می‌کنند حاشیه امن بیشتری بخواهد.</p></div><span class="badge <?=$penalized>0?'warn':'good'?>"><?=$penalized?> پروفایل با Buffer اضافه</span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">ریسک هوشمند</a><a href="/admin/bot/learning.php">یادگیری استراتژی‌ها</a><a class="active" href="/admin/bot/calibration.php">کالیبراسیون Edge</a><a href="/admin/bot/rotation.php">تعویض فرصت‌ها</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>قانون محافظتی</h2><p>کالیبراسیون هیچ‌وقت شرط ورود را آسان‌تر نمی‌کند و حجم معامله را افزایش نمی‌دهد.</p></div><span class="badge good">Tighten only</span></div><div class="stat-grid"><div class="stat-card"><span>حداقل داده برای کالیبراسیون</span><b><?=h($cal['minimum_calibration_trades']??8)?> معامله</b></div><div class="stat-card"><span>خطای مجاز بدون جریمه</span><b><?=f($cal['tolerated_prediction_bias_percent']??.10,2)?>٪</b></div><div class="stat-card"><span>بیشترین Buffer فعال فعلی</span><b><?=f($maxPenalty,3)?>٪</b></div><div class="stat-card"><span>سقف Buffer اضافه</span><b><?=f($cal['maximum_extra_margin_percent']??.85,2)?>٪</b></div></div><div class="explain" style="margin-top:10px"><div><b>پیش‌بینی دقیق</b><br>اگر اختلاف Edge پیش‌بینی‌شده و نتیجه واقعی کوچک باشد، Buffer اضافه صفر می‌ماند.</div><div><b>بیش‌برآورد پایدار</b><br>با زیاد شدن نمونه‌ها، بخشی از خطای تاریخی به شرط Edge ورود بعدی اضافه می‌شود.</div><div><b>Edge ناکافی پس از کالیبراسیون</b><br>Candidate رد می‌شود و Smart Fallback سراغ فرصت بعدی می‌رود؛ خروج‌های ایمنی دست‌نخورده می‌مانند.</div></div></section>

<section class="panel"><div class="panel-head"><div><h2>پروفایل‌های Strategy / Regime</h2><p>کالیبراسیون هر پروفایل مستقل است؛ خطای Breakout روی Trend یا Mean Reversion منتقل نمی‌شود.</p></div><span class="badge info"><?=count($profiles)?> پروفایل</span></div>
<?php if($profiles===[]):?><div class="empty">هنوز داده کافی از معاملات بسته‌شده موتور Multi‑Strategy وجود ندارد. کالیبراسیون فعلاً خنثی است.</div><?php else:?><div class="cal-grid"><?php foreach($profiles as $p):$pen=max(0,(float)($p['calibration_penalty_percent']??0));$pct=min(100,($pen/.85)*100);?><article class="cal-card"><div class="panel-head"><div><h3><?=h(strategyFa((string)($p['strategy_key']??'')))?></h3><p><?=h(regimeFa((string)($p['regime']??'')))?> • <?=h($p['trades']??0)?> معامله بسته‌شده</p></div><span class="badge <?=$pen>0?'warn':((bool)($p['calibration_ready']??false)?'good':'info')?>"><?=$pen>0?'+'.f($pen,3).'٪':((bool)($p['calibration_ready']??false)?'بدون جریمه':'Warm‑up')?></span></div><div class="bar"><i style="width:<?=h($pct)?>%"></i></div><div class="numbers"><div class="num"><span>میانگین Edge پیش‌بینی‌شده</span><b><?=f($p['average_entry_edge_percent']??null,3)?>٪</b></div><div class="num"><span>میانگین بازده خالص واقعی</span><b><?=f($p['average_realized_return_percent']??null,3)?>٪</b></div><div class="num"><span>نسبت تحقق Edge</span><b><?=($p['edge_capture_ratio']??null)===null?'—':f(((float)$p['edge_capture_ratio'])*100,0).'٪'?></b></div><div class="num"><span>Buffer اضافه ورود</span><b>+<?=f($pen,3)?>٪</b></div></div></article><?php endforeach?></div><?php endif?>
</section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>
