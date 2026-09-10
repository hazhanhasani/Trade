<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Trading\NobitexTradeTimeline;
use Trade\Updater;

if(!Config::installed()){header('Location: /install/');exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if(!isset($_SESSION['admin_id'])){header('Location: /admin/');exit;}

$timeline=(new NobitexTradeTimeline())->snapshot(null,100);
if(($_GET['ajax']??'')==='1'){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'data'=>$timeline],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function num(mixed $v,int $d=4):string{return number_format((float)$v,$d,'.',',');}
$version=Updater::currentVersion();require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تایم‌لاین معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('timeline',$version);?>
<div class="page-head"><div><div class="page-eyebrow">CONFIRMED TRADES / LIVE</div><h1>تایم‌لاین خرید و فروش</h1><p>فقط Fillهای تأییدشده نوبیتکس؛ مبالغ IRT به تومان و زمان‌ها به تقویم شمسی و ساعت ایران نمایش داده می‌شوند.</p></div><span class="badge good"><span class="live-dot"></span>زنده</span></div>
<section class="panel"><div class="panel-head"><div><h2>رویدادهای معامله</h2><p id="timelineUpdated">در حال همگام‌سازی…</p></div><span class="badge info" id="timelineCount"><?=count($timeline['items']??[])?> رویداد</span></div><div class="timeline" id="timeline">
<?php foreach(($timeline['items']??[]) as $e):$sell=($e['type']??'')==='sell';?><article class="timeline-item <?=$sell?'sell':'buy'?>"><div class="timeline-title"><b><?=$sell?'🔴 فروش تأیید شد':'🟢 خرید تأیید شد'?> — <?=h($e['symbol']??'')?></b><span class="badge <?=$sell?'bad':'good'?>"><?=h($e['time_iran']['jalali_datetime']??'—')?></span></div><div class="timeline-meta"><div><span>مقدار</span><b><?=h(num($e['amount']??0,8))?></b></div><div><span>قیمت</span><b><?=h(num($e['price']??0,8))?> <?=h(($e['display_unit']??'')==='TOMAN'?'تومان':($e['display_unit']??''))?></b></div><div><span>ارزش</span><b><?=h(num($e['value']??0,4))?></b></div><div><span><?=$sell?'سود/زیان':'وضعیت'?></span><b class="<?=$sell&&($e['pnl']??0)<0?'bad-text':'ok'?>"><?=$sell?h(num($e['pnl']??0,4)).' ('.h(num($e['pnl_percent']??0,3)).'٪)':'تکمیل‌شده'?></b></div></div></article><?php endforeach;?>
</div></section>
<?php tradeAdminFooter($version);?></div><script>
function renderTimeline(data){const items=data.items||[],el=document.getElementById('timeline');document.getElementById('timelineCount').textContent=items.length+' رویداد';document.getElementById('timelineUpdated').textContent='آخرین بروزرسانی: '+TradeUI.iranFromPayload(data.generated_at_iran);if(!items.length){el.innerHTML='<div class="empty">هنوز معامله تأییدشده‌ای ثبت نشده است.</div>';return;}el.innerHTML=items.map(e=>{const sell=e.type==='sell',unit=e.display_unit==='TOMAN'?'تومان':e.display_unit,time=TradeUI.iranFromPayload(e.time_iran);return `<article class="timeline-item ${sell?'sell':'buy'}"><div class="timeline-title"><b>${sell?'🔴 فروش تأیید شد':'🟢 خرید تأیید شد'} — ${TradeUI.escapeHtml(e.symbol)}</b><span class="badge ${sell?'bad':'good'}">${TradeUI.escapeHtml(time)}</span></div><div class="timeline-meta"><div><span>مقدار</span><b>${TradeUI.formatNumber(e.amount,8)}</b></div><div><span>قیمت</span><b>${TradeUI.formatNumber(e.price,8)} ${TradeUI.escapeHtml(unit)}</b></div><div><span>ارزش</span><b>${TradeUI.formatNumber(e.value,4)}</b></div><div><span>${sell?'سود/زیان':'وضعیت'}</span><b class="${sell&&Number(e.pnl)<0?'bad-text':'ok'}">${sell?TradeUI.formatNumber(e.pnl,4)+' ('+TradeUI.formatNumber(e.pnl_percent,3)+'٪)':'تکمیل‌شده'}</b></div></div></article>`}).join('');}
window.addEventListener('DOMContentLoaded',()=>TradeUI.poll(async()=>{const j=await TradeUI.json('/admin/timeline.php?ajax=1');renderTimeline(j.data);},5000));
</script></body></html>
