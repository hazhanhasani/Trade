<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Intelligence\MarketContextService;
use Trade\Observability\ErrorReporter;
use Trade\Updater;

if(!Config::installed()){header('Location: /install/');exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if(!isset($_SESSION['admin_id'])){header('Location: /admin/');exit;}

try{$snapshot=(new MarketContextService())->snapshot(($_GET['force']??'')==='1');}catch(Throwable $e){ErrorReporter::captureThrowable($e,'warning','admin_market_context');$snapshot=['markets'=>[],'news'=>['items'=>[]],'source_health'=>[],'generated_at_iran'=>null,'error'=>$e->getMessage()];}
if(($_GET['ajax']??'')==='1'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function n(mixed $v,int $d=4):string{return is_numeric($v)?number_format((float)$v,$d,'.',','):'—';}
$version=Updater::currentVersion();require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بازار و اخبار — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('market',$version);?>
<div class="page-head"><div><div class="page-eyebrow">MARKET CONTEXT / READ ONLY</div><h1>بازار، صرافی‌ها و اخبار</h1><p>نمای مقایسه‌ای از بازارهای جهانی و خبرهای مرتبط. این داده‌ها فقط برای آگاهی و پایش هستند و مستقیماً سفارش ایجاد نمی‌کنند.</p></div><span class="badge info"><span class="live-dot"></span>خواندنی</span></div>
<section class="panel soft"><div class="panel-head"><div><h2>سلامت منابع</h2><p id="marketUpdated">آخرین دریافت: <?=h($snapshot['generated_at_iran']['jalali_datetime']??'—')?></p></div><button class="btn soft" type="button" id="forceMarket">بروزرسانی اجباری</button></div><div class="metric-grid"><div class="metric"><span>صرافی‌های در دسترس</span><b id="healthyExchanges"><?=h(($snapshot['source_health']['healthy_exchanges']??0).'/'.($snapshot['source_health']['total_exchanges']??3))?></b></div><div class="metric"><span>اخبار</span><b id="newsHealth"><?=h($snapshot['source_health']['news_status']??'—')?></b></div><div class="metric"><span>نقش داده</span><b>فقط پایش / بدون گیت معامله</b></div></div></section>
<div class="panel-grid" id="exchangePanels">
<?php foreach(($snapshot['markets']??[]) as $key=>$m):?><section class="panel"><div class="panel-head"><div><h2><?=h(ucfirst($key))?></h2><p><?=h($m['source']??'')?></p></div><span class="badge <?=($m['status']??'')==='ok'?'good':'bad'?>"><?=h($m['status']??'unknown')?></span></div><div class="table-wrap"><table><thead><tr><th>بازار</th><th>آخرین</th><th>Bid</th><th>Ask</th><th>تغییر ۲۴ساعته</th></tr></thead><tbody><?php foreach(($m['items']??[]) as $row):?><tr><td><b><?=h($row['symbol']??'')?></b></td><td><?=h(n($row['last']??null,6))?></td><td><?=h(n($row['bid']??null,6))?></td><td><?=h(n($row['ask']??null,6))?></td><td><?=isset($row['change_24h_percent'])?h(n($row['change_24h_percent'],2)).'%':'—'?></td></tr><?php endforeach;?></tbody></table></div></section><?php endforeach;?>
</div>
<section class="panel"><div class="panel-head"><div><h2>خبرهای رمز ارز</h2><p>تیترهای جدید از منابع عمومی؛ خبر به‌تنهایی سیگنال خرید یا فروش نیست.</p></div><span class="badge info" id="newsCount"><?=count($snapshot['news']['items']??[])?> خبر</span></div><div class="news-list" id="newsList"><?php foreach(($snapshot['news']['items']??[]) as $item):?><a class="news-item <?=($item['attention']['high']??false)?'attention':''?>" href="<?=h($item['link']??'#')?>" target="_blank" rel="noopener noreferrer"><b><?=h($item['title']??'')?></b><small><?=h($item['source']??'')?> • <?=h($item['time_iran']['jalali_datetime']??'—')?><?=($item['attention']['high']??false)?' • نیازمند توجه':''?></small></a><?php endforeach;?></div></section>
<?php tradeAdminFooter($version);?></div><script>
function marketTable(m){const rows=Object.values(m.items||{});return `<section class="panel"><div class="panel-head"><div><h2>${TradeUI.escapeHtml((m.source||'').split(' ')[0]||'Exchange')}</h2><p>${TradeUI.escapeHtml(m.source||'')}</p></div><span class="badge ${m.status==='ok'?'good':'bad'}">${TradeUI.escapeHtml(m.status||'unknown')}</span></div><div class="table-wrap"><table><thead><tr><th>بازار</th><th>آخرین</th><th>Bid</th><th>Ask</th><th>تغییر ۲۴ساعته</th></tr></thead><tbody>${rows.map(r=>`<tr><td><b>${TradeUI.escapeHtml(r.symbol)}</b></td><td>${TradeUI.formatNumber(r.last,6)}</td><td>${TradeUI.formatNumber(r.bid,6)}</td><td>${TradeUI.formatNumber(r.ask,6)}</td><td>${r.change_24h_percent==null?'—':TradeUI.formatNumber(r.change_24h_percent,2)+'٪'}</td></tr>`).join('')}</tbody></table></div></section>`;}
function renderMarket(d){document.getElementById('healthyExchanges').textContent=`${d.source_health?.healthy_exchanges??0}/${d.source_health?.total_exchanges??3}`;document.getElementById('newsHealth').textContent=d.source_health?.news_status??'—';document.getElementById('marketUpdated').textContent='آخرین دریافت: '+TradeUI.iranFromPayload(d.generated_at_iran);document.getElementById('exchangePanels').innerHTML=Object.values(d.markets||{}).map(marketTable).join('');const items=d.news?.items||[];document.getElementById('newsCount').textContent=items.length+' خبر';document.getElementById('newsList').innerHTML=items.map(x=>`<a class="news-item ${x.attention?.high?'attention':''}" href="${TradeUI.escapeHtml(x.link||'#')}" target="_blank" rel="noopener noreferrer"><b>${TradeUI.escapeHtml(x.title)}</b><small>${TradeUI.escapeHtml(x.source)} • ${TradeUI.escapeHtml(TradeUI.iranFromPayload(x.time_iran))}${x.attention?.high?' • نیازمند توجه':''}</small></a>`).join('');}
async function refreshMarket(force=false){const j=await TradeUI.json('/admin/market.php?ajax=1'+(force?'&force=1':''));renderMarket(j.data);}
window.addEventListener('DOMContentLoaded',()=>{TradeUI.poll(()=>refreshMarket(false),60000);document.getElementById('forceMarket').addEventListener('click',()=>refreshMarket(true));});
</script></body></html>
