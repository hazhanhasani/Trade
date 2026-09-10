<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexRotationMonitor;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function f(mixed $value, int $digits=3): string { return is_numeric($value) ? number_format((float)$value, $digits, '.', ',') : '—'; }
function reasonFa(string $reason): string { return match ($reason) {
    'superior_opportunity_after_rotation_costs'=>'فرصت جایگزین پس از کسر هزینه و حاشیه اصطکاک، برتری کافی دارد',
    'portfolio_has_free_slot'=>'پورتفو هنوز اسلات آزاد دارد و نیازی به جایگزینی نیست',
    'pending_order_present'=>'تا تعیین تکلیف سفارش Pending، Rotation متوقف است',
    'rotation_cooldown_active'=>'Cooldown تعویض هنوز تمام نشده است',
    'no_profitable_replacement_candidate'=>'در سیگنال‌های تازه، فرصت خرید جایگزین سودمند پیدا نشده است',
    'no_rotation_eligible_position'=>'هیچ پوزیشن فعلی شرایط حداقل زمان نگهداری و سقف زیان Rotation را ندارد',
    'replacement_advantage_insufficient'=>'برتری فرصت جدید برای جبران Edge پوزیشن فعلی و هزینه تعویض کافی نیست',
    'position_signal_data_stale'=>'داده پایش پوزیشن‌ها تازه نیست؛ تصمیم نمایشی تا اسکن بعدی معتبر نیست',
    'rotation_disabled'=>'Portfolio Rotation غیرفعال است',
    'kill_switch'=>'توقف اضطراری فعال است',
    'rotation_preview_unavailable'=>'داده کافی برای پیش‌نمایش Rotation موجود نیست',
    default=>$reason,
}; }
function eventFa(string $event): string { return match ($event) {
    'nobitex.rotation.sell_submitted'=>'فروش برای Rotation ارسال شد',
    'nobitex.rotation.local_state_conflict'=>'نیاز به همگام‌سازی وضعیت پس از فروش',
    'nobitex.rotation.position_scan_failed'=>'پایش یکی از پوزیشن‌ها ناموفق بود',
    default=>$event,
}; }
function stateFa(string $state): string { return match ($state) {
    'ready'=>'آماده جایگزینی',
    'standby'=>'آماده‌باش',
    'cooldown'=>'در Cooldown',
    'blocked'=>'مسدود',
    'waiting_data'=>'در انتظار داده تازه',
    'disabled'=>'غیرفعال',
    default=>'در حال پایش',
}; }

try {
    $rotation=(new NobitexRotationMonitor())->snapshot(30);
    $error='';
} catch (Throwable $e) {
    $rotation=[];
    $error=mb_substr($e->getMessage(),0,700);
}

