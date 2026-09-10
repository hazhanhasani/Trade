<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\TradingViewSignalService;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function faAction(string $v): string { return match(strtolower($v)){'buy'=>'خرید','sell'=>'فروش','hold'=>'صبر',default=>$v}; }

$tv=new TradingViewSignalService();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/tradingview.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $action=(string)($_POST['action']??'');
        if($action==='generate_secret'){
            $tv->generateSecret();$message='Webhook Secret جدید ساخته شد؛ Alertهای قبلی باید با URL جدید بروزرسانی شوند.';
        }elseif($action==='save'){
            $tv->saveSettings([
                'enabled'=>(string)($_POST['enabled']??'0'),'mode'=>(string)($_POST['mode']??'assist'),'ttl_seconds'=>(string)($_POST['ttl_seconds']??'480'),'min_confidence'=>(string)($_POST['min_confidence']??'65'),'required_confirmations'=>(string)($_POST['required_confirmations']??'3'),'score_weight'=>(string)($_POST['score_weight']??'22'),'instant_recheck'=>(string)($_POST['instant_recheck']??'0'),'require_known_ip'=>(string)($_POST['require_known_ip']??'0'),
            ]);
            $message='تنظیمات TradingView ذخیره شد.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,800);}
}
if(isset($_GET['csrf_refresh']))$error='فرم منقضی شده بود؛ صفحه تازه شد و تغییری انجام نشد.';

