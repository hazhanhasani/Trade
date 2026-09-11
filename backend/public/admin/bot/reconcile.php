<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Observability\BaleSystemAlert;
use Trade\Support\IranClock;
use Trade\Trading\NobitexPositionReconciler;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

$pdo=Database::connection();
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function num(mixed $v,int $d=8):string{$s=number_format((float)$v,$d,'.',',');return rtrim(rtrim($s,'0'),'.');}
function counts(PDO $pdo):array{
    $out=['pending_open'=>0,'open'=>0,'pending_close'=>0,'active'=>0];
    $rows=$pdo->query("SELECT status,COUNT(*) c FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') GROUP BY status")->fetchAll();
    foreach($rows as $r){$s=(string)$r['status'];if(array_key_exists($s,$out))$out[$s]=(int)$r['c'];}
    $out['active']=$out['pending_open']+$out['open']+$out['pending_close'];
    return$out;
}

$before=counts($pdo);$after=$before;$result=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        header('Location: /admin/bot/reconcile.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $result=(new NobitexPositionReconciler())->reconcile($pdo);
        $after=counts($pdo);
        $alert=new BaleSystemAlert();
        foreach(($result['events']??[]) as $event){
            if(!is_array($event))continue;
            $type=(string)($event['type']??'changed');$asset=(string)($event['asset']??'');$symbol=(string)($event['symbol']??'');
            $beforeAmount=(float)($event['tracked_amount_before']??0);$remaining=(float)($event['remaining_amount']??0);
            $title=$type==='closed'?'فروش/خروج دستی از پوزیشن شناسایی شد':'کاهش دستی موجودی پوزیشن شناسایی شد';
            $body='همگام‌سازی فوری پنل، موجودی واقعی نوبیتکس را با پوزیشن Trade تطبیق داد. '
                .'دارایی: '.($asset!==''?$asset:$symbol)
                .' | مقدار ثبت‌شده: '.num($beforeAmount)
                .' | مقدار واقعی باقی‌مانده: '.num($remaining)
                .' | ظرفیت پوزیشن بروزرسانی شد. برای فروش بیرون از Trade سود/زیان ساختگی ثبت نشد.';
            $alert->queue('manual-position-sync-'.$type.'-'.(int)($event['position_id']??0),'warning',$title,$body,[
                'component'=>'position_reconciliation','exchange'=>'nobitex','symbol'=>$symbol,'status'=>$type,
            ],true,$pdo);
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,900);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی منقضی شده بود؛ هیچ تغییری انجام نشد. دوباره همگام‌سازی را بزن.';

$recent=$pdo->query("SELECT level,event_name,context_json,created_at FROM nobitex_autotrade_events WHERE event_name IN ('nobitex.position.external_close_detected','nobitex.position.external_resize_detected') ORDER BY id DESC LIMIT 20")->fetchAll();
$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>همگام‌سازی پوزیشن‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">NOBITEX POSITION RECONCILIATION</div><h1>همگام‌سازی پوزیشن‌ها با نوبیتکس</h1><p>اگر داخل خود نوبیتکس دارایی را دستی فروخته یا مقدارش را کم کرده‌ای، این ابزار موجودی واقعی کیف پول را با پوزیشن‌های باز Trade تطبیق می‌دهد و ظرفیت را فوراً آزاد می‌کند.</p></div><span class="badge info">فقط کاهش / بستن</span></div>

<?php if($error!==''):?><div class="notice bad"><b>همگام‌سازی ناموفق:</b> <?=h($error)?></div><?php endif?>
<?php if(is_array($result)):?>
<div class="notice <?=((int)($result['closed']??0)+(int)($result['resized']??0))>0?'good':'info'?>"><b><?php if(((int)($result['closed']??0)+(int)($result['resized']??0))>0):?>موجودی واقعی شناسایی و پوزیشن‌های داخلی اصلاح شدند.<?php else:?>اختلاف قابل‌توجهی بین پوزیشن‌های باز و موجودی واقعی پیدا نشد.<?php endif?></b></div>
<?php endif?>

<div class="stat-grid">
<div class="stat-card"><span>پوزیشن فعال قبل</span><b><?=h($before['active'])?></b></div>
<div class="stat-card"><span>پوزیشن فعال اکنون</span><b class="<?=($after['active']<$before['active'])?'ok':''?>"><?=h($after['active'])?></b></div>
<div class="stat-card"><span>بسته‌شده با Sync</span><b><?=h($result['closed']??0)?></b></div>
<div class="stat-card"><span>کاهش مقدار با Sync</span><b><?=h($result['resized']??0)?></b></div>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>همگام‌سازی فوری</h2><p>در حالت عادی Cron این بررسی را خودکار انجام می‌دهد. این دکمه برای زمانی است که همین الان در نوبیتکس فروش دستی انجام داده‌ای و نمی‌خواهی منتظر Tick بعدی بمانی.</p></div><span class="badge good">Wallet → Trade</span></div>
<div class="metric-grid"><div class="metric"><span>Open</span><b><?=h($after['open'])?></b></div><div class="metric"><span>Pending Buy</span><b><?=h($after['pending_open'])?></b></div><div class="metric"><span>Pending Sell</span><b><?=h($after['pending_close'])?></b></div></div>
<form method="post" style="margin-top:14px" onsubmit="return confirm('موجودی واقعی نوبیتکس با پوزیشن‌های باز Trade همگام شود؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn safe" type="submit">همین الان با نوبیتکس همگام کن</button></form>
<div class="notice info"><b>نکته ایمنی:</b> این ابزار هیچ پوزیشنی را از روی موجودی صرافی ایجاد یا بزرگ نمی‌کند. فقط وقتی موجودی واقعی کمتر از مقدار ثبت‌شده باشد، پوزیشن را کوچک یا بسته می‌کند. سفارش‌های Pending توسط موتور Order Reconciliation مدیریت می‌شوند تا Fill واقعی ربات با فروش دستی اشتباه نشود.</div>
</section>

<?php if(is_array($result)&&($result['events']??[])!==[]):?><section class="panel"><div class="panel-head"><div><h2>تغییرات همین Sync</h2><p>هر تغییری که روی پوزیشن‌ها اعمال شد.</p></div><span class="badge warn"><?=count($result['events'])?> تغییر</span></div><div class="table-wrap"><table><thead><tr><th>بازار</th><th>نوع</th><th>قبل</th><th>باقی‌مانده</th></tr></thead><tbody><?php foreach($result['events'] as $e):?><tr><td><b><?=h($e['symbol']??$e['asset']??'—')?></b></td><td><?=h(($e['type']??'')==='closed'?'بستن خارجی':'کاهش خارجی')?></td><td><?=h(num($e['tracked_amount_before']??0))?></td><td><?=h(num($e['remaining_amount']??0))?></td></tr><?php endforeach?></tbody></table></div></section><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>آخرین تشخیص‌های فروش/کاهش دستی</h2><p>رویدادها با زمان شمسی ایران نمایش داده می‌شوند و در صورت فعال بودن بله، هشدار نیز ارسال می‌شود.</p></div><span class="badge info">۲۰ رویداد اخیر</span></div><div class="table-wrap"><table><thead><tr><th>زمان ایران</th><th>نوع</th><th>بازار</th><th>وضعیت</th></tr></thead><tbody><?php if($recent===[]):?><tr><td colspan="4" class="empty">هنوز فروش دستی شناسایی‌شده‌ای ثبت نشده است.</td></tr><?php endif?><?php foreach($recent as $row):$ctx=json_decode((string)($row['context_json']??''),true);$ctx=is_array($ctx)?$ctx:[];?><tr><td><?=h(IranClock::fromUtc((string)$row['created_at']))?></td><td><?=h($row['event_name']==='nobitex.position.external_close_detected'?'فروش/خروج کامل':'کاهش مقدار')?></td><td><b><?=h($ctx['symbol']??$ctx['asset']??'—')?></b></td><td><span class="badge warn"><?=h($row['level'])?></span></td></tr><?php endforeach?></tbody></table></div></section>

<?php tradeAdminFooter($version); ?>
</div></body></html>
