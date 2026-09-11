<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Observability\BaleSystemAlert;
use Trade\Support\IranClock;
use Trade\Trading\NobitexDisplayMoney;
use Trade\Trading\NobitexExternalTradeReconciler;
use Trade\Trading\NobitexPositionReconciler;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

$pdo=Database::connection();
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function num(mixed $v,int $d=8):string{$s=number_format((float)$v,$d,'.',',');return rtrim(rtrim($s,'0'),'.');}
function counts(PDO $pdo):array{$out=['pending_open'=>0,'open'=>0,'pending_close'=>0,'active'=>0];$rows=$pdo->query("SELECT status,COUNT(*) c FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') GROUP BY status")->fetchAll();foreach($rows as $r){$s=(string)$r['status'];if(array_key_exists($s,$out))$out[$s]=(int)$r['c'];}$out['active']=$out['pending_open']+$out['open']+$out['pending_close'];return$out;}

$before=counts($pdo);$after=$before;$exact=null;$wallet=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/reconcile.php?csrf_refresh=1',true,303);exit;}
    try{
        // Exact trade history first, wallet comparison second as fallback.
        $exact=(new NobitexExternalTradeReconciler())->reconcile($pdo);
        $wallet=(new NobitexPositionReconciler())->reconcile($pdo);
        $after=counts($pdo);$alert=new BaleSystemAlert();
        foreach(($exact['events']??[]) as $event){
            if(!is_array($event))continue;$quote=(string)($event['quote_asset']??'IRT');$unit=strtoupper($quote)==='IRT'?'تومان':strtoupper($quote);
            $exit=NobitexDisplayMoney::quoteValue((float)($event['exit_price']??0),$quote);$net=NobitexDisplayMoney::quoteValue((float)($event['net_pnl']??0),$quote);
            $body='همگام‌سازی فوری، SELL دستی را از تاریخچه رسمی معاملات نوبیتکس تشخیص داد. بازار: '.(string)($event['symbol']??'').' | مقدار: '.num($event['sold_amount']??0).' | قیمت خروج: '.num($exit).' '.$unit.' | سود/زیان خالص: '.number_format($net,2,'.',',').' '.$unit.' | پوزیشن '.(($event['type']??'')==='closed'?'بسته شد':'کاهش یافت').'.';
            $alert->queue('manual-exact-trade-'.(string)($event['trade_id']??'').'-'.(int)($event['position_id']??0),'warning','فروش دستی نوبیتکس دقیقاً تطبیق داده شد',$body,['component'=>'external_trade_reconciliation','exchange'=>'nobitex','symbol'=>(string)($event['symbol']??''),'status'=>(string)($event['type']??'changed')],true,$pdo);
        }
        foreach(($wallet['events']??[]) as $event){
            if(!is_array($event))continue;$type=(string)($event['type']??'changed');$asset=(string)($event['asset']??'');$symbol=(string)($event['symbol']??'');
            $body='موجودی واقعی نوبیتکس کمتر از پوزیشن ثبت‌شده بود و به‌صورت fallback اصلاح شد. دارایی: '.($asset!==''?$asset:$symbol).' | مقدار قبلی: '.num($event['tracked_amount_before']??0).' | باقی‌مانده: '.num($event['remaining_amount']??0).' | Fill دقیق اثبات نشد، بنابراین PnL ساختگی ثبت نشد.';
            $alert->queue('manual-wallet-sync-'.$type.'-'.(int)($event['position_id']??0),'warning','اختلاف موجودی نوبیتکس با پوزیشن Trade اصلاح شد',$body,['component'=>'position_reconciliation','exchange'=>'nobitex','symbol'=>$symbol,'status'=>$type],true,$pdo);
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,900);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی منقضی شده بود؛ هیچ تغییری انجام نشد. دوباره همگام‌سازی را بزن.';

$recent=$pdo->query("SELECT level,event_name,context_json,created_at FROM nobitex_autotrade_events WHERE event_name IN ('nobitex.position.external_trade_applied','nobitex.position.external_close_detected','nobitex.position.external_resize_detected') ORDER BY id DESC LIMIT 25")->fetchAll();
$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>همگام‌سازی پوزیشن‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">EXTERNAL TRADE RECONCILIATION V2</div><h1>همگام‌سازی پوزیشن‌ها با نوبیتکس</h1><p>SELLهای دستی را از تاریخچه واقعی معاملات نوبیتکس تشخیص می‌دهد، سفارش‌های خود ربات را جدا می‌کند و سپس موجودی کیف پول را به‌عنوان کنترل دوم بررسی می‌کند.</p></div><span class="badge good">Trade History + Wallet</span></div>
<?php if($error!==''):?><div class="notice bad"><b>همگام‌سازی ناموفق:</b> <?=h($error)?></div><?php endif?>
<?php if(is_array($exact)||is_array($wallet)):$changes=(int)($exact['positions_changed']??0)+(int)($wallet['closed']??0)+(int)($wallet['resized']??0);?><div class="notice <?=$changes>0?'good':'info'?>"><b><?=$changes>0?'فروش/کاهش دستی شناسایی شد و ظرفیت پوزیشن بروزرسانی شد.':'اختلاف جدیدی برای اصلاح پیدا نشد.'?></b></div><?php endif?>

<div class="stat-grid"><div class="stat-card"><span>پوزیشن فعال قبل</span><b><?=h($before['active'])?></b></div><div class="stat-card"><span>پوزیشن فعال اکنون</span><b class="<?=($after['active']<$before['active'])?'ok':''?>"><?=h($after['active'])?></b></div><div class="stat-card"><span>SELL دستی دقیق</span><b><?=h($exact['manual_sells_applied']??0)?></b><small>از /market/trades/list</small></div><div class="stat-card"><span>اصلاح Wallet fallback</span><b><?=h(((int)($wallet['closed']??0)+(int)($wallet['resized']??0)))?></b></div></div>

<section class="panel soft"><div class="panel-head"><div><h2>همگام‌سازی فوری</h2><p>اول Order ID معاملات را با سفارش‌های Trade مقایسه می‌کند؛ فقط SELL خارجی/دستی روی پوزیشن اعمال می‌شود. بعد موجودی واقعی کیف پول برای اختلاف‌های باقی‌مانده بررسی می‌شود.</p></div><span class="badge good">کاهش / بستن فقط</span></div>
<div class="metric-grid"><div class="metric"><span>Open</span><b><?=h($after['open'])?></b></div><div class="metric"><span>Pending Buy</span><b><?=h($after['pending_open'])?></b></div><div class="metric"><span>Pending Sell</span><b><?=h($after['pending_close'])?></b></div></div>
<form method="post" style="margin-top:14px" onsubmit="return confirm('تاریخچه معاملات و موجودی واقعی نوبیتکس با پوزیشن‌های Trade همگام شود؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn safe" type="submit">همین الان Sync کامل نوبیتکس را اجرا کن</button></form>
<div class="notice info"><b>ایمنی:</b> این ابزار هیچ پوزیشن جدیدی از روی کیف پول ایجاد نمی‌کند و مقدار پوزیشن را بالا نمی‌برد. SELL متعلق به Order ID خود ربات نادیده گرفته می‌شود. وضعیت‌های Pending همچنان توسط Order Reconciliation کنترل می‌شوند تا حسابداری Fill ربات خراب نشود.</div></section>

<?php if(is_array($exact)&&($exact['events']??[])!==[]):?><section class="panel"><div class="panel-head"><div><h2>فروش‌های دستی دقیق همین Sync</h2><p>مقدار، قیمت خروج و PnL بر اساس Fill گزارش‌شده توسط نوبیتکس.</p></div><span class="badge warn"><?=count($exact['events'])?> تغییر</span></div><div class="table-wrap"><table><thead><tr><th>بازار</th><th>نوع</th><th>مقدار فروش</th><th>قیمت خروج خام صرافی</th><th>PnL خالص خام</th></tr></thead><tbody><?php foreach($exact['events'] as $e):?><tr><td><b><?=h($e['symbol']??'—')?></b></td><td><?=h(($e['type']??'')==='closed'?'بستن کامل':'فروش جزئی')?></td><td><?=h(num($e['sold_amount']??0))?></td><td><?=h(num($e['exit_price']??0))?></td><td><?=h(num($e['net_pnl']??0))?></td></tr><?php endforeach?></tbody></table></div></section><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>آخرین تشخیص‌های خارجی</h2><p>رویدادهای دقیق Trade History و fallback کیف پول با زمان شمسی ایران.</p></div><span class="badge info">۲۵ رویداد اخیر</span></div><div class="table-wrap"><table><thead><tr><th>زمان ایران</th><th>نوع</th><th>بازار</th><th>وضعیت</th></tr></thead><tbody><?php if($recent===[]):?><tr><td colspan="4" class="empty">هنوز رویدادی ثبت نشده است.</td></tr><?php endif?><?php foreach($recent as $row):$ctx=json_decode((string)($row['context_json']??''),true);$ctx=is_array($ctx)?$ctx:[];$name=(string)$row['event_name'];$label=$name==='nobitex.position.external_trade_applied'?'SELL دستی دقیق':($name==='nobitex.position.external_close_detected'?'بستن Wallet fallback':'کاهش Wallet fallback');?><tr><td><?=h(IranClock::fromUtc((string)$row['created_at']))?></td><td><?=h($label)?></td><td><b><?=h($ctx['symbol']??$ctx['asset']??'—')?></b></td><td><span class="badge warn"><?=h($row['level'])?></span></td></tr><?php endforeach?></tbody></table></div></section>
<?php tradeAdminFooter($version); ?></div></body></html>