$status=$tv->status(true);$csrf=h((string)$_SESSION['csrf']);$webhook=(string)($status['webhook_url']??'');$last=is_array($status['last_signal']??null)?$status['last_signal']:null;
$pinePath=dirname(__DIR__,2).'/resources/tradingview/TradeMTFConfirm.pine';$pine=is_file($pinePath)?(string)file_get_contents($pinePath):'Pine template is unavailable in this package.';
$version=Updater::currentVersion();
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TradingView — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.secret-box,.code-box{direction:ltr;text-align:left;background:#131722;color:#f1f5ff;border-radius:13px;padding:13px;font-family:monospace;font-size:11px;word-break:break-all;white-space:pre-wrap}.code-box{max-height:520px;overflow:auto}.check{display:flex;align-items:center;gap:8px;padding:8px 0;font-size:11px}.check input{width:auto}.tv-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:12px}@media(max-width:800px){.tv-grid{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">TRADING / TRADINGVIEW</div><h1>TradingView Signal Center</h1><p>لایه تأیید چندتایم‌فریمی برای موتور معاملات؛ این بخش زیرمجموعه «معاملات» است.</p></div><span class="badge <?=$status['enabled']?'good':'bad'?>">TV <?=$status['enabled']?'ON':'OFF'?></span></div>
<div class="subnav"><a href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">Intelligence</a><a href="/admin/bot/rotation.php">Rotation</a><a class="active" href="/admin/tradingview.php">TradingView</a></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="stat-grid"><div class="stat-card"><span>TradingView</span><b class="<?=$status['enabled']?'ok':'bad-text'?>"><?=$status['enabled']?'فعال':'خاموش'?></b></div><div class="stat-card"><span>Mode</span><b><?=h($status['mode']==='confirm'?'Confirm':'Assist')?></b></div><div class="stat-card"><span>آخرین سیگنال</span><b class="<?=$status['fresh']?'ok':'warn-text'?>"><?=$status['fresh']?'تازه':'قدیمی / ندارد'?></b></div><div class="stat-card"><span>سیگنال ۲۴ ساعت</span><b><?=h((string)($status['signals_24h']['total']??0))?></b></div></div>

<div class="tv-grid"><div>
<section class="panel soft"><div class="panel-head"><div><h2>Webhook Connection</h2><p>Alertهای Pine مستقیماً از Webhook رسمی TradingView وارد Backend می‌شوند.</p></div><span class="badge info">SECURE</span></div><?php if($webhook!==''):?><div class="secret-box" id="webhookUrl"><?=h($webhook)?></div><div class="actions" style="margin-top:9px"><button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').innerText)">کپی Webhook URL</button></div><?php else:?><div class="notice info">هنوز Webhook Secret ساخته نشده است.</div><?php endif?><form method="post" style="margin-top:10px" onsubmit="return confirm('Secret جدید ساخته شود؟ URL قبلی دیگر معتبر نخواهد بود.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="generate_secret"><button class="btn <?=$webhook!==''?'danger':''?>"><?=$webhook!==''?'چرخش Secret':'ساخت Webhook Secret'?></button></form><div class="notice info">Secret را در GitHub، Pine Script یا چت قرار نده؛ فقط URL کامل را داخل Webhook URL Alert وارد کن.</div></section>

<section class="panel"><div class="panel-head"><div><h2>تنظیمات تصمیم‌گیری</h2><p>مالک تنظیمات TradingView فقط همین صفحه است.</p></div><span class="badge info">SETTINGS</span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save"><input type="hidden" name="enabled" value="0"><input type="hidden" name="instant_recheck" value="0"><input type="hidden" name="require_known_ip" value="0"><div class="field-grid"><div class="field"><label>حالت TradingView</label><select name="mode"><option value="assist" <?=$status['mode']==='assist'?'selected':''?>>Assist — پیشنهادی</option><option value="confirm" <?=$status['mode']==='confirm'?'selected':''?>>Confirm — ورود فقط با تأیید TV</option></select></div><div class="field"><label>اعتبار سیگنال (ثانیه)</label><input type="number" min="60" max="3600" name="ttl_seconds" value="<?=h($status['signal_ttl_seconds'])?>"></div><div class="field"><label>حداقل Confidence</label><input type="number" min="40" max="95" name="min_confidence" value="<?=h($status['min_confidence'])?>"></div><div class="field"><label>تعداد تأیید تایم‌فریم</label><input type="number" min="1" max="4" name="required_confirmations" value="<?=h($status['required_confirmations'])?>"></div><div class="field"><label>وزن امتیاز TradingView</label><input type="number" min="0" max="40" name="score_weight" value="<?=h($status['score_weight'])?>"></div></div><label class="check"><input type="checkbox" name="enabled" value="1" <?=$status['enabled']?'checked':''?>> استفاده از TradingView در تصمیم ربات</label><label class="check"><input type="checkbox" name="instant_recheck" value="1" <?=$status['instant_recheck']?'checked':''?>> بعد از Webhook فوراً Nobitex را دوباره تحلیل کن</label><label class="check"><input type="checkbox" name="require_known_ip" value="1" <?=$status['require_known_ip']?'checked':''?>> فقط IPهای رسمی TradingView را بپذیر</label><div class="actions" style="margin-top:8px"><button class="btn">ذخیره تنظیمات</button></div></form><div class="notice info"><b>Assist</b> برای شروع مناسب‌تر است؛ نبود Alert یک ارز اسکن چندارزی Nobitex را متوقف نمی‌کند. <b>Confirm</b> فقط برای زمانی مناسب است که Alertهای کامل فعال باشند.</div></section>
</div><div>
<section class="panel"><div class="panel-head"><div><h2>آخرین سیگنال دریافتی</h2><p>آخرین payload معتبر ذخیره‌شده.</p></div></div><?php if($last):?><div class="status-line"><span class="badge <?=($last['action']??'')==='buy'?'good':(($last['action']??'')==='sell'?'bad':'warn')?>"><?=h(faAction((string)$last['action']))?></span><span class="badge info"><?=h($last['asset'])?></span><span class="badge"><?=h($last['timeframe'])?></span></div><div class="metric-grid" style="margin-top:10px"><div class="metric"><span>Confidence</span><b><?=h($last['confidence'])?>%</b></div><div class="metric"><span>Confirmations</span><b><?=h($last['confirmations'])?></b></div><div class="metric"><span>Source</span><b class="<?=$last['source_verified']?'ok':'warn-text'?>"><?=$last['source_verified']?'VERIFIED':'CHECK'?></b></div></div><div class="notice info">Symbol: <?=h($last['symbol'])?><br>دریافت: <?=h($last['received_at'])?><br>Source IP: <?=$last['source_verified']?'تأییدشده':'تأیید نشده / ممکن است Proxy باشد'?></div><?php else:?><div class="empty">هنوز Alert معتبری دریافت نشده است.</div><?php endif?></section>

<section class="panel"><div class="panel-head"><div><h2>راه‌اندازی Alert</h2><p>مراحل اتصال TradingView به Backend.</p></div></div><ol class="muted" style="line-height:2"><li>احراز هویت دو مرحله‌ای TradingView را فعال نگه دار.</li><li>قالب Pine پایین را در Pine Editor قرار بده و روی نمودار اجرا کن.</li><li>Create Alert را بزن و Condition را روی <b>Any alert() function call</b> بگذار.</li><li>Webhook URL بالا را داخل Alert وارد کن.</li><li>برای هر Asset موردنظر Alert مناسب ایجاد کن.</li></ol></section>
</div></div>

<section class="panel"><div class="panel-head"><div><h2>Pine Template</h2><p>قالب داخلی پروژه برای تأیید چندتایم‌فریمی.</p></div><button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pine').innerText)">کپی Pine</button></div><div class="code-box" id="pine"><?=h($pine)?></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>