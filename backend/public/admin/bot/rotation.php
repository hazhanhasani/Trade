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
    'superior_opportunity_after_rotation_costs'=>'فرصت جایگزین پس از کسر هزینه و حاشیه اصطکاک، برتری کافی دارد',
    'portfolio_has_free_slot'=>'پورتفو هنوز اسلات آزاد دارد و نیازی به جایگزینی نیست',
    'pending_order_present'=>'تا تعیین تکلیف سفارش Pending، Rotation متوقف است',
    'rotation_cooldown_active'=>'Cooldown تعویض هنوز تمام نشده است',
    'no_profitable_replacement_candidate'=>'فرصت خرید جایگزین سودمند پیدا نشده است',
    'no_rotation_eligible_position'=>'هیچ پوزیشن فعلی شرایط Rotation را ندارد',
    'replacement_advantage_insufficient'=>'برتری فرصت جدید برای جبران هزینه تعویض کافی نیست',
    'position_signal_data_stale'=>'داده پایش پوزیشن‌ها تازه نیست',
    'rotation_disabled'=>'Portfolio Rotation غیرفعال است',
    'kill_switch'=>'توقف اضطراری فعال است',
    'rotation_preview_unavailable'=>'داده کافی برای پیش‌نمایش Rotation موجود نیست',
    default=>$reason,
}; }
function stateFa(string $state): string { return match($state) {
    'ready'=>'آماده جایگزینی','standby'=>'آماده‌باش','cooldown'=>'در Cooldown','blocked'=>'مسدود','waiting_data'=>'در انتظار داده تازه','disabled'=>'غیرفعال',default=>'در حال پایش',
}; }
function eventFa(string $event): string { return match($event) {
    'nobitex.rotation.sell_submitted'=>'فروش برای Rotation ارسال شد',
    'nobitex.rotation.local_state_conflict'=>'نیاز به همگام‌سازی وضعیت پس از فروش',
    'nobitex.rotation.position_scan_failed'=>'پایش یکی از پوزیشن‌ها ناموفق بود',
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
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="20"><title>Rotation — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.pairs{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pair{border:1px solid var(--line);border-radius:17px;padding:15px}.pair.weak{background:var(--amberSoft)}.pair.best{background:var(--greenSoft)}.pair h3{margin:0;font-size:15px}.edge{font-size:27px;font-weight:900;margin:12px 0}.small-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}.small{padding:9px;border-radius:11px;background:rgba(255,255,255,.72)}.small span{display:block;color:var(--muted);font-size:9px}.small b{display:block;margin-top:4px;font-size:11px}.decision{padding:14px;border-radius:14px;line-height:1.9}.decision.good{background:var(--greenSoft);color:#12654e}.decision.warn{background:var(--amberSoft);color:#75500a}.decision.bad{background:var(--redSoft);color:#a52c2c}@media(max-width:720px){.pairs{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('intelligence',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">PORTFOLIO / ROTATION</div><h1>Portfolio Rotation</h1><p>پایش ضعیف‌ترین پوزیشن و بهترین فرصت جایگزین؛ صفحه هر ۲۰ ثانیه بروزرسانی می‌شود.</p></div><span class="badge <?=$stateClass?>"><?=h(stateFa($state))?></span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">Intelligence</a><a class="active" href="/admin/bot/rotation.php">Rotation</a><a href="/admin/tradingview.php">TradingView</a></div>
<?php if($error!==''):?><div class="notice bad"><?=h($error)?></div><?php else:?>
<section class="panel soft"><div class="panel-head"><div><h2>وضعیت Rotation</h2><p>آخرین محاسبه <?=h($rotation['generated_at']??'—')?> • TTL سیگنال <?=h($rotation['signal_ttl_seconds']??300)?> ثانیه</p></div><span class="badge <?=$stateClass?>"><?=h(stateFa($state))?></span></div><div class="stat-grid"><div class="stat-card"><span>ظرفیت پورتفو</span><b><?=h(($portfolio['active_positions']??0).'/'.($portfolio['max_positions']??0))?></b></div><div class="stat-card"><span>Pending</span><b><?=h($portfolio['pending_orders']??0)?></b></div><div class="stat-card"><span>سیگنال تازه پوزیشن</span><b><?=h(($portfolio['fresh_position_signals']??0).' / '.($portfolio['open_positions']??0))?></b></div><div class="stat-card"><span>Cooldown باقی‌مانده</span><b><?=h($cooldown['remaining_seconds']??0)?>s</b></div></div><div class="decision <?=$stateClass?>" style="margin-top:12px"><b><?=h(reasonFa($reason))?></b><?php if(($cooldown['active']??false)===true):?><div>پایان Cooldown: <?=h($cooldown['until']??'—')?></div><?php endif?></div></section>

<section class="panel"><div class="panel-head"><div><h2>مقایسه فرصت</h2><p>Preview فقط از داده ذخیره‌شده استفاده می‌کند و سفارشی ارسال نمی‌کند.</p></div><a class="btn soft" href="trade://rotation">باز کردن در اپ</a></div><div class="pairs"><div class="pair weak"><h3>ضعیف‌ترین پوزیشن</h3><?php if($weak):?><div class="edge"><?=h($weak['symbol']??'—')?></div><div class="small-grid"><div class="small"><span>Forward Edge</span><b><?=f($weak['forward_edge_percent']??null)?>%</b></div><div class="small"><span>Net PnL</span><b><?=f($weak['unrealized_net_pnl_percent']??null)?>%</b></div><div class="small"><span>Estimated Exit Cost</span><b><?=f($weak['estimated_exit_cost_percent']??null)?>%</b></div><div class="small"><span>Hold Time</span><b><?=h($weak['hold_minutes']??'—')?> دقیقه</b></div></div><?php else:?><div class="empty">پوزیشن واجد شرایط رتبه‌بندی وجود ندارد.</div><?php endif?></div><div class="pair best"><h3>بهترین جایگزین</h3><?php if($best):?><div class="edge"><?=h($best['symbol']??'—')?></div><div class="small-grid"><div class="small"><span>Tradable Edge</span><b><?=f($best['tradable_net_edge_percent']??null)?>%</b></div><div class="small"><span>Execution Quality</span><b><?=f($best['execution_quality_score']??null,1)?></b></div><div class="small"><span>Quote</span><b><?=h($best['quote_asset']??'—')?></b></div><div class="small"><span>Signal Age</span><b><?=h($best['age_seconds']??'—')?>s</b></div></div><?php else:?><div class="empty">فرصت جایگزین تازه‌ای پیدا نشده است.</div><?php endif?></div></div>
<div class="metric-grid" style="margin-top:10px"><div class="metric"><span>اختلاف Edge</span><b><?=f($adv)?>%</b></div><div class="metric"><span>حداقل لازم</span><b><?=f($req)?>%</b></div><div class="metric"><span>مازاد</span><b><?=f($sur)?>%</b></div></div><div style="margin-top:10px"><div class="progress"><i style="width:<?=$ratio?>%"></i></div><div class="progress-labels"><span>برتری فعلی</span><span><?=f($ratio,0)?>% از Threshold</span></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>Rotation Policy</h2><p>گاردهای جلوگیری از Churn و تعویض زودهنگام.</p></div><span class="badge info">POLICY</span></div><div class="metric-grid"><div class="metric"><span>Enabled</span><b><?=($config['enabled']??false)?'YES':'NO'?></b></div><div class="metric"><span>Min Hold</span><b><?=h($config['minimum_hold_minutes']??'—')?> min</b></div><div class="metric"><span>Cooldown</span><b><?=h($config['cooldown_minutes']??'—')?> min</b></div><div class="metric"><span>Min Advantage</span><b><?=f($config['minimum_advantage_percent']??null)?>%</b></div><div class="metric"><span>Loss Guard</span><b><?=f($config['maximum_loss_percent']??null)?>%</b></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>تاریخچه Rotation</h2><p>۳۰ رخداد اخیر ثبت‌شده.</p></div><span class="badge info"><?=count($history)?> EVENT</span></div><div class="table-wrap"><table><thead><tr><th>UTC</th><th>Event</th><th>Symbol</th><th>Detail</th></tr></thead><tbody><?php if($history===[]):?><tr><td colspan="4" class="empty">هنوز رخدادی ثبت نشده است.</td></tr><?php endif?><?php foreach($history as $row):$details=is_array($row['details']??null)?$row['details']:(json_decode((string)($row['details_json']??''),true)?:[]);?><tr><td><?=h($row['created_at']??'—')?></td><td><?=h(eventFa((string)($row['event']??$row['event_type']??'—')))?></td><td><b><?=h($details['symbol']??$row['symbol']??'—')?></b></td><td><?=h($details['reason']??$details['message']??'—')?></td></tr><?php endforeach?></tbody></table></div></section>
<?php endif?>
<?php tradeAdminFooter($version); ?>
</div></body></html>