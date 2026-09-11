<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\MarketData\MarketDataCredentialStore;
use Trade\MarketData\MarketDataHub;
use Trade\Trading\BotController;
use Trade\Trading\NobitexOrderService;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$controller=new BotController();
$nobitex=new NobitexOrderService();
$marketCredentials=new MarketDataCredentialStore();
$marketHub=new MarketDataHub($marketCredentials);
$message='';$error='';$marketTest=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        header('Location: /admin/exchanges.php?csrf_refresh=1',true,303);exit;
    }
    try{
        $action=(string)($_POST['action']??'');
        if($action==='bot_toggle'||$action==='live_toggle'){
            // Execution controls are intentionally Nobitex-only. A stale form,
            // bookmark or crafted POST can never re-enable Bitpin trading.
            $exchange=strtolower(trim((string)($_POST['exchange']??'')));
            if($exchange!=='nobitex') throw new RuntimeException('کنترل معامله فقط برای نوبیتکس مجاز است.');
            $enabled=(string)($_POST['enabled']??'0')==='1';
            if($action==='bot_toggle'){
                $controller->setExchangeEnabled('nobitex',$enabled);
                $message='Nobitex — ربات '.($enabled?'روشن شد.':'خاموش شد.');
            }else{
                $controller->setExchangeLive('nobitex',$enabled);
                $message='Nobitex — ارسال سفارش واقعی '.($enabled?'روشن شد.':'خاموش شد.');
            }
        }elseif($action==='save_nobitex'){
            $nobitex->saveCredentials((string)($_POST['public_key']??''),(string)($_POST['private_key']??''));
            $message='کلید API نوبیتکس تست و به‌صورت رمزنگاری‌شده ذخیره شد.';
        }elseif($action==='test_nobitex'){
            $nobitex->client()->test();
            $message='اتصال نوبیتکس و دریافت موجودی با موفقیت انجام شد.';
        }elseif($action==='delete_nobitex'){
            $nobitex->deleteCredentials();
            $message='کلیدهای نوبیتکس حذف شدند و ربات و ارسال واقعی آن خاموش شد.';
        }elseif($action==='save_market_data'){
            $source=strtolower(trim((string)($_POST['source']??'')));
            $marketCredentials->save($source,(string)($_POST['api_key']??''),(string)($_POST['secret']??''));
            MarketDataHub::clearRuntime();
            $message=match($source){
                'abantether'=>'کلید Market Data آبان‌تتر به‌صورت رمزنگاری‌شده ذخیره شد.',
                'bit24'=>'کلید Market Data بیت۲۴ به‌صورت رمزنگاری‌شده ذخیره شد.',
                'tabdeal'=>'کلید تبدیل ذخیره شد؛ دریافت قیمت عمومی تبدیل حتی بدون کلید نیز فعال است.',
                default=>'کلید Market Data ذخیره شد.',
            };
        }elseif($action==='delete_market_data'){
            $source=strtolower(trim((string)($_POST['source']??'')));
            $marketCredentials->delete($source);MarketDataHub::clearRuntime();
            $message='کلید منبع Market Data حذف شد.';
        }elseif($action==='test_market_data'){
            MarketDataHub::clearRuntime();
            $marketTest=$marketHub->snapshot('USDT','IRT',true);
            $ok=(int)($marketTest['consensus']['source_count']??0);
            $message=$ok>=2?'تست Market Data انجام شد و Consensus با '.$ok.' منبع معتبر ساخته شد.':'تست انجام شد؛ برای Consensus حداقل دو منبع معتبر لازم است.';
        }
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود و تازه‌سازی شد؛ هیچ تغییری انجام نشد.';

$status=$controller->status();
$ex=is_array($status['exchanges']??null)?$status['exchanges']:[];
$nobitexStatus=is_array($ex['nobitex']??null)?$ex['nobitex']:[];
$sourceStatus=$marketCredentials->status();
$csrf=h((string)$_SESSION['csrf']);
$version=Updater::currentVersion();
require __DIR__.'/_nav.php';

function sourceBadge(array $status):string{return($status['configured']??false)?'good':'info';}
function sourceText(array $status):string{return($status['configured']??false)?'کلید ذخیره شده':'بدون کلید';}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>صرافی‌ها و Market Data — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.market-role{font-size:11px;line-height:1.9;color:var(--muted)}.source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.source-card{min-width:0}.source-card form{margin-top:10px}.source-card input{max-width:100%;box-sizing:border-box}.source-result{direction:ltr;text-align:left;font:11px/1.7 monospace;white-space:pre-wrap;word-break:break-word;max-height:330px;overflow:auto}.role-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}details.help-box{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:10px 12px}details.help-box summary{cursor:pointer;font-weight:800;font-size:11px}details.help-box p{color:var(--muted);font-size:10px;line-height:1.9;margin:9px 0 0}@media(max-width:680px){.source-grid{grid-template-columns:1fr}.field-grid{grid-template-columns:1fr!important}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('exchanges',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">Execution + Market Intelligence</div><h1>صرافی‌ها و منابع قیمت</h1><p>معامله واقعی فقط روی نوبیتکس اجرا می‌شود. آبان‌تتر، بیت۲۴، تبدیل و بیت‌پین فقط داده بازار به مغز ربات می‌دهند.</p></div><span class="badge <?=($status['kill_switch']??false)?'bad':'good'?>">توقف اضطراری <?=($status['kill_switch']??false)?'روشن':'خاموش'?></span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<section class="panel"><div class="panel-head"><div><h2>مرز امنیتی پروژه</h2><p>نقش هر اتصال عمداً جدا شده تا هیچ منبع قیمت نتواند خودش سفارش ثبت کند.</p></div><span class="badge good">Nobitex-only execution</span></div>
<div class="simple-guide"><div><b>Nobitex</b><span>تحلیل بازار + موجودی + ثبت/لغو سفارش واقعی، فقط با مجوز ربات و Live Execution.</span></div><div><b>Market Data</b><span>آبان‌تتر، بیت۲۴، تبدیل و بیت‌پین فقط قیمت، Order Book و داده لازم برای Consensus را می‌دهند.</span></div><div><b>Consensus</b><span>حداقل دو منبع هم‌نظر لازم است؛ منبع پرت یا خطای ریال/تومان از محاسبه حذف می‌شود.</span></div></div>
<div class="role-strip"><span class="badge bad">Bitpin execution: قفل دائمی</span><span class="badge info">IRT خارجی: تومان</span><span class="badge info">Nobitex IRT داخلی: ریال → نرمال‌سازی ×/÷10</span></div></section>

<section class="panel soft"><div class="panel-head"><div><h2>Nobitex</h2><p>تنها Execution Venue پروژه.</p></div><span class="badge <?=($nobitexStatus['credentials_configured']??false)?'good':'bad'?>">API <?=($nobitexStatus['credentials_configured']??false)?'متصل':'قطع'?></span></div>
<div class="status-line"><span class="badge <?=($nobitexStatus['sodium_available']??false)?'good':'bad'?>">امضای امن <?=($nobitexStatus['sodium_available']??false)?'آماده':'غیرفعال'?></span><span class="badge <?=($nobitexStatus['bot_enabled']??false)?'good':'bad'?>">ربات <?=($nobitexStatus['bot_enabled']??false)?'روشن':'خاموش'?></span><span class="badge <?=($nobitexStatus['live_execution_enabled']??false)?'good':'bad'?>">ارسال واقعی <?=($nobitexStatus['live_execution_enabled']??false)?'روشن':'خاموش'?></span></div>
<div class="notice info">برای نوبیتکس فقط دسترسی <b>خواندن + معامله</b> لازم است. دسترسی برداشت لازم نیست و نباید فعال شود. کلید خصوصی رمزنگاری‌شده ذخیره می‌شود.</div>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_nobitex"><div class="field-grid"><div class="field"><label>کلید عمومی API نوبیتکس</label><input name="public_key" required autocomplete="off" spellcheck="false" style="direction:ltr"><span class="field-help">Public API key</span></div><div class="field"><label>کلید خصوصی API نوبیتکس</label><input type="password" name="private_key" required autocomplete="new-password" spellcheck="false" style="direction:ltr"><span class="field-help">هیچ‌وقت در صفحه دوباره نمایش داده نمی‌شود.</span></div></div><div class="actions" style="margin-top:12px"><button class="btn">تست اتصال و ذخیره امن</button></div></form>
<?php if($nobitexStatus['credentials_configured']??false):?><div class="actions" style="margin-top:10px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_nobitex"><button class="btn secondary">تست دوباره نوبیتکس</button></form><form method="post" onsubmit="return confirm('کلید نوبیتکس حذف شود؟ ربات و ارسال واقعی نیز خاموش می‌شوند.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="delete_nobitex"><button class="btn danger">حذف کلیدهای API</button></form></div><?php endif?>
<div class="panel" style="margin-top:12px;box-shadow:none;background:#fff"><div class="panel-head"><div><h2 style="font-size:14px">کنترل اجرای خودکار</h2><p>فقط این دو کنترل اجازه دارند روی معامله واقعی اثر بگذارند.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="bot_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=($nobitexStatus['bot_enabled']??false)?'0':'1'?>"><button class="btn <?=($nobitexStatus['bot_enabled']??false)?'secondary':'safe'?>"><?=($nobitexStatus['bot_enabled']??false)?'خاموش کردن ربات':'روشن کردن ربات'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="live_toggle"><input type="hidden" name="exchange" value="nobitex"><input type="hidden" name="enabled" value="<?=($nobitexStatus['live_execution_enabled']??false)?'0':'1'?>"><button class="btn <?=($nobitexStatus['live_execution_enabled']??false)?'secondary':'safe'?>"><?=($nobitexStatus['live_execution_enabled']??false)?'خاموش کردن ارسال واقعی':'روشن کردن ارسال واقعی'?></button></form></div></div></section>

<section class="panel"><div class="panel-head"><div><h2>منابع Market Data ایران</h2><p>این منابع هیچ مسیر ثبت سفارش در پروژه ندارند.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_market_data"><button class="btn secondary">تست USDT/IRT و Consensus</button></form></div>
<div class="source-grid">
<section class="panel source-card"><div class="panel-head"><div><h2>آبان‌تتر</h2><p class="market-role">API رسمی؛ فقط Market Data و مرجع قیمت داخلی.</p></div><span class="badge <?=sourceBadge($sourceStatus['abantether']??[])?>"><?=sourceText($sourceStatus['abantether']??[])?></span></div>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_market_data"><input type="hidden" name="source" value="abantether"><div class="field"><label>API Key آبان‌تتر</label><input type="password" name="api_key" required autocomplete="new-password" style="direction:ltr"><span class="field-help">فقط روی Backend و رمزنگاری‌شده ذخیره می‌شود.</span></div><div class="actions"><button class="btn">ذخیره امن</button><?php if($sourceStatus['abantether']['configured']??false):?><button class="btn danger" type="submit" name="action" value="delete_market_data" formnovalidate>حذف کلید</button><?php endif?></div></form></section>

<section class="panel source-card"><div class="panel-head"><div><h2>بیت۲۴</h2><p class="market-role">API رسمی؛ Order Book و داده بازار، بدون اجازه معامله.</p></div><span class="badge <?=sourceBadge($sourceStatus['bit24']??[])?>"><?=sourceText($sourceStatus['bit24']??[])?></span></div>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_market_data"><input type="hidden" name="source" value="bit24"><div class="field"><label>API Key بیت۲۴</label><input type="password" name="api_key" required autocomplete="new-password" style="direction:ltr"><span class="field-help">به‌عنوان X-BIT24-APIKEY استفاده می‌شود؛ در UI نمایش داده نمی‌شود.</span></div><div class="actions"><button class="btn">ذخیره امن</button><?php if($sourceStatus['bit24']['configured']??false):?><button class="btn danger" type="submit" name="action" value="delete_market_data" formnovalidate>حذف کلید</button><?php endif?></div></form></section>

<section class="panel source-card"><div class="panel-head"><div><h2>تبدیل (Tabdeal)</h2><p class="market-role">Order Book عمومی همین حالا فعال است؛ API Key خصوصی برای دریافت قیمت لازم نیست.</p></div><span class="badge good">Public Market Data فعال</span></div>
<div class="notice info">بعد از احراز هویت می‌توانی کلید را هم نگه‌داری کنی، ولی موتور فعلی برای قیمت و عمق بازار از endpoint عمومی استفاده می‌کند.</div><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_market_data"><input type="hidden" name="source" value="tabdeal"><div class="field-grid"><div class="field"><label>API Key تبدیل (اختیاری)</label><input type="password" name="api_key" autocomplete="new-password" style="direction:ltr"></div><div class="field"><label>Secret (اختیاری)</label><input type="password" name="secret" autocomplete="new-password" style="direction:ltr"></div></div><div class="actions"><button class="btn secondary">ذخیره برای آینده</button><?php if($sourceStatus['tabdeal']['configured']??false):?><button class="btn danger" type="submit" name="action" value="delete_market_data" formnovalidate>حذف کلید</button><?php endif?></div></form></section>

<section class="panel source-card"><div class="panel-head"><div><h2>Bitpin</h2><p class="market-role">فقط قیمت و Order Book عمومی؛ به‌طور کامل از Execution خارج شده.</p></div><span class="badge good">Market Data only</span></div><div class="notice bad"><b>ثبت یا لغو سفارش Bitpin در Backend قفل دائمی دارد.</b> حتی فلگ یا تنظیم قدیمی دیتابیس نمی‌تواند آن را فعال کند.</div><div class="role-strip"><span class="badge good">Public API</span><span class="badge bad">Trading disabled</span><span class="badge bad">Live execution disabled</span></div></section>
</div>
<?php if(is_array($marketTest)):?><div class="panel" style="margin-top:12px;box-shadow:none"><div class="panel-head"><div><h2>نتیجه آخرین تست Market Data</h2><p>کلیدها نمایش داده نمی‌شوند؛ فقط سلامت منبع و نتیجه Consensus دیده می‌شود.</p></div><span class="badge <?=($marketTest['consensus']['available']??false)?'good':'bad'?>"><?=($marketTest['consensus']['available']??false)?'Consensus آماده':'Consensus ناکافی'?></span></div><div class="source-result"><?=h(json_encode(['canonical_quote_unit'=>$marketTest['canonical_quote_unit']??null,'sources'=>array_map(static fn($s)=>['status'=>$s['status']??'unknown','mid'=>$s['mid']??null,'error'=>$s['error']??null,'reason'=>$s['reason']??null],(array)($marketTest['sources']??[])),'consensus'=>$marketTest['consensus']??[]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></div><?php endif?>
</section>

<section class="panel"><div class="panel-head"><div><h2>منطق تصمیم نهایی</h2><p>داده چند صرافی فقط کیفیت تصمیم نوبیتکس را بالا می‌برد.</p></div><span class="badge info">Harden-only</span></div><div class="simple-guide"><div><b>۱. جمع‌آوری</b><span>قیمت/Order Book از منابع فعال گرفته و همه واحدها نرمال می‌شوند.</span></div><div><b>۲. حذف داده خراب</b><span>اختلاف‌های غیرعادی و خطای ۱۰برابری ریال/تومان قبل از Consensus حذف می‌شوند.</span></div><div><b>۳. اجرای معامله</b><span>داده خارجی فقط می‌تواند Edge را کم کند، سفارش Market را به Limit محدود تبدیل کند یا BUY را وتو کند؛ هرگز BUY جدید تولید نمی‌کند.</span></div></div><details class="help-box"><summary>شرط معامله واقعی چیست؟</summary><p>API نوبیتکس متصل، ربات نوبیتکس روشن، Live Execution روشن و Kill Switch خاموش باشد؛ سپس تمام کنترل‌های ریسک، Profit-First، Execution Learning و External Market Consensus نیز باید معامله را تأیید کنند.</p></details></section>

</div></body></html>
