<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Trading\TradeCommandCenter;
use Trade\Updater;

if(!Config::installed()){header('Location:/install/');exit;}
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control:no-store,no-cache,must-revalidate,max-age=0');
header('Pragma:no-cache');
if(!isset($_SESSION['admin_id'])){header('Location:/admin/');exit;}

function ph_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ph_n(mixed $v,int $d=2):string{return is_numeric($v)?number_format((float)$v,$d,'.',','):'—';}
function ph_badge(string $severity):string{return match($severity){'critical'=>'bad','warning'=>'warn','good'=>'good',default=>'info'};}

$version=Updater::currentVersion();$error='';$snapshot=[];
try{$snapshot=(new TradeCommandCenter())->snapshot();}catch(Throwable $e){$error=mb_substr($e->getMessage(),0,800);}
$headline=is_array($snapshot['headline']??null)?$snapshot['headline']:[];
$strip=is_array($snapshot['status_strip']??null)?$snapshot['status_strip']:[];
$decision=is_array($snapshot['decision_explainability']??null)?$snapshot['decision_explainability']:[];
$settings=is_array($snapshot['settings']['current']??null)?$snapshot['settings']['current']:[];
$alerts=is_array($snapshot['alerts']??null)?$snapshot['alerts']:[];
$performance=is_array($snapshot['performance']['summary_by_quote']['IRT']??null)?$snapshot['performance']['summary_by_quote']['IRT']:[];

$checks=[];$blockers=[];$warnings=[];
$addCheck=static function(string $code,string $label,bool $ok,string $detail,string $severity='critical')use(&$checks,&$blockers,&$warnings):void{
    $checks[]=['code'=>$code,'label'=>$label,'ok'=>$ok,'detail'=>$detail,'severity'=>$ok?'good':$severity];
    if(!$ok){$row=['code'=>$code,'label'=>$label,'detail'=>$detail,'severity'=>$severity];if($severity==='critical')$blockers[]=$row;else$warnings[]=$row;}
};
$addCheck('bot','ربات',($strip['bot']??'off')==='live','BOT '.strtoupper((string)($strip['bot']??'off')));
$addCheck('api','API',($strip['api']??'missing')==='ready','API '.strtoupper((string)($strip['api']??'missing')));
$addCheck('live','اجرای واقعی',($strip['live_execution']??'off')==='on','LIVE '.strtoupper((string)($strip['live_execution']??'off')));
$addCheck('cron','Cron',(bool)($strip['cron_healthy']??false),(bool)($strip['cron_healthy']??false)?'Cron سالم است':'Cron نیاز به بررسی دارد');
$addCheck('emergency','حالت اضطراری',($strip['emergency_mode']??'normal')==='normal','EMERGENCY '.strtoupper((string)($strip['emergency_mode']??'normal')));
$addCheck('circuit','Circuit',($strip['circuit']??'open')==='closed',($strip['circuit']??'open')==='closed'?'Circuit بسته است':'Circuit باز است: '.(string)($strip['circuit_reason']??'نامشخص'));
$active=(int)($headline['active_positions']??0);$limit=(int)($headline['effective_max_positions']??0);
$addCheck('capacity','ظرفیت پوزیشن',$limit<=0||$active<$limit,$limit>0?"{$active}/{$limit} پوزیشن فعال":'سقف مؤثر نامشخص','warning');

$action=strtolower((string)($decision['action']??'hold'));
$edge=is_numeric($decision['tradable_edge_percent']??null)?(float)$decision['tradable_edge_percent']:null;
$reason=(string)($decision['reason_fa']??$decision['reason']??'تصمیم اخیر در دسترس نیست.');
$signalReady=$action==='buy'&&$edge!==null&&$edge>0;
if(!$signalReady)$warnings[]=['code'=>'market','label'=>'شرایط بازار','detail'=>$reason,'severity'=>'warning'];

$criticalAlerts=count(array_filter($alerts,static fn($a)=>is_array($a)&&($a['priority']??'')==='critical'));
if($criticalAlerts>0)$warnings[]=['code'=>'alerts','label'=>'هشدار فعال','detail'=>"{$criticalAlerts} هشدار Critical در مرکز فرمان وجود دارد.",'severity'=>'warning'];

$controlsReady=$blockers===[];
$buyState=$controlsReady?($signalReady?'ready':'waiting_market'):'blocked';
$buyTitle=match($buyState){'ready'=>'مسیر BUY آماده است','waiting_market'=>'زیرساخت آماده است؛ بازار هنوز شرایط ورود نداده','blocked'=>'BUY به‌وسیله یکی از کنترل‌های اجرایی مسدود است'};
$buyText=match($buyState){
    'ready'=>'آخرین تصمیم نیز سیگنال BUY با Edge خالص مثبت دارد. این فقط آمادگی اجرای سفارش را نشان می‌دهد و تضمین سود نیست.',
    'waiting_market'=>'مشکل اجرایی قطعی دیده نمی‌شود؛ ربات منتظر سیگنالی است که پس از کارمزد، Spread و فیلترهای ریسک قابل معامله باشد.',
    default=>'تا رفع Blockerهای قرمز، تغییر دادن Thresholdهای سیگنال مشکل اصلی را حل نمی‌کند.',
};

