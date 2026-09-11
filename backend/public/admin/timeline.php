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
function timelineKind(array $e):array{
    $type=(string)($e['type']??'buy');
    return match($type){
        'sell'=>['sell','🔴 فروش تأیید شد','bad'],
        'external_sell'=>['sell','🟠 فروش/کاهش دستی شناسایی شد','warn'],
        default=>['buy','🟢 خرید تأیید شد','good'],
    };
}
$version=Updater::currentVersion();require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تایم‌لاین معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('timeline',$version);?>
<div class="page-head"><div><div class="page-eyebrow">TRADES / EXTERNAL RECONCILIATION / LIVE</div><h1>تایم‌لاین خرید و فروش</h1><p>Fillهای تأییدشده ربات و فروش/کاهش دستی تشخیص‌داده‌شده در نوبیتکس؛ مبالغ IRT به تومان و زمان‌ها به تقویم شمسی و ساعت ایران نمایش داده می‌شوند.</p></div><span class="badge good"><span class="live-dot"></span>زنده</span></div>
<section class="panel"><div class="panel-head"><div><h2>رویدادهای معامله</h2><p id="timelineUpdated">در حال همگام‌سازی…</p></div><span class="badge info" id="timelineCount"><?=count($timeline['items']??[])?> رویداد</span></div><div class="timeline" id="timeline">
<?php foreach(($timeline['items']??[]) as $e):[$cls,$label,$badge]=timelineKind($e);$external=($e['type']??'')==='external_sell';?><article class="timeline-item <?=$cls?>"><div class="timeline-title"><b><?=$label?> — <?=h($e['symbol']??'')?></b><span class="badge <?=$badge?>"><?=h($e['time_iran']['jalali_datetime']??'—')?></span></div><div class="timeline-meta"><div><span><?=$external?'مقدار کاهش':'مقدار'?></span><b><?=h(num($e['amount']??0,8))?></b></div><div><span>قیمت</span><b><?=$external?'نامشخص — خارج از ربات':h(num($e['price']??0,8)).' '.h(($e['display_unit']??'')==='TOMAN'?'تومان':($e['display_unit']??''))?></b></div><div><span><?=$external?'باقی‌مانده':'ارزش'?></span><b><?=$external?h(num($e['remaining_amount']??0,8)):h(num($e['value']??0,4))?></b></div><div><span><?=$external?'وضعیت':(($e['type']??'')==='sell'?'سود/زیان':'وضعیت')?></span><b class="<?=$external?'warn-text':((($e['type']??'')==='sell'&&($e['pnl']??0)<0)?'bad-text':'ok')?>"><?=$external?'همگام‌سازی شد — PnL ثبت نشد':((($e['type']??'')==='sell')?h(num($e['pnl']??0,4)).' ('.h(num($e['pnl_percent']??0,3)).'٪)':'تکمیل‌شده')?></b></div></div><?php if($external):?><div class="notice info"><?=h($e['note']??'تغییر موجودی خارج از ربات شناسایی شد.')?></div><?php endif?></article><?php endforeach;?>
</div></section>
<?php tradeAdminFooter($version);?></div><script>
function renderTimeline(data){const items=data.items||[],el=document.getElementById('timeline');document.getElementById('timelineCount').textContent=items.length+' رویداد';document.getElementById('timelineUpdated').textContent='آخرین بروزرسانی: '+TradeUI.iranFromPayload(data.generated_at_iran);if(!items.length){el.innerHTML='<div class="empty">هنوز رویداد معامله‌ای ثبت نشده است.</div>';return;}el.innerHTML=items.map(e=>{const external=e.type==='external_sell',sell=e.type==='sell',unit=e.display_unit==='TOMAN'?'تومان':(e.display_unit||''),time=TradeUI.iranFromPayload(e.time_iran),label=external?'🟠 فروش/کاهش دستی شناسایی شد':(sell?'🔴 فروش تأیید شد':'🟢 خرید تأیید شد'),badge=external?'warn':(sell?'bad':'good');const price=external?'نامشخص — خارج از ربات':TradeUI.formatNumber(e.price,8)+' '+TradeUI.escapeHtml(unit);const third=external?TradeUI.formatNumber(e.remaining_amount,8):TradeUI.formatNumber(e.value,4);const state=external?'همگام‌سازی شد — PnL ثبت نشد':(sell?TradeUI.formatNumber(e.pnl,4)+' ('+TradeUI.formatNumber(e.pnl_percent,3)+'٪)':'تکمیل‌شده');return `<article class="timeline-item ${external||sell?'sell':'buy'}"><div class="timeline-title"><b>${label} — ${TradeUI.escapeHtml(e.symbol||'')}</b><span class="badge ${badge}">${TradeUI.escapeHtml(time)}</span></div><div class="timeline-meta"><div><span>${external?'مقدار کاهش':'مقدار'}</span><b>${TradeUI.formatNumber(e.amount,8)}</b></div><div><span>قیمت</span><b>${price}</b></div><div><span>${external?'باقی‌مانده':'ارزش'}</span><b>${third}</b></div><div><span>${external?'وضعیت':(sell?'سود/زیان':'وضعیت')}</span><b class="${external?'warn-text':(sell&&Number(e.pnl)<0?'bad-text':'ok')}">${state}</b></div></div>${external?`<div class="notice info">${TradeUI.escapeHtml(e.note||'تغییر موجودی خارج از ربات شناسایی شد.')}</div>`:''}</article>`}).join('');}
window.addEventListener('DOMContentLoaded',()=>TradeUI.poll(async()=>{const j=await TradeUI.json('/admin/timeline.php?ajax=1');renderTimeline(j.data);},5000));
</script></body></html>
