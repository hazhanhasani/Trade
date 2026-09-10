<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/bootstrap.php';

use Trade\Config;
use Trade\Integrations\BaleTradeNotifier;
use Trade\Trading\TradeNotificationCenter;
use Trade\Updater;

if(!Config::installed()){header('Location:/install/');exit;}
session_name('trade_admin');session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);session_start();header('Cache-Control:no-store');
if(!isset($_SESSION['admin_id'])){header('Location:/admin/');exit;}
if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$center=new TradeNotificationCenter();$bale=new BaleTradeNotifier();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location:/admin/notifications.php?csrf_refresh=1',true,303);exit;}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='read_all'){$center->markRead();$message='همه اعلان‌ها خوانده شدند.';}
        elseif($action==='read_one'){$id=(int)($_POST['id']??0);if($id>0)$center->markRead($id);}
        elseif($action==='save_bale'){
            $enabled=(string)($_POST['enabled']??'0')==='1';
            $result=$bale->saveAndTest((string)($_POST['bot_token']??''),(string)($_POST['chat_id']??''),$enabled);
            $message='اتصال بله تست و ذخیره شد'.($enabled?' و ارسال خودکار روشن است.':'؛ ارسال خودکار فعلاً خاموش است.');
        }
        elseif($action==='test_bale'){$bale->testConfigured();$message='پیام آزمایشی با موفقیت به کانال بله ارسال شد.';}
        elseif($action==='toggle_bale'){$enabled=(string)($_POST['enabled']??'0')==='1';$bale->setEnabled($enabled);$message='ارسال خودکار بله '.($enabled?'روشن شد.':'خاموش شد.');}
        elseif($action==='disconnect_bale'){$bale->disconnect();$message='اتصال بله از پنل حذف شد.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی تازه شد؛ هیچ تغییری انجام نشد.';
$unread=$center->unreadCount();$items=$center->recent(80,false);$baleStatus=$bale->status();$version=Updater::currentVersion();$csrf=h((string)$_SESSION['csrf']);require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اعلان‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.feed{display:grid;gap:9px}.notification{display:grid;grid-template-columns:42px 1fr auto;gap:11px;align-items:start;padding:14px;border:1px solid var(--line);border-radius:17px;background:#fff}.notification.unread{background:linear-gradient(135deg,#fff,#f5f2ff);border-color:#ddd6ff}.icon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;font-weight:900}.icon.info{background:#edf4ff;color:#2563eb}.icon.success{background:#e8f7f1;color:#0b966d}.icon.warning{background:#fff6e7;color:#ad6908}.icon.critical{background:#fff0f0;color:#d04444}.notification h3{margin:0 0 5px;font-size:13px}.notification p{margin:0;color:var(--muted);font-size:11px;line-height:1.8}.notification small{display:block;color:var(--muted);margin-top:6px;font-size:9px}.steps{display:grid;gap:8px;margin-top:10px}.step{display:grid;grid-template-columns:30px 1fr;gap:9px;align-items:start;padding:10px;border:1px solid var(--line);border-radius:13px;background:var(--surface2)}.step b:first-child{width:28px;height:28px;border-radius:9px;background:#eee9ff;color:#6941ff;display:grid;place-items:center}.delivery-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.delivery-stat{padding:10px;border:1px solid var(--line);border-radius:13px;background:#fff}.delivery-stat small{display:block;color:var(--muted);margin-bottom:4px}.delivery-stat b{font-size:15px}@media(max-width:650px){.notification{grid-template-columns:38px 1fr}.notification form{grid-column:2}.delivery-grid{grid-template-columns:1fr}.step{grid-template-columns:28px 1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('notifications',$version);?>
<div class="page-head"><div><div class="page-eyebrow">EVENTS / NOTIFICATIONS</div><h1>مرکز اعلان‌ها</h1><p>اعلان‌های داخلی Trade و ارسال خودکار خرید و فروش به کانال بله را از همین صفحه مدیریت کن.</p></div><span class="badge <?=$unread>0?'warn':'good'?>"><?=$unread?> خوانده‌نشده</span></div>
<div class="subnav"><a href="/admin/system.php">سیستم</a><a class="active" href="/admin/notifications.php">اعلان‌ها</a></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel soft">
<div class="panel-head"><div><h2>ارسال معاملات به کانال بله</h2><p>بعد از تأیید واقعی خرید یا فروش در نوبیتکس، اطلاعات معامله حداکثر در چرخه بعدی Cron به کانال ارسال می‌شود.</p></div><span class="badge <?=($baleStatus['enabled']??false)&&($baleStatus['configured']??false)?'good':'warn'?>"><?=($baleStatus['enabled']??false)?'فعال':'خاموش'?></span></div>
<div class="notice info">بازو را داخل کانال بله اضافه کن و دسترسی ارسال پیام بده. برای کانال عمومی می‌توانی نام کاربری را مثل <b>@mychannel</b> وارد کنی؛ برای کانال خصوصی شناسه عددی گفتگو را وارد کن.</div>
<div class="steps"><div class="step"><b>۱</b><div><b>بازو را به کانال اضافه کن</b><div class="field-help">بازو باید اجازه ارسال پیام در کانال داشته باشد.</div></div></div><div class="step"><b>۲</b><div><b>توکن و کانال را وارد کن</b><div class="field-help">توکن از BotFather بله گرفته می‌شود. توکن در Backend رمزنگاری می‌شود و بعداً نمایش داده نمی‌شود.</div></div></div><div class="step"><b>۳</b><div><b>تست و ذخیره را بزن</b><div class="field-help">پنل ابتدا getMe و سپس یک پیام واقعی آزمایشی به همان کانال می‌فرستد؛ فقط در صورت موفقیت تنظیمات ذخیره می‌شوند.</div></div></div></div>
<form method="post" autocomplete="off" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_bale"><div class="field-grid"><div class="field"><label>توکن بازوی بله</label><input type="password" name="bot_token" autocomplete="new-password" spellcheck="false" style="direction:ltr" placeholder="<?=($baleStatus['token_configured']??false)?'قبلاً ذخیره شده؛ برای نگه‌داشتن خالی بگذار':'123456789:xxxxxxxxxxxxxxxx'?>"><span class="field-help"><?=($baleStatus['token_configured']??false)?'توکن فعلی ذخیره و رمزنگاری شده است. فقط برای تعویض توکن مقدار جدید وارد کن.':'توکن محرمانه بازو را اینجا وارد کن.'?></span></div><div class="field"><label>شناسه یا نام کاربری کانال</label><input name="chat_id" required value="<?=h($baleStatus['chat_id']??'')?>" spellcheck="false" style="direction:ltr" placeholder="@channelusername یا -123456789"><span class="field-help">نام کاربری کانال با @ یا Chat ID عددی.</span></div></div><label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="enabled" value="1" <?=($baleStatus['enabled']??false)?'checked':''?> style="width:auto"><b>ارسال خودکار خرید و فروش روشن باشد</b></label><div class="actions" style="margin-top:12px"><button class="btn safe">تست اتصال و ذخیره امن</button></div></form>
<?php if($baleStatus['configured']??false):?>
<div class="delivery-grid" style="margin-top:14px"><div class="delivery-stat"><small>مقصد</small><b><?=h($baleStatus['chat_id']??'—')?></b></div><div class="delivery-stat"><small>در صف ارسال</small><b><?=h($baleStatus['pending_deliveries']??0)?></b></div><div class="delivery-stat"><small>ارسال ناموفق</small><b><?=h($baleStatus['failed_deliveries']??0)?></b></div></div>
<?php $last=$baleStatus['last_delivery']??null;if(is_array($last)&&!empty($last['last_error'])):?><div class="notice bad" style="margin-top:10px"><b>آخرین خطای بله:</b> <?=h($last['last_error'])?></div><?php endif?>
<div class="actions" style="margin-top:12px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_bale"><button class="btn secondary">ارسال پیام آزمایشی</button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="toggle_bale"><input type="hidden" name="enabled" value="<?=($baleStatus['enabled']??false)?'0':'1'?>"><button class="btn <?=($baleStatus['enabled']??false)?'secondary':'safe'?>"><?=($baleStatus['enabled']??false)?'خاموش کردن ارسال خودکار':'روشن کردن ارسال خودکار'?></button></form><form method="post" onsubmit="return confirm('اتصال بله و توکن ذخیره‌شده از پنل حذف شود؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="disconnect_bale"><button class="btn danger">قطع اتصال بله</button></form></div>
<?php endif?>
<div class="notice info" style="margin-top:12px">پیام خرید شامل بازار، مقدار، قیمت، ارزش معامله، استراتژی و وضعیت بازار است. پیام فروش علاوه بر این‌ها قیمت ورود/خروج، سود یا زیان و دلیل خروج را هم نمایش می‌دهد. مقادیر بازار IRT در کانال به <b>تومان</b> نمایش داده می‌شوند.</div>
</section>

<div class="actions" style="margin:14px 0 12px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="read_all"><button class="btn soft" <?=$unread===0?'disabled':''?>>خواندن همه اعلان‌های داخلی</button></form></div>
<section class="panel"><div class="panel-head"><div><h2>اعلان‌های داخلی Trade</h2><p>رخدادهای مهم معاملات، اجرا، تعویض فرصت‌ها، ریسک و خطاهای سیستم.</p></div><span class="badge info"><?=count($items)?> EVENT</span></div><div class="feed"><?php if($items===[]):?><div class="empty">هنوز اعلانی ثبت نشده است.</div><?php endif?><?php foreach($items as $n):$priority=(string)($n['priority']??'info');if(!in_array($priority,['info','success','warning','critical'],true))$priority='info';?><div class="notification <?=($n['unread']??false)?'unread':''?>"><div class="icon <?=$priority?>">!</div><div><h3><?=h($n['title']??'')?></h3><p><?=h($n['body']??'')?></p><small><?=h(($n['category']??''). ' • '.($n['created_at']??''))?></small></div><?php if($n['unread']??false):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="read_one"><input type="hidden" name="id" value="<?=h($n['id'])?>"><button class="btn soft">خواندم</button></form><?php endif?></div><?php endforeach?></div></section>
<?php tradeAdminFooter($version);?></div></body></html>
