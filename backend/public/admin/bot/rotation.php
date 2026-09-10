<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexRotationMonitor;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function f(mixed $v, int $d=3): string { return is_numeric($v) ? number_format((float)$v,$d,'.',',') : '—'; }
function reasonFa(string $reason): string { return match($reason) {
    'superior_opportunity_after_rotation_costs'=>'فرصت جدید بعد از کم کردن هزینه‌های فروش و خرید، به‌اندازه کافی بهتر است',
    'portfolio_has_free_slot'=>'هنوز جای خالی برای معامله جدید وجود دارد و نیازی به تعویض نیست',
    'pending_order_present'=>'تا تعیین تکلیف سفارش در انتظار، تعویض متوقف است',
    'rotation_cooldown_active'=>'فاصله زمانی اجباری بین دو تعویض هنوز تمام نشده است',
    'no_profitable_replacement_candidate'=>'فرصت خرید جدیدی که ارزش تعویض داشته باشد پیدا نشده است',
    'no_rotation_eligible_position'=>'هیچ معامله باز فعلی برای تعویض مناسب نیست',
    'replacement_advantage_insufficient'=>'برتری فرصت جدید برای جبران هزینه تعویض کافی نیست',
    'position_signal_data_stale'=>'اطلاعات پایش معاملات باز قدیمی است و باید تازه شود',
    'rotation_disabled'=>'قابلیت تعویض فرصت‌ها غیرفعال است',
    'kill_switch'=>'توقف اضطراری فعال است',
    'rotation_preview_unavailable'=>'داده کافی برای بررسی تعویض وجود ندارد',
    default=>$reason,
}; }
function stateFa(string $state): string { return match($state) {
    'ready'=>'آماده تعویض','standby'=>'در حال بررسی','cooldown'=>'در فاصله اجباری','blocked'=>'مسدود','waiting_data'=>'در انتظار داده تازه','disabled'=>'غیرفعال',default=>'در حال پایش',
}; }
function eventFa(string $event): string { return match($event) {
    'nobitex.rotation.sell_submitted'=>'فروش معامله ضعیف برای تعویض ارسال شد',
    'nobitex.rotation.local_state_conflict'=>'وضعیت محلی بعد از فروش نیاز به همگام‌سازی دارد',
    'nobitex.rotation.position_scan_failed'=>'پایش یکی از معاملات باز ناموفق بود',
    default=>$event,
}; }

try { $rotation=(new NobitexRotationMonitor())->snapshot(30); $error=''; }
catch(Throwable $e){$rotation=[];$error=mb_substr($e->getMessage(),0,700);}

