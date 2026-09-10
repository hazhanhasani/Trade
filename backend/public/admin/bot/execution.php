<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexExecutionLearning;
use Trade\Trading\NobitexSchema;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n(mixed $v, int $d=3): string { return is_numeric($v) ? number_format((float)$v,$d,'.',',') : '—'; }
function strategyFa(string $key): string { return match($key) {
    'trend_momentum_v1'=>'روند و مومنتوم',
    'breakout_v1'=>'شکست محدوده',
    'mean_reversion_v1'=>'بازگشت به میانگین',
    default=>$key,
}; }
function regimeFa(string $key): string { return match($key) {
    'trending_up'=>'روند صعودی','trending_down'=>'روند نزولی',
    'breakout_up'=>'شکست صعودی','breakout_down'=>'شکست نزولی',
    'ranging'=>'بازار رنج','high_volatility'=>'نوسان شدید','uncertain'=>'نامطمئن',
    default=>$key,
}; }
function executionState(array $p): array {
    if (!(bool)($p['learning_ready']??false)) return ['info','در حال جمع‌آوری داده'];
    $penalty=(float)($p['execution_penalty_percent']??0);
    if ($penalty>=0.25) return ['bad','اجرای پرهزینه'];
    if ($penalty>=0.08) return ['warn','نیازمند احتیاط'];
    return ['good','اجرای مناسب'];
}

try { NobitexSchema::ensure(); $data=(new NobitexExecutionLearning())->snapshot(); $error=''; }
catch (Throwable $e) { $data=[]; $error=mb_substr($e->getMessage(),0,800); }
$profiles=is_array($data['profiles']??null)?$data['profiles']:[];
$version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>یادگیری اجرای سفارش — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.exec-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.exec-card{border:1px solid var(--line);border-radius:16px;background:#fff;padding:14px;min-width:0}.exec-card h3{margin:0 0 4px;font-size:14px}.exec-card p{margin:0;color:var(--muted);font-size:10px;line-height:1.8}.nums{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:12px}.num{background:var(--surface2);border-radius:11px;padding:9px;min-width:0}.num span{display:block;color:var(--muted);font-size:9px;line-height:1.6}.num b{display:block;margin-top:4px;font-size:11px;overflow-wrap:anywhere}.intro{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.intro>div{background:var(--surface2);padding:11px;border-radius:13px;font-size:10px;line-height:1.9}@media(max-width:800px){.exec-grid,.intro{grid-template-columns:1fr}.nums{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:380px){.nums{grid-template-columns:1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('execution-learning',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">EXECUTION LEARNING V1</div><h1>یادگیری اجرای سفارش</h1><p>ربات اختلاف قیمت برنامه‌ریزی‌شده با Fill واقعی، پرشدن ناقص سفارش و Reprice را اندازه می‌گیرد و در ورودهای بعدی همان شرایط محافظه‌کارتر می‌شود.</p></div><span class="badge info"><?=h($data['samples']??0)?> نمونه اجرا</span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/learning.php">یادگیری استراتژی‌ها</a><a class="active" href="/admin/bot/execution.php">یادگیری اجرا</a><a href="/admin/bot/calibration.php">کالیبراسیون Edge</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>منطق این بخش</h2><p>این سیستم فقط شرط ورود را سخت‌تر می‌کند و هرگز ریسک را افزایش نمی‌دهد.</p></div><span class="badge good">Reduction‑Only</span></div><div class="intro"><div><b>Slippage واقعی</b><br>قیمت Fill با قیمت مرجع لحظه تصمیم مقایسه می‌شود.</div><div><b>Partial Fill و Reprice</b><br>اجرای ناقص و نیاز به قیمت‌گذاری مجدد به‌عنوان هزینه اجرایی ثبت می‌شوند.</div><div><b>حاشیه ورود تطبیقی</b><br>پس از حداقل <?=h($data['minimum_samples']??6)?> نمونه، هزینه اجرای تاریخی به Edge لازم برای ورود بعدی افزوده می‌شود.</div></div></section>
<section class="panel"><div class="panel-head"><div><h2>پروفایل‌های اجرای واقعی</h2><p>هر Strategy + Regime + Quote مستقل یاد گرفته می‌شود؛ IRT و USDT با هم قاطی نمی‌شوند.</p></div><span class="badge info">سقف جریمه <?=n($data['maximum_penalty_percent']??0.45,2)?>٪</span></div>
<?php if($profiles===[]):?><div class="empty">هنوز Fill واقعی کافی برای ساخت پروفایل Execution Learning وجود ندارد. تا آن زمان جریمه اجرا صفر است.</div><?php else:?><div class="exec-grid"><?php foreach($profiles as $p):[$cls,$label]=executionState($p);?><article class="exec-card"><div class="panel-head"><div><h3><?=h(strategyFa((string)($p['strategy_key']??'')))?> • <?=h(regimeFa((string)($p['regime']??'')))?></h3><p><?=h($p['quote_asset']??'')?> • <?=h($p['samples']??0)?> اجرای تأییدشده</p></div><span class="badge <?=h($cls)?>"><?=h($label)?></span></div><div class="nums"><div class="num"><span>میانگین Slippage</span><b><?=n($p['average_slippage_percent']??0)?>٪</b></div><div class="num"><span>P75 Slippage</span><b><?=n($p['p75_slippage_percent']??0)?>٪</b></div><div class="num"><span>میانگین Fill</span><b><?=n(((float)($p['average_fill_ratio']??1))*100,1)?>٪</b></div><div class="num"><span>نرخ Partial Fill</span><b><?=n(((float)($p['partial_fill_rate']??0))*100,1)?>٪</b></div><div class="num"><span>نرخ Reprice</span><b><?=n(((float)($p['reprice_rate']??0))*100,1)?>٪</b></div><div class="num"><span>حاشیه اضافه ورود</span><b><?=n($p['execution_penalty_percent']??0)?>٪</b></div></div></article><?php endforeach?></div><?php endif?>
</section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>