$score=100;
$score-=count($blockers)*14;$score-=count($warnings)*4;$score-=min(25,max(0,(float)($headline['current_drawdown_percent']??0))*2.2);$score-=min(15,$criticalAlerts*5);$score=max(0,min(100,(int)round($score)));
$scoreLabel=$score>=85?'سالم و آماده':($score>=70?'قابل قبول':($score>=50?'نیازمند توجه':'بحرانی'));

$priorities=[];
foreach($blockers as$b)$priorities[]=['p'=>'P0','title'=>$b['label'],'body'=>$b['detail']];
if((float)($headline['current_drawdown_percent']??0)>=6)$priorities[]=['p'=>'P0','title'=>'Drawdown بالا','body'=>'تا مشخص شدن علت افت حساب، افزایش ریسک یا اجبار به خرید منطقی نیست.'];
if(!$signalReady)$priorities[]=['p'=>'P1','title'=>'کیفیت Entry','body'=>$reason];
if($criticalAlerts>0)$priorities[]=['p'=>'P1','title'=>'هشدارهای فعال','body'=>'هشدارهای Critical را قبل از تغییر استراتژی جمع‌بندی کن.'];
if($priorities===[])$priorities[]=['p'=>'P2','title'=>'پایش عادی','body'=>'سیستم از نظر اجرایی مانع واضحی ندارد؛ تمرکز روی کیفیت سیگنال و عملکرد خالص باشد.'];
$priorities=array_slice($priorities,0,6);