$state=(string)($rotation['state']??'error');
$reason=(string)($rotation['reason']??'rotation_preview_unavailable');
$portfolio=is_array($rotation['portfolio']??null)?$rotation['portfolio']:[];
$weak=is_array($rotation['weakest_position']??null)?$rotation['weakest_position']:null;
$best=is_array($rotation['best_replacement']??null)?$rotation['best_replacement']:null;
$plan=is_array($rotation['preview_plan']??null)?$rotation['preview_plan']:[];
$cooldown=is_array($rotation['cooldown']??null)?$rotation['cooldown']:[];
$config=is_array($rotation['config']??null)?$rotation['config']:[];
$history=is_array($rotation['history']??null)?$rotation['history']:[];
$stateClass=$state==='ready'?'good':(in_array($state,['blocked','error','disabled'],true)?'bad':'warn');
$version=Updater::currentVersion();
$adv=$plan['advantage_percent']??null;$req=$plan['required_advantage_percent']??null;$sur=$plan['advantage_surplus_percent']??null;
$ratio=(is_numeric($adv)&&is_numeric($req)&&(float)$req>0)?max(0,min(100,((float)$adv/(float)$req)*100)):0;
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="20"><title>تعویض فرصت‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.pairs{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pair{border:1px solid var(--line);border-radius:17px;padding:15px;overflow:hidden}.pair.weak{background:var(--amberSoft)}.pair.best{background:var(--greenSoft)}.pair h3{margin:0;font-size:15px}.edge{font-size:27px;font-weight:900;margin:12px 0;overflow-wrap:anywhere}.small-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}.small{padding:9px;border-radius:11px;background:rgba(255,255,255,.72);overflow:hidden}.small span{display:block;color:var(--muted);font-size:9px}.small b{display:block;margin-top:4px;font-size:11px;overflow-wrap:anywhere}.decision{padding:14px;border-radius:14px;line-height:1.9;overflow-wrap:anywhere}.decision.good{background:var(--greenSoft);color:#12654e}.decision.warn{background:var(--amberSoft);color:#75500a}.decision.bad{background:var(--redSoft);color:#a52c2c}details.help-box{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 12px}details.help-box summary{cursor:pointer;font-weight:800;font-size:11px}details.help-box p{color:var(--muted);font-size:10px;line-height:1.9;margin:9px 0 0}@media(max-width:720px){.pairs,.small-grid{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('intelligence',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">جایگزینی هوشمند معاملات</div><h1>تعویض فرصت‌ها</h1><p>اگر ظرفیت معاملات پر باشد، ربات بررسی می‌کند آیا فروش یک معامله ضعیف و ورود به فرصت جدید واقعاً بعد از همه هزینه‌ها ارزش دارد یا نه.</p></div><span class="badge <?=$stateClass?>"><?=h(stateFa($state))?></span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">ریسک هوشمند</a><a class="active" href="/admin/bot/rotation.php">تعویض فرصت‌ها</a><a href="/admin/tradingview.php">سیگنال TradingView</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>آیا الان تعویض لازم است؟</h2><p>آخرین محاسبه: <?=h($rotation['generated_at']??'—')?> • اعتبار داده سیگنال: <?=h($rotation['signal_ttl_seconds']??300)?> ثانیه</p></div><span class="badge <?=$stateClass?>"><?=h(stateFa($state))?></span></div><div class="stat-grid"><div class="stat-card"><span>معاملات باز / سقف</span><b><?=h(($portfolio['active_positions']??0).'/'.($portfolio['max_positions']??0))?></b></div><div class="stat-card"><span>سفارش در انتظار</span><b><?=h($portfolio['pending_orders']??0)?></b></div><div class="stat-card"><span>معاملات با داده تازه</span><b><?=h(($portfolio['fresh_position_signals']??0).' / '.($portfolio['open_positions']??0))?></b></div><div class="stat-card"><span>زمان باقی‌مانده تا تعویض بعدی</span><b><?=h($cooldown['remaining_seconds']??0)?> ثانیه</b></div></div><div class="decision <?=$stateClass?>" style="margin-top:12px"><b><?=h(reasonFa($reason))?></b><?php if(($cooldown['active']??false)===true):?><div>پایان فاصله اجباری: <?=h($cooldown['until']??'—')?></div><?php endif?></div></section>

<section class="panel"><div class="panel-head"><div><h2>مقایسه معامله فعلی با فرصت جدید</h2><p>این قسمت فقط پیش‌نمایش است و خودش سفارشی ارسال نمی‌کند.</p></div><a class="btn soft" href="trade://rotation">باز کردن در اپ</a></div><div class="pairs"><div class="pair weak"><h3>ضعیف‌ترین معامله باز</h3><?php if($weak):?><div class="edge"><?=h($weak['symbol']??'—')?></div><div class="small-grid"><div class="small"><span>سود مورد انتظار از ادامه نگهداری</span><b><?=f($weak['forward_edge_percent']??null)?>٪</b></div><div class="small"><span>سود/زیان خالص فعلی</span><b><?=f($weak['unrealized_net_pnl_percent']??null)?>٪</b></div><div class="small"><span>هزینه تقریبی فروش</span><b><?=f($weak['estimated_exit_cost_percent']??null)?>٪</b></div><div class="small"><span>مدت نگهداری</span><b><?=h($weak['hold_minutes']??'—')?> دقیقه</b></div></div><?php else:?><div class="empty">معامله مناسبی برای مقایسه وجود ندارد.</div><?php endif?></div><div class="pair best"><h3>بهترین فرصت جدید</h3><?php if($best):?><div class="edge"><?=h($best['symbol']??'—')?></div><div class="small-grid"><div class="small"><span>سود خالص قابل معامله</span><b><?=f($best['tradable_net_edge_percent']??null)?>٪</b></div><div class="small"><span>کیفیت اجرای سفارش</span><b><?=f($best['execution_quality_score']??null,1)?> از 100</b></div><div class="small"><span>نوع بازار</span><b><?=h($best['quote_asset']??'—')?></b></div><div class="small"><span>سن سیگنال</span><b><?=h($best['age_seconds']??'—')?> ثانیه</b></div></div><?php else:?><div class="empty">فرصت جدید مناسبی پیدا نشده است.</div><?php endif?></div></div>
<div class="metric-grid" style="margin-top:10px"><div class="metric"><span>برتری فرصت جدید</span><b><?=f($adv)?>٪</b></div><div class="metric"><span>حداقل برتری لازم برای تعویض</span><b><?=f($req)?>٪</b></div><div class="metric"><span>برتری اضافه بعد از حداقل لازم</span><b><?=f($sur)?>٪</b></div></div><div style="margin-top:10px"><div class="progress"><i style="width:<?=$ratio?>%"></i></div><div class="progress-labels"><span>قدرت برتری فعلی</span><span><?=f($ratio,0)?>٪ از حد لازم</span></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>قوانین محافظتی تعویض</h2><p>این قوانین جلوی تعویض‌های پشت‌سرهم و فروش عجولانه را می‌گیرند.</p></div><span class="badge info">محافظ سرمایه</span></div><div class="metric-grid"><div class="metric"><span>تعویض خودکار</span><b><?=($config['enabled']??false)?'فعال':'غیرفعال'?></b></div><div class="metric"><span>حداقل زمان نگهداری قبل از تعویض</span><b><?=h($config['minimum_hold_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>فاصله اجباری بین دو تعویض</span><b><?=h($config['cooldown_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>حداقل برتری فرصت جدید</span><b><?=f($config['minimum_advantage_percent']??null)?>٪</b></div><div class="metric"><span>حد زیان محافظتی برای تعویض</span><b><?=f($config['maximum_loss_percent']??null)?>٪</b></div></div><details class="help-box"><summary>تعویض فرصت دقیقاً چه می‌کند؟</summary><p>ربات یک معامله را فقط چون ارز دیگری کمی بهتر شده نمی‌فروشد. ابتدا هزینه فروش معامله فعلی، هزینه خرید فرصت جدید، زمان نگهداری، زیان فعلی و فاصله زمانی از تعویض قبلی را بررسی می‌کند. فقط اگر فرصت جدید به‌اندازه کافی بهتر باشد، فروش مرحله اول انجام می‌شود؛ سپس بعد از تأیید بسته شدن معامله، فرصت جدید دوباره بررسی می‌شود.</p></details></section>

<section class="panel"><div class="panel-head"><div><h2>تاریخچه تعویض‌ها</h2><p>۳۰ رخداد اخیر ثبت‌شده.</p></div><span class="badge info"><?=count($history)?> رخداد</span></div><div class="table-wrap"><table><thead><tr><th>زمان UTC</th><th>رویداد</th><th>بازار</th><th>توضیح</th></tr></thead><tbody><?php if($history===[]):?><tr><td colspan="4" class="empty">هنوز رخدادی ثبت نشده است.</td></tr><?php endif?><?php foreach($history as $row):$details=is_array($row['details']??null)?$row['details']:(json_decode((string)($row['details_json']??''),true)?:[]);?><tr><td><?=h($row['created_at']??'—')?></td><td><?=h(eventFa((string)($row['event']??$row['event_type']??'—')))?></td><td><b><?=h($details['symbol']??$row['symbol']??'—')?></b></td><td><?=h($details['reason']??$details['message']??'—')?></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>