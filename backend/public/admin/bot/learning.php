<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexSchema;
use Trade\Trading\NobitexStrategyLearning;
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
function stateClass(array $profile): string {
    if ((bool)($profile['blocked']??false)) return 'bad';
    $m=(float)($profile['size_multiplier']??1.0);
    if (!(bool)($profile['learning_ready']??false)) return 'info';
    return $m>=0.95?'good':($m>=0.75?'warn':'bad');
}
function stateFa(array $profile): string {
    if ((bool)($profile['blocked']??false)) return 'ورود موقتاً متوقف';
    if (!(bool)($profile['learning_ready']??false)) return 'در حال جمع‌آوری داده';
    $m=(float)($profile['size_multiplier']??1.0);
    if ($m>=0.9999) return 'عادی';
    return 'حجم کاهش یافته';
}

try { NobitexSchema::ensure(); $learning=(new NobitexStrategyLearning())->snapshot(); $error=''; }
catch (Throwable $e) { $learning=[]; $error=mb_substr($e->getMessage(),0,800); }

$strategies=is_array($learning['strategies']??null)?$learning['strategies']:[];
$profiles=is_array($learning['profiles']??null)?$learning['profiles']:[];
$version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="30"><title>یادگیری استراتژی‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.learning-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.strategy-card,.profile-card{border:1px solid var(--line);border-radius:16px;background:#fff;padding:14px;min-width:0}.strategy-card h3,.profile-card h3{margin:0 0 5px;font-size:14px}.strategy-card p,.profile-card p{margin:0;color:var(--muted);font-size:10px;line-height:1.8}.profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.numbers{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:12px}.number{background:var(--surface2);border-radius:11px;padding:9px;min-width:0}.number span{display:block;color:var(--muted);font-size:9px;line-height:1.6}.number b{display:block;margin-top:4px;font-size:11px;overflow-wrap:anywhere}.weight{height:9px;background:#e9ecf3;border-radius:999px;overflow:hidden;margin-top:10px;direction:ltr}.weight i{height:100%;display:block;background:linear-gradient(90deg,#cf3f4b,#ad6900,#0b936c);border-radius:999px}.explain{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.explain>div{background:var(--surface2);padding:11px;border-radius:13px;font-size:10px;line-height:1.9}@media(max-width:800px){.learning-grid,.profile-grid,.explain{grid-template-columns:1fr}.numbers{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:380px){.numbers{grid-template-columns:1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('intelligence',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">STRATEGY LEARNING V2</div><h1>یادگیری استراتژی‌ها</h1><p>ربات نتیجه واقعی هر روش معامله را جداگانه یاد می‌گیرد. ضعیف شدن یک روش، روی روش‌های دیگر اثر نمی‌گذارد.</p></div><span class="badge info"><?=h($learning['realized_samples']??0)?> معامله آموزشی</span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">ریسک هوشمند</a><a class="active" href="/admin/bot/learning.php">یادگیری استراتژی‌ها</a><a href="/admin/bot/rotation.php">تعویض فرصت‌ها</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>این بخش چه کاری می‌کند؟</h2><p>تنها از معاملات بسته‌شده و سود/زیان خالص بعد از هزینه‌ها یاد می‌گیرد.</p></div><span class="badge good">افزایش ریسک ممنوع</span></div><div class="explain"><div><b>کمتر از ۵ معامله</b><br>هیچ تغییری در حجم نمی‌دهد تا با چند نتیجه اتفاقی تصمیم نگیرد.</div><div><b>عملکرد ضعیف</b><br>فقط حجم خرید بعدی همان استراتژی در همان نوع بازار را کمتر می‌کند.</div><div><b>ضعف پایدار با داده کافی</b><br>بعد از حداقل ۱۲ معامله، اگر نتیجه واقعاً بد بماند ورود همان پروفایل را متوقف می‌کند؛ خروج‌ها هرگز متوقف نمی‌شوند.</div></div></section>

<section class="panel"><div class="panel-head"><div><h2>وضعیت سه روش اصلی</h2><p>ضریب ۱۰۰٪ یعنی حجم عادی. عدد پایین‌تر یعنی ربات برای آن روش محتاط‌تر شده است.</p></div></div><div class="learning-grid">
<?php foreach($strategies as $s):$m=max(0,min(1,(float)($s['size_multiplier']??1)));?><div class="strategy-card"><div class="panel-head"><div><h3><?=h(strategyFa((string)($s['strategy_key']??'')))?></h3><p><?=h($s['trades']??0)?> معامله بسته‌شده</p></div><span class="badge <?=((int)($s['blocked_profiles']??0)>0)?'bad':($m<.95?'warn':'good')?>"><?=f($m*100,0)?>٪</span></div><div class="weight"><i style="width:<?=h($m*100)?>%"></i></div><div class="numbers"><div class="number"><span>پروفایل‌های دارای داده کافی</span><b><?=h($s['mature_profiles']??0)?></b></div><div class="number"><span>پروفایل متوقف‌شده</span><b><?=h($s['blocked_profiles']??0)?></b></div><div class="number"><span>ضریب کلی این روش</span><b><?=f($m*100,0)?>٪</b></div></div></div><?php endforeach?>
</div></section>

<section class="panel"><div class="panel-head"><div><h2>جزئیات یادگیری بر اساس نوع بازار</h2><p>مثلاً «شکست محدوده در شکست صعودی» مستقل از «روند و مومنتوم در روند صعودی» یاد گرفته می‌شود.</p></div><span class="badge info"><?=count($profiles)?> پروفایل</span></div>
<?php if($profiles===[]):?><div class="empty">هنوز معامله بسته‌شده‌ای که با موتور Multi‑Strategy جدید ثبت شده باشد وجود ندارد. تا جمع شدن داده، همه روش‌ها با ضریب ۱۰۰٪ کار می‌کنند.</div><?php else:?><div class="profile-grid"><?php foreach($profiles as $p):$m=max(0,min(1,(float)($p['size_multiplier']??1)));?><article class="profile-card"><div class="panel-head"><div><h3><?=h(strategyFa((string)($p['strategy_key']??'')))?></h3><p><?=h(regimeFa((string)($p['regime']??'')))?></p></div><span class="badge <?=h(stateClass($p))?>"><?=h(stateFa($p))?></span></div><div class="weight"><i style="width:<?=h($m*100)?>%"></i></div><div class="numbers"><div class="number"><span>تعداد معاملات</span><b><?=h($p['trades']??0)?></b></div><div class="number"><span>درصد معاملات سودده</span><b><?=f(((float)($p['win_rate']??0))*100,1)?>٪</b></div><div class="number"><span>میانگین بازده خالص</span><b><?=f($p['average_return_percent']??0,3)?>٪</b></div><div class="number"><span>نسبت مجموع سود به زیان</span><b><?=f($p['profit_factor']??1,2)?></b></div><div class="number"><span>زیان پشت‌سرهم اخیر</span><b><?=h($p['recent_loss_streak']??0)?></b></div><div class="number"><span>حجم خرید بعدی</span><b><?=f($m*100,0)?>٪ حالت عادی</b></div><?php if(($p['average_entry_edge_percent']??null)!==null):?><div class="number"><span>میانگین سود پیش‌بینی‌شده هنگام ورود</span><b><?=f($p['average_entry_edge_percent'],3)?>٪</b></div><div class="number"><span>نسبت نتیجه واقعی به پیش‌بینی</span><b><?=($p['edge_capture_ratio']??null)===null?'—':f(((float)$p['edge_capture_ratio'])*100,0).'٪'?></b></div><?php endif?></div></article><?php endforeach?></div><?php endif?>
</section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>