require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>پایش پروژه — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.ph-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.ph-check{padding:12px;border:1px solid var(--line);border-radius:16px;background:var(--surface2)}.ph-check b{display:block;margin-bottom:4px}.ph-check small{color:var(--muted);line-height:1.7}.ph-priority{display:grid;grid-template-columns:54px 1fr;gap:10px;padding:11px 0;border-bottom:1px solid var(--line)}.ph-priority:last-child{border-bottom:0}.ph-mode{display:grid;grid-template-columns:150px 1fr;gap:10px;padding:9px 0;border-bottom:1px solid var(--line)}.ph-mode:last-child{border-bottom:0}@media(max-width:800px){.ph-grid{grid-template-columns:1fr 1fr}.ph-mode{grid-template-columns:1fr}}@media(max-width:520px){.ph-grid{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('health',$version);?>
<div class="page-head"><div><div class="page-eyebrow">PROJECT / PRODUCT / RUNTIME REVIEW</div><h1>پایش پروژه</h1><p>یک نمای اجرایی برای CEO، Debug، Review، Prioritize، Track و Customer View. این صفحه Read-only است و هیچ سفارش یا تنظیمی را تغییر نمی‌دهد.</p></div><a class="btn secondary" href="/admin/project-health.php">بروزرسانی</a></div>
<?php if($error!==''):?><div class="notice bad"><?=ph_h($error)?></div><?php endif?>
<section class="hero-panel"><div class="page-eyebrow" style="color:#d8d4ff">PROJECT SCORE</div><h2><?=$score?>/100 — <?=ph_h($scoreLabel)?></h2><p><?=ph_h($buyTitle)?> • Backend <?=ph_h($version)?></p><div class="hero-pills"><span class="hero-pill">BUY <?=ph_h(strtoupper($buyState))?></span><span class="hero-pill">RISK <?=ph_h(strtoupper((string)($strip['risk']??'—')))?></span><span class="hero-pill">CRON <?=($strip['cron_healthy']??false)?'OK':'CHECK'?></span><span class="hero-pill">ALERTS <?=count($alerts)?></span></div></section>

<div class="stat-grid"><div class="stat-card"><span>ارزش کیف پول</span><b><?=ph_n($headline['portfolio_value_irt']??0,0)?></b><small>تومان</small></div><div class="stat-card"><span>PnL امروز</span><b class="<?=((float)($headline['today_net_pnl_irt']??0)>=0)?'ok':'bad-text'?>"><?=ph_n($headline['today_net_pnl_irt']??0,0)?></b><small>تومان</small></div><div class="stat-card"><span>Drawdown حساب</span><b><?=ph_n($headline['current_drawdown_percent']??0,2)?>%</b></div><div class="stat-card"><span>پوزیشن</span><b><?=$active?>/<?=$limit?></b><small>سقف مؤثر</small></div></div>

<section class="panel <?=$buyState==='blocked'?'danger':'soft'?>"><div class="panel-head"><div><h2>آمادگی خرید</h2><p><?=ph_h($buyText)?></p></div><span class="badge <?=ph_badge($buyState==='ready'?'good':($buyState==='blocked'?'critical':'warning'))?>"><?=ph_h($buyTitle)?></span></div><div class="ph-grid"><?php foreach($checks as$c):?><div class="ph-check"><span class="badge <?=ph_badge((string)$c['severity'])?>"><?=$c['ok']?'OK':'CHECK'?></span><b><?=ph_h($c['label'])?></b><small><?=ph_h($c['detail'])?></small></div><?php endforeach?></div><div class="notice info" style="margin-top:12px"><b>آخرین تصمیم بازار:</b> <?=ph_h((string)($decision['symbol']??'—'))?> • <?=ph_h((string)($decision['action']??'—'))?> • Edge <?=ph_n($decision['tradable_edge_percent']??null,3)?>%<br><?=ph_h($reason)?></div></section>

<div class="panel-grid"><section class="panel"><div class="panel-head"><div><h2>اولویت‌های اجرایی</h2><p>Focus + Prioritize + Plan</p></div></div><?php foreach($priorities as$p):?><div class="ph-priority"><span class="badge <?=$p['p']==='P0'?'bad':($p['p']==='P1'?'warn':'info')?>"><?=ph_h($p['p'])?></span><div><b><?=ph_h($p['title'])?></b><small class="muted"><?=ph_h($p['body'])?></small></div></div><?php endforeach?></section><section class="panel"><div class="panel-head"><div><h2>KPIهای قابل پیگیری</h2><p>Track + Review</p></div></div><div class="metric-grid"><div class="metric"><span>Win Rate 30d</span><b><?=ph_n($performance['win_rate_percent']??0,1)?>%</b></div><div class="metric"><span>Profit Factor</span><b><?=ph_n($performance['profit_factor']??0,2)?></b></div><div class="metric"><span>Avg Return</span><b><?=ph_n($performance['average_return_percent']??0,3)?>%</b></div><div class="metric"><span>Trades</span><b><?=ph_h($performance['trades']??0)?></b></div><div class="metric"><span>Exposure Limit</span><b><?=ph_n($settings['nobitex_portfolio_exposure_percent']??0,0)?>%</b></div><div class="metric"><span>Daily Loss Limit</span><b><?=ph_n($settings['daily_loss_limit_percent']??0,1)?>%</b></div></div></section></div>

<section class="panel"><div class="panel-head"><div><h2>روش کاری پروژه</h2><p>تمام حالت‌هایی که خواستی، به یک چرخه عملی تبدیل شده‌اند.</p></div></div><?php $modes=[
'/human'=>'متن خطا و تصمیم باید برای انسان قابل فهم باشد؛ Raw code کنار توضیح فارسی بماند.',
'/expert'=>'هر تغییر Runtime باید منبع داده، Failure mode و Regression test داشته باشد.',
'/ceo'=>'اول P0های توقف/امنیت، بعد سودآوری، بعد قابلیت‌های تزئینی.',
'/seo'=>'صفحات خصوصی Admin/API نباید Index شوند؛ مستندات عمومی باید نسخه و قابلیت درست داشته باشند.',
'/critic'=>'فرض می‌کنیم هر state می‌تواند stale، متناقض یا deadlock شود و برایش تست می‌سازیم.',
'/plan + /planner'=>'Release کوچک، قابل برگشت و نسخه‌دار؛ تغییرات پرریسک جدا از UI انجام شوند.',
'/habit'=>'هر تغییر قبل از Release: lint → regression → package → signed APK → stable manifest.',
'/focus + /prioritize'=>'یک Source of Truth برای Settings و یک Source of Truth برای Live status.',
'/track + /review'=>'Win Rate، Profit Factor، Drawdown، API failures، Pending age و Cron health پایش شوند.',
'/customer + /audience'=>'کاربر باید در یک نگاه بفهمد چرا ربات خرید نکرده، چه چیزی مسدود است و قدم بعدی چیست.',
'/competitor + /research'=>'الگوهای رایج botها: Entry conditions شفاف، Backtest/Shadow، کنترل ریسک و حالت‌های اجرایی مجزا.',
'/evaluate + /innovate'=>'هر قابلیت جدید با اثر روی Risk/Latency/Rate-limit/UX سنجیده شود؛ نوآوری بدون اندازه‌گیری وارد Live نشود.',
'/debug'=>'خطا باید با component + exchange + file/line + reason code قابل ردیابی باشد.',
'/concise'=>'داشبورد اول نتیجه را می‌گوید؛ جزئیات فقط در بخش‌های پایین‌تر.',
];foreach($modes as$k=>$v):?><div class="ph-mode"><b><?=ph_h($k)?></b><span><?=ph_h($v)?></span></div><?php endforeach?></section>

<section class="panel"><div class="panel-head"><div><h2>قانون تصمیم برای خرید</h2><p>هدف پروژه «تعداد معامله بیشتر» نیست؛ هدف، ورود قابل اندازه‌گیری با هزینه و ریسک کنترل‌شده است.</p></div></div><div class="simple-guide"><div><b>۱. زیرساخت</b><span>Bot، API، Live، Cron و Circuit باید سالم باشند.</span></div><div><b>۲. فرصت</b><span>سیگنال BUY باید بعد از کارمزد، Spread و Buffer هنوز Edge مثبت داشته باشد.</span></div><div><b>۳. ریسک</b><span>ظرفیت، Exposure، Pending، Cooldown و محدودیت‌های ریسک باید اجازه ورود بدهند.</span></div></div></section>

<?php tradeAdminFooter($version);?></div></body></html>