$state=(string)($rotation['state']??'error');
$reason=(string)($rotation['reason']??'rotation_preview_unavailable');
$portfolio=is_array($rotation['portfolio']??null)?$rotation['portfolio']:[];
$weak=is_array($rotation['weakest_position']??null)?$rotation['weakest_position']:null;
$best=is_array($rotation['best_replacement']??null)?$rotation['best_replacement']:null;
$plan=is_array($rotation['preview_plan']??null)?$rotation['preview_plan']:[];
$cooldown=is_array($rotation['cooldown']??null)?$rotation['cooldown']:[];
$config=is_array($rotation['config']??null)?$rotation['config']:[];
$history=is_array($rotation['history']??null)?$rotation['history']:[];
$stateClass=in_array($state,['ready'],true)?'good':(in_array($state,['blocked','error','disabled'],true)?'bad':'warn');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="20"><title>Portfolio Rotation — Trade</title>
<style>
:root{--bg:#f6f7fb;--card:#fff;--ink:#171a24;--muted:#73798b;--line:#e6e9f0;--violet:#6941ff;--green:#0b936c;--red:#cf3f4b;--amber:#ad6900;--blue:#2761d8;--greenSoft:#eaf8f3;--redSoft:#fff0f1;--amberSoft:#fff7e8;--blueSoft:#eef5ff;--violetSoft:#f1edff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:22px}.top,.sectionTitle,.pairHead{display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;gap:12px;align-items:center}.logo{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,#6941ff,#8b5cf6);color:#fff;font-size:22px;font-weight:900}.top h1,.sectionTitle h2{margin:0}.top h1{font-size:23px}.muted{color:var(--muted);font-size:12px;line-height:1.8}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{padding:10px 13px;border-radius:12px;text-decoration:none;color:#fff;background:var(--violet);font-weight:700;font-size:12px}.btn.gray{background:#667085}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:17px;margin-top:14px;box-shadow:0 10px 34px #1b274008}.hero{background:linear-gradient(135deg,#fff,#f7f4ff)}.badge{display:inline-block;padding:7px 10px;border-radius:999px;font-size:11px;font-weight:800}.badge.good{background:var(--greenSoft);color:var(--green)}.badge.bad{background:var(--redSoft);color:var(--red)}.badge.warn{background:var(--amberSoft);color:var(--amber)}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:14px}.metric{padding:12px;border:1px solid var(--line);border-radius:14px;background:#fff}.metric span{display:block;font-size:10px;color:var(--muted)}.metric b{display:block;margin-top:6px;font-size:16px}.pairs{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.pair{border:1px solid var(--line);border-radius:17px;padding:15px}.pair.weak{background:var(--amberSoft)}.pair.best{background:var(--greenSoft)}.pair h3{margin:0;font-size:17px}.edge{font-size:28px;font-weight:900;margin-top:12px}.smallGrid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:12px}.small{padding:9px;border-radius:11px;background:#ffffffb8}.small span{display:block;color:var(--muted);font-size:10px}.small b{display:block;margin-top:4px;font-size:12px}.decision{padding:15px;border-radius:16px;margin-top:12px;background:var(--violetSoft);line-height:1.9}.decision.good{background:var(--greenSoft)}.decision.bad{background:var(--redSoft)}.decision.warn{background:var(--amberSoft)}.progress{height:10px;background:#ebeef4;border-radius:999px;overflow:hidden;margin-top:8px}.progress>i{display:block;height:100%;background:linear-gradient(90deg,#6941ff,#0b936c);border-radius:999px}.scroll{overflow:auto;margin-top:10px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:right;padding:10px;border-bottom:1px solid #edf0f4;white-space:nowrap}th{color:var(--muted)}.notice{background:var(--blueSoft);color:#244c93;padding:12px 14px;border-radius:14px;margin-top:14px;font-size:12px;line-height:1.9}.error{background:var(--redSoft);color:var(--red);padding:13px;border-radius:14px;margin-top:14px}.dot{width:9px;height:9px;border-radius:50%;display:inline-block;background:var(--green);margin-left:6px}@media(max-width:800px){.grid4{grid-template-columns:1fr 1fr}.pairs{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}@media(max-width:480px){.wrap{padding:12px}.grid4,.smallGrid{grid-template-columns:1fr 1fr}.actions,.actions .btn{width:100%}.actions .btn{text-align:center}.edge{font-size:23px}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand"><div class="logo">R</div><div><h1>Portfolio Rotation</h1><div class="muted"><span class="dot"></span>پایش خودکار ضعیف‌ترین پوزیشن و بهترین فرصت جایگزین • بروزرسانی صفحه هر ۲۰ ثانیه</div></div></div><div class="actions"><a class="btn" href="trade://rotation">باز کردن در اپ</a><a class="btn" href="/admin/bot/rotation.php">بروزرسانی</a><a class="btn gray" href="/admin/bot/">ربات معامله‌گر</a><a class="btn gray" href="/admin/">پنل اصلی</a></div></div>
<?php if($error!==''):?><div class="error"><?=h($error)?></div><?php else:?>
<div class="card hero"><div class="sectionTitle"><div><h2>وضعیت Rotation</h2><div class="muted">آخرین محاسبه: <?=h($rotation['generated_at']??'—')?> • TTL سیگنال: <?=h($rotation['signal_ttl_seconds']??300)?> ثانیه</div></div><span class="badge <?=$stateClass?>"><?=h(stateFa($state))?></span></div>
<div class="grid4"><div class="metric"><span>ظرفیت پورتفو</span><b><?=h(($portfolio['active_positions']??0).'/'.($portfolio['max_positions']??0))?></b></div><div class="metric"><span>Pending</span><b><?=h($portfolio['pending_orders']??0)?></b></div><div class="metric"><span>سیگنال تازه پوزیشن</span><b><?=h($portfolio['fresh_position_signals']??0)?> / <?=h($portfolio['open_positions']??0)?></b></div><div class="metric"><span>Cooldown باقی‌مانده</span><b><?=h($cooldown['remaining_seconds']??0)?>s</b></div></div>
<div class="decision <?=$stateClass?>"><b><?=h(reasonFa($reason))?></b><?php if(($cooldown['active']??false)===true):?><div class="muted">پایان Cooldown: <?=h($cooldown['until']??'—')?></div><?php endif?></div></div>

<div class="card"><div class="sectionTitle"><h2>مقایسه فرصت</h2><span class="muted">داده نمایشی از آخرین سیگنال‌های ذخیره‌شده است</span></div><div class="pairs">
<div class="pair weak"><div class="pairHead"><h3>ضعیف‌ترین پوزیشن</h3><span class="badge warn">CURRENT</span></div><?php if($weak):?><div class="edge"><?=h($weak['symbol']??'—')?></div><div class="smallGrid"><div class="small"><span>Forward Edge</span><b><?=f($weak['forward_edge_percent']??null)?>%</b></div><div class="small"><span>Net PnL</span><b><?=f($weak['unrealized_net_pnl_percent']??null)?>%</b></div><div class="small"><span>هزینه خروج تخمینی</span><b><?=f($weak['estimated_exit_cost_percent']??null)?>%</b></div><div class="small"><span>زمان نگهداری</span><b><?=h($weak['hold_minutes']??'—')?> دقیقه</b></div></div><div class="muted">آخرین سیگنال: <?=h($weak['signal_at']??'—')?> UTC</div><?php else:?><p class="muted">هنوز پوزیشن باز با سیگنال تازه برای رتبه‌بندی وجود ندارد.</p><?php endif?></div>
<div class="pair best"><div class="pairHead"><h3>بهترین جایگزین</h3><span class="badge good">OPPORTUNITY</span></div><?php if($best):?><div class="edge"><?=h($best['symbol']??'—')?></div><div class="smallGrid"><div class="small"><span>Tradable Edge</span><b><?=f($best['tradable_net_edge_percent']??null)?>%</b></div><div class="small"><span>Execution Quality</span><b><?=f($best['execution_quality_score']??null,1)?></b></div><div class="small"><span>Quote</span><b><?=h($best['quote_asset']??'—')?></b></div><div class="small"><span>سن سیگنال</span><b><?=h($best['age_seconds']??'—')?>s</b></div></div><div class="muted">آخرین سیگنال: <?=h($best['signal_at']??'—')?> UTC</div><?php else:?><p class="muted">فرصت خرید تازه‌ای که خارج از دارایی‌های فعال باشد پیدا نشده است.</p><?php endif?></div>
</div>
<?php $adv=$plan['advantage_percent']??null;$req=$plan['required_advantage_percent']??null;$sur=$plan['advantage_surplus_percent']??null;$ratio=(is_numeric($adv)&&is_numeric($req)&&(float)$req>0)?max(0,min(100,((float)$adv/(float)$req)*100)):0;?>
<div class="grid4"><div class="metric"><span>اختلاف Edge</span><b><?=f($adv)?>%</b></div><div class="metric"><span>حداقل لازم برای تعویض</span><b><?=f($req)?>%</b></div><div class="metric"><span>مازاد بر حد تعویض</span><b><?=f($sur)?>%</b></div><div class="metric"><span>نتیجه Preview</span><b class="<?=($plan['rotate']??false)?'':'muted'?>"><?=($plan['rotate']??false)?'ROTATE':'HOLD'?></b></div></div><div class="progress" title="Advantage / Required"><i style="width:<?=h((string)$ratio)?>%"></i></div></div>

<div class="card"><div class="sectionTitle"><h2>گاردهای Rotation</h2><span class="badge <?=($config['enabled']??false)?'good':'bad'?>"><?=($config['enabled']??false)?'ENABLED':'DISABLED'?></span></div><div class="grid4"><div class="metric"><span>حداقل برتری Edge</span><b><?=f($config['min_advantage_percent']??null,2)?>%</b></div><div class="metric"><span>حداقل زمان نگهداری</span><b><?=h($config['min_hold_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>Cooldown</span><b><?=h($config['cooldown_minutes']??'—')?> دقیقه</b></div><div class="metric"><span>حداکثر زیان قابل Rotation</span><b><?=f($config['max_rotation_loss_percent']??null,2)?>%</b></div></div><div class="muted" style="margin-top:9px">Friction margin: <?=f($config['friction_margin_percent']??null,2)?>% • هدف قبلی: <?=h($rotation['last_target_symbol']??'—')?></div></div>

<div class="card"><div class="sectionTitle"><h2>تاریخچه Rotation</h2><span class="muted"><?=count($history)?> رویداد اخیر</span></div><div class="scroll"><table><tr><th>UTC</th><th>رویداد</th><th>پوزیشن خروجی</th><th>فرصت جایگزین</th><th>Edge Difference</th><th>Required</th></tr><?php foreach($history as $row):$ctx=is_array($row['context']??null)?$row['context']:[];$victim=is_array($ctx['victim']??null)?$ctx['victim']:[];$candidate=is_array($ctx['candidate']??null)?$ctx['candidate']:[];?><tr><td><?=h($row['created_at']??'—')?></td><td><?=h(eventFa((string)($row['event_name']??'')))?></td><td><?=h($victim['symbol']??($ctx['symbol']??'—'))?></td><td><?=h($candidate['symbol']??'—')?></td><td><?=isset($ctx['advantage_percent'])?f($ctx['advantage_percent']).'%':'—'?></td><td><?=isset($ctx['required_advantage_percent'])?f($ctx['required_advantage_percent']).'%':'—'?></td></tr><?php endforeach?><?php if($history===[]):?><tr><td colspan="6" class="muted">هنوز رویداد Rotation ثبت نشده است.</td></tr><?php endif?></table></div></div>
<div class="notice"><b>نکته اجرایی:</b> این صفحه فقط وضعیت را توضیح می‌دهد. حتی اگر Preview روی ROTATE باشد، موتور واقعی قبل از فروش دوباره Order Book و سیگنال بازار را از نوبیتکس می‌گیرد و تمام گاردهای Live، Kill Switch، Pending، PnL، زمان نگهداری، هزینه خروج و Cooldown را مجدداً بررسی می‌کند.</div>
<?php endif?></div></body></html>
