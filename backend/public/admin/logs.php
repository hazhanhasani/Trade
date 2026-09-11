<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Observability\SystemObservability;
use Trade\Updater;

if(!Config::installed()){header('Location: /install/');exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if(!isset($_SESSION['admin_id'])){header('Location: /admin/');exit;}

$snapshot=(new SystemObservability())->snapshot();
if(($_GET['ajax']??'')==='1'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$version=Updater::currentVersion();require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>خطاها و لاگ‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('system',$version);?>
<div class="page-head"><div><div class="page-eyebrow">OBSERVABILITY / LIVE</div><h1>خطاها و لاگ‌های حساس</h1><p>هشدارهای PHP، Runtime، API و Cron به‌صورت زنده ثبت می‌شوند؛ خطاهای مهم در صورت فعال بودن بله، برای همان کانال صف و ارسال می‌شوند.</p></div><span class="badge bad"><span class="live-dot"></span>پایش حساس</span></div>
<div class="stat-grid"><div class="stat-card"><span>خطاهای ۶۰ دقیقه اخیر</span><b class="bad-text" id="errTotal"><?=h($snapshot['errors_last_60m']['total']??0)?></b></div><div class="stat-card"><span>بحرانی</span><b class="bad-text" id="errCritical"><?=h($snapshot['errors_last_60m']['critical']??0)?></b></div><div class="stat-card"><span>هشدار</span><b class="warn-text" id="errWarning"><?=h($snapshot['errors_last_60m']['warning']??0)?></b></div><div class="stat-card"><span>صف هشدار بله</span><b id="balePending"><?=h($snapshot['bale_system_alerts']['pending']??0)?></b></div></div>
<section class="panel"><div class="panel-head"><div><h2>رویدادهای اخیر</h2><p id="logUpdated">آخرین بروزرسانی: <?=h($snapshot['time_iran']['jalali_datetime']??'—')?></p></div><span class="badge info" id="logCount"><?=count($snapshot['recent_errors']??[])?> رکورد</span></div><div id="logRows"><?php foreach(($snapshot['recent_errors']??[]) as $e):?><div class="log-row <?=h($e['severity']??'error')?>"><div class="log-head"><b><?=h(strtoupper((string)($e['severity']??'error')))?> • <?=h($e['component']??'runtime')?></b><span class="badge"><?=h($e['time_iran']['jalali_datetime']??'—')?></span></div><div class="log-message"><?=h($e['message']??'')?></div><div class="muted mono-ltr" style="margin-top:5px"><?=h(basename((string)($e['file']??'')))?>:<?=h($e['line']??0)?> • <?=h(substr((string)($e['fingerprint']??''),0,16))?></div></div><?php endforeach;?></div></section>
<?php tradeAdminFooter($version);?></div><script>
function renderLogs(d){TradeUI.setText('#errTotal',d.errors_last_60m?.total??0);TradeUI.setText('#errCritical',d.errors_last_60m?.critical??0);TradeUI.setText('#errWarning',d.errors_last_60m?.warning??0);TradeUI.setText('#balePending',d.bale_system_alerts?.pending??0);document.getElementById('logUpdated').textContent='آخرین بروزرسانی: '+TradeUI.iranFromPayload(d.time_iran);const rows=d.recent_errors||[];document.getElementById('logCount').textContent=rows.length+' رکورد';document.getElementById('logRows').innerHTML=rows.length?rows.map(e=>`<div class="log-row ${TradeUI.escapeHtml(e.severity||'error')}"><div class="log-head"><b>${TradeUI.escapeHtml((e.severity||'error').toUpperCase())} • ${TradeUI.escapeHtml(e.component||'runtime')}</b><span class="badge">${TradeUI.escapeHtml(TradeUI.iranFromPayload(e.time_iran))}</span></div><div class="log-message">${TradeUI.escapeHtml(e.message||'')}</div><div class="muted mono-ltr" style="margin-top:5px">${TradeUI.escapeHtml((e.file||'').split('/').pop())}:${TradeUI.escapeHtml(e.line||0)} • ${TradeUI.escapeHtml((e.fingerprint||'').slice(0,16))}</div></div>`).join(''):'<div class="empty">هیچ خطای ثبت‌شده‌ای در بازه قابل مشاهده وجود ندارد.</div>';}
window.addEventListener('DOMContentLoaded',()=>TradeUI.poll(async()=>{const j=await TradeUI.json('/admin/logs.php?ajax=1');renderLogs(j.data);},4000));
</script></body></html>
