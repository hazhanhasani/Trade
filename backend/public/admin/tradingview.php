<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\TradingViewSignalService;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function faAction(string $v):string{return match(strtolower($v)){'buy'=>'خرید','sell'=>'فروش','hold'=>'صبر',default=>$v};}

$tv=new TradingViewSignalService();
$message='';$error='';$generated=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/tradingview.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $action=(string)($_POST['action']??'');
        if($action==='generate_secret'){
            $tv->generateSecret();$generated=true;$message='Webhook Secret جدید ساخته شد. اگر قبلاً Alert ساخته بودی، URL آن را با آدرس جدید جایگزین کن.';
        }elseif($action==='save'){
            $tv->saveSettings([
                'enabled'=>(string)($_POST['enabled']??'0'),
                'mode'=>(string)($_POST['mode']??'assist'),
                'ttl_seconds'=>(string)($_POST['ttl_seconds']??'480'),
                'min_confidence'=>(string)($_POST['min_confidence']??'65'),
                'required_confirmations'=>(string)($_POST['required_confirmations']??'3'),
                'score_weight'=>(string)($_POST['score_weight']??'22'),
                'instant_recheck'=>(string)($_POST['instant_recheck']??'0'),
                'require_known_ip'=>(string)($_POST['require_known_ip']??'0'),
            ]);
            $message='تنظیمات TradingView ذخیره شد.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,800);}
}
if(isset($_GET['csrf_refresh']))$error='فرم منقضی شده بود؛ صفحه تازه شد و تغییری انجام نشد.';
$status=$tv->status(true);$csrf=h((string)$_SESSION['csrf']);
$webhook=(string)($status['webhook_url']??'');$last=is_array($status['last_signal']??null)?$status['last_signal']:null;
$pinePath=dirname(__DIR__,2).'/resources/tradingview/TradeMTFConfirm.pine';
$pine=is_file($pinePath)?(string)file_get_contents($pinePath):'Pine template is unavailable in this package.';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TradingView • Trade</title><style>
:root{--bg:#f6f7fb;--card:#fff;--ink:#171a24;--muted:#73798b;--line:#e7e9f0;--primary:#6941ff;--green:#0d966f;--red:#d14343;--amber:#b46a00;--soft:#f0edff;--greenSoft:#eaf8f3;--redSoft:#fff0f0}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1120px;margin:auto;padding:22px}.top{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px}.logo{width:50px;height:50px;border-radius:16px;background:#131722;color:#fff;display:grid;place-items:center;font-weight:900;font-size:20px}.brand h1{margin:0;font-size:24px}.muted{color:var(--muted);font-size:13px;line-height:1.8}.actions,.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.btn{display:inline-block;border:0;border-radius:12px;padding:11px 15px;background:var(--primary);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.gray{background:#656c7c}.btn.danger{background:var(--red)}.btn.dark{background:#131722}.grid{display:grid;grid-template-columns:1.15fr .85fr;gap:14px}.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:18px;margin-top:14px;box-shadow:0 10px 30px #17203308}.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.metric{border:1px solid var(--line);border-radius:15px;padding:13px}.metric span{display:block;color:var(--muted);font-size:12px}.metric b{display:block;margin-top:6px;font-size:16px}.on{color:var(--green)}.off{color:var(--red)}.warn{color:var(--amber)}.msg,.err,.notice{margin-top:12px;padding:13px 15px;border-radius:14px;line-height:1.8}.msg{background:var(--greenSoft);color:#116246}.err{background:var(--redSoft);color:#9b2525}.notice{background:#fff7e5;color:#6f5000}.fields{display:grid;grid-template-columns:repeat(2,1fr);gap:11px}.field label{display:block;color:var(--muted);font-size:12px;margin-bottom:6px}.field input,.field select{width:100%;border:1px solid #cfd4df;border-radius:11px;padding:11px;background:#fff}.check{display:flex;align-items:center;gap:8px;padding:8px 0}.check input{width:auto}.url,.code{direction:ltr;text-align:left;background:#131722;color:#f1f5ff;border-radius:13px;padding:13px;font-family:monospace;font-size:12px;word-break:break-all;white-space:pre-wrap}.code{max-height:520px;overflow:auto}.copy{margin-top:8px}.steps{line-height:2}.badge{display:inline-block;border-radius:999px;padding:6px 9px;background:#f0f2f7;font-size:12px}.badge.ok{background:var(--greenSoft);color:var(--green)}.badge.bad{background:var(--redSoft);color:var(--red)}@media(max-width:800px){.grid{grid-template-columns:1fr}.metrics{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.wrap{padding:12px}.fields,.metrics{grid-template-columns:1fr}.actions .btn{flex:1;text-align:center}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand"><div class="logo">TV</div><div><h1>TradingView Signal Center</h1><div class="muted">لایه تأیید چندتایم‌فریمی برای موتور چندارزی Nobitex</div></div></div><div class="actions"><a class="btn gray" href="/admin/">پنل اصلی</a><a class="btn gray" href="/admin/bot/">ربات</a><a class="btn gray" href="/admin/exchanges.php">صرافی‌ها</a></div></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif?><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?>
<div class="metrics card"><div class="metric"><span>TradingView</span><b class="<?=$status['enabled']?'on':'off'?>"><?=$status['enabled']?'فعال':'خاموش'?></b></div><div class="metric"><span>حالت</span><b><?=h($status['mode']==='confirm'?'Confirm سخت‌گیرانه':'Assist هوشمند')?></b></div><div class="metric"><span>آخرین سیگنال</span><b class="<?=$status['fresh']?'on':'warn'?>"><?=$status['fresh']?'تازه':'قدیمی / موجود نیست'?></b></div><div class="metric"><span>سیگنال ۲۴ ساعت</span><b><?=h((string)($status['signals_24h']['total']??0))?></b></div></div>
<div class="grid"><div>
<div class="card"><h2>اتصال Webhook</h2><p class="muted">کلید API تریدینگ‌ویو لازم نیست. Alertهای Pine از Webhook رسمی TradingView به Backend می‌آیند و Secret فقط داخل URL است.</p>
<?php if($webhook!==''):?><div class="url" id="webhookUrl"><?=h($webhook)?></div><button class="btn dark copy" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').innerText)">کپی Webhook URL</button><?php else:?><div class="notice">هنوز Webhook Secret ساخته نشده است.</div><?php endif?>
<form method="post" onsubmit="return confirm('با ساخت Secret جدید، Webhook قبلی دیگر معتبر نخواهد بود. ادامه؟')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="generate_secret"><button class="btn <?=$webhook!==''?'danger':''?>" type="submit"><?=$webhook!==''?'چرخش Secret':'ساخت Webhook Secret'?></button></form>
<p class="muted">Secret را در چت، GitHub یا Pine Script قرار نده. فقط URL کامل را در بخش Webhook URL خود Alert وارد کن.</p></div>
<div class="card"><h2>تنظیمات تصمیم‌گیری</h2><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save"><input type="hidden" name="enabled" value="0"><input type="hidden" name="instant_recheck" value="0"><input type="hidden" name="require_known_ip" value="0"><div class="fields">
<div class="field"><label>حالت TradingView</label><select name="mode"><option value="assist" <?=$status['mode']==='assist'?'selected':''?>>Assist — پیشنهادی</option><option value="confirm" <?=$status['mode']==='confirm'?'selected':''?>>Confirm — ورود فقط با تأیید TV</option></select></div>
<div class="field"><label>اعتبار سیگنال (ثانیه)</label><input type="number" min="60" max="3600" name="ttl_seconds" value="<?=h($status['signal_ttl_seconds'])?>"></div>
<div class="field"><label>حداقل Confidence</label><input type="number" min="40" max="95" name="min_confidence" value="<?=h($status['min_confidence'])?>"></div>
<div class="field"><label>تعداد تأیید تایم‌فریم</label><input type="number" min="1" max="4" name="required_confirmations" value="<?=h($status['required_confirmations'])?>"></div>
<div class="field"><label>وزن امتیاز TradingView</label><input type="number" min="0" max="40" name="score_weight" value="<?=h($status['score_weight'])?>"></div>
</div><label class="check"><input type="checkbox" name="enabled" value="1" <?=$status['enabled']?'checked':''?>> استفاده از TradingView در تصمیم ربات</label><label class="check"><input type="checkbox" name="instant_recheck" value="1" <?=$status['instant_recheck']?'checked':''?>> بعد از Webhook، در FastCGI فوراً Nobitex را دوباره تحلیل کن</label><label class="check"><input type="checkbox" name="require_known_ip" value="1" <?=$status['require_known_ip']?'checked':''?>> فقط IPهای رسمی TradingView را بپذیر (اگر پشت Proxy/CDN نیستی)</label><button class="btn" type="submit">ذخیره تنظیمات</button></form>
<div class="notice"><b>Assist</b> برای شروع بهتر است: نبودن Alert یک ارز، اسکن چندارزی Nobitex را متوقف نمی‌کند؛ اما TradingView مخالف می‌تواند جلوی ورود ضعیف را بگیرد. <b>Confirm</b> فقط وقتی مناسب است که برای ارزهای موردنظر Alert فعال داشته باشی.</div></div>
</div><div>
<div class="card"><h2>آخرین سیگنال دریافتی</h2><?php if($last):?><div class="row"><span class="badge <?=($last['action']??'')==='buy'?'ok':(($last['action']??'')==='sell'?'bad':'')?>"><?=h(faAction((string)$last['action']))?></span><b><?=h($last['asset'])?></b><span><?=h($last['timeframe'])?></span></div><p>Confidence: <b><?=h($last['confidence'])?>%</b> • Confirmations: <b><?=h($last['confirmations'])?></b></p><p class="muted">Symbol: <?=h($last['symbol'])?><br>دریافت: <?=h($last['received_at'])?><br>Source IP: <span class="<?=$last['source_verified']?'on':'warn'?>"><?=$last['source_verified']?'تأییدشده TradingView':'تأیید IP نشده / ممکن است Proxy باشد'?></span></p><?php else:?><p class="muted">هنوز Alert معتبری دریافت نشده است.</p><?php endif?></div>
<div class="card"><h2>راه‌اندازی در TradingView</h2><ol class="steps"><li>در TradingView احراز هویت دو مرحله‌ای را فعال نگه دار.</li><li>قالب Pine پایین را در Pine Editor قرار بده و روی نمودار 1m ارز موردنظر اجرا کن.</li><li>Create Alert را بزن و Condition را روی <b>Any alert() function call</b> بگذار.</li><li>Webhook URL بالا را در Alert وارد کن.</li><li>برای تحلیل جهانی می‌توانی مثلاً BTCUSDT/ETHUSDT روی بازار پرحجم را استفاده کنی؛ Backend سیگنال را بر اساس <b>asset</b> با بازار IRT/USDT نوبیتکس تطبیق می‌دهد.</li></ol><p class="muted">یک Pine روی یک نمودار همه ارزهای نوبیتکس را اسکن نمی‌کند. برای پوشش بیشتر، Alert ارزهای مهم را جدا بساز؛ موتور داخلی Nobitex همچنان کل بازار واجدشرایط را اسکن می‌کند.</p></div>
</div></div>
<div class="card"><h2>Pine Script v6 — Trade MTF Confirm</h2><p class="muted">این قالب 1m + 5m + 15m + 1h را ترکیب می‌کند. تایم‌فریم‌های بالاتر از آخرین کندل بسته‌شده استفاده می‌کنند تا سیگنال HTF کمتر دچار repaint شود.</p><div class="code" id="pineCode"><?=h($pine)?></div><button class="btn dark copy" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pineCode').innerText)">کپی Pine Script</button></div>
<div class="notice">TradingView فقط یک لایه تأیید است و مستقیماً به کلید صرافی یا موجودی دسترسی ندارد. Stop Loss، Take Profit، سقف پوزیشن، حد زیان روزانه، Kill Switch و اعتبارسنجی سفارش همچنان در Backend اجرا می‌شوند.</div>
</div></body></html>