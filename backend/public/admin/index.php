<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Trading\BotController;
use Trade\Trading\NobitexOrderService;
use Trade\Trading\NobitexSchema;
use Trade\Trading\OrderService;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pdo=Database::connection();
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
if(isset($_GET['logout'])){$_SESSION=[];session_destroy();header('Location: /admin/');exit;}

if(!isset($_SESSION['admin_id'])){
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $u=trim((string)($_POST['username']??''));$p=(string)($_POST['password']??'');$s=$pdo->prepare('SELECT id,password_hash FROM admins WHERE username=:u LIMIT 1');$s->execute([':u'=>$u]);$a=$s->fetch();
        if($a&&password_verify($p,(string)$a['password_hash'])){session_regenerate_id(true);$_SESSION['admin_id']=(int)$a['id'];$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/');exit;}$error='نام کاربری یا رمز عبور صحیح نیست.';
    }
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade</title><style>body{font-family:Tahoma,Arial;background:#f5f7fb;color:#172033}.box{max-width:380px;margin:12vh auto;background:#fff;padding:28px;border-radius:22px;border:1px solid #e2e8f0}input,button{width:100%;box-sizing:border-box;padding:13px;margin:7px 0;border-radius:11px}input{border:1px solid #ccd5e3}button{border:0;background:#1769ff;color:#fff;font-weight:700}.err{background:#fff0f0;color:#a11;padding:10px;border-radius:10px}</style></head><body><div class="box"><h2>Trade</h2><p>ورود به پنل مدیریت</p><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?><form method="post"><input name="username" placeholder="نام کاربری" required autocomplete="username"><input type="password" name="password" placeholder="رمز عبور" required autocomplete="current-password"><button>ورود</button></form></div></body></html><?php exit;
}

NobitexSchema::ensure();AppAccess::bootstrapLegacy($pdo);AppAccess::cleanup($pdo);

if(($_GET['ajax']??'')==='health'){
    header('Content-Type: application/json; charset=utf-8');
    $status=(new BotController())->status();
    $cron=$pdo->query("SELECT status,started_at,TIMESTAMPDIFF(SECOND,started_at,UTC_TIMESTAMP()) age_seconds FROM bot_runs ORDER BY id DESC LIMIT 1")->fetch()?:null;$age=$cron?(int)$cron['age_seconds']:null;$cronOk=$age!==null&&$age<=180;
    $checks=[];
    foreach(['bitpin','nobitex']as$exchange){$info=$status['exchanges'][$exchange];$ok=false;$text=$info['credentials_configured']?'در حال تست':'کلید تنظیم نشده';if($info['credentials_configured']){try{if($exchange==='bitpin'){$s=new OrderService();$c=$s->client();$c->wallets();$s->syncTokens($c);}else{(new NobitexOrderService())->client()->test();}$ok=true;$text='API و Wallet سالم';}catch(Throwable $e){$text=mb_substr($e->getMessage(),0,180);}}$checks[$exchange]=['ok'=>$ok,'text'=>$text];}
    echo json_encode(['ok'=>true,'checks'=>[
        'backend'=>['ok'=>true,'text'=>'Backend v'.Updater::currentVersion()],
        'database'=>['ok'=>true,'text'=>'MySQL متصل'],
        'bitpin'=>$checks['bitpin'],'nobitex'=>$checks['nobitex'],
        'cron'=>['ok'=>$cronOk,'text'=>$age===null?'هنوز اجرا نشده':($cronOk?'فعال — '.$age.' ثانیه قبل':'آخرین اجرا '.$age.' ثانیه قبل')],
        'https'=>['ok'=>!empty($_SERVER['HTTPS']),'text'=>!empty($_SERVER['HTTPS'])?'HTTPS فعال':'HTTPS تشخیص داده نشد'],
        'php'=>['ok'=>PHP_VERSION_ID>=80200,'text'=>'PHP '.PHP_VERSION],
        'sodium'=>['ok'=>function_exists('sodium_crypto_sign_detached'),'text'=>function_exists('sodium_crypto_sign_detached')?'Ed25519 آماده':'PHP Sodium غیرفعال'],
        'app_tokens'=>['ok'=>AppAccess::activeCount($pdo)>0,'text'=>AppAccess::activeCount($pdo).' اتصال فعال'],
    ],'time_utc'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

$message='';$error='';$pairCode='';$pairLink='';$issuedToken='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/?csrf_refresh=1',true,303);exit;}
    try{$a=(string)($_POST['action']??'');
        if($a==='create_pairing'){$pair=AppAccess::createPairing($pdo,(int)$_SESSION['admin_id'],trim((string)($_POST['label']??'Android phone')));$pairCode=(string)$pair['code'];$pairLink='trade://pair?server='.rawurlencode((string)Config::get('app.url','https://rado-taxi.sbs')).'&code='.rawurlencode($pairCode);$message='کد اتصال ۱۰ دقیقه‌ای ساخته شد.';}
        elseif($a==='issue_token'){$issued=AppAccess::issueToken($pdo,trim((string)($_POST['label']??'Manual token')),(int)$_SESSION['admin_id']);$issuedToken=(string)$issued['token'];$message='توکن جدید ساخته شد؛ فقط همین یک‌بار نمایش داده می‌شود.';}
        elseif($a==='revoke_token'){AppAccess::revoke($pdo,(int)($_POST['token_id']??0));$message='اتصال لغو شد.';}
        elseif($a==='kill_switch'){$on=(string)($_POST['enabled']??'1')==='1';(new BotController())->setKillSwitch($on);$message=$on?'توقف اضطراری هر دو صرافی فعال شد.':'توقف اضطراری برداشته شد.';}
        elseif($a==='test_exchange'){$exchange=strtolower((string)($_POST['exchange']??''));if($exchange==='bitpin'){$s=new OrderService();$c=$s->client();$c->wallets();$s->syncTokens($c);}elseif($exchange==='nobitex'){(new NobitexOrderService())->client()->test();}else throw new InvalidArgumentException('صرافی نامعتبر است.');$message='اتصال '.($exchange==='nobitex'?'Nobitex':'Bitpin').' موفق بود.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم امنیتی قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';
$status=(new BotController())->status();$ex=$status['exchanges'];$kill=$status['kill_switch'];$version=Updater::currentVersion();$tokens=AppAccess::tokens($pdo);$orders=$pdo->query('SELECT exchange_name,exchange_order_id,market_code,side,status,created_at FROM orders ORDER BY id DESC LIMIT 12')->fetchAll();$runs=$pdo->query('SELECT run_id,status,started_at FROM bot_runs ORDER BY id DESC LIMIT 8')->fetchAll();$csrf=h((string)$_SESSION['csrf']);
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade Control Center</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1180px;margin:auto;padding:18px}.top,.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.top{justify-content:space-between}.badges{display:flex;gap:7px;flex-wrap:wrap}.badge{padding:7px 10px;border-radius:999px;background:#fff;border:1px solid #e1e7f0;text-decoration:none;color:#172033}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.healthgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.card{background:#fff;border:1px solid #e1e7f0;border-radius:20px;padding:18px;margin-top:14px;box-shadow:0 8px 28px #14213a0b}.health{padding:12px;border:1px solid #e6eaf0;border-radius:13px}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#9aa5b5;margin-left:6px}.dot.ok{background:#14a06f}.dot.bad{background:#d23b3b}.ok{color:#087857}.bad{color:#b42318}.muted{color:#68748a;font-size:13px}.btn{border:0;border-radius:10px;padding:10px 13px;background:#1769ff;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn.gray{background:#657187}.btn.danger{background:#c62828}.btn.safe{background:#147a52}.msg,.err,.pair{padding:12px;border-radius:12px;margin-top:12px}.msg{background:#ecfff4;color:#176548}.err{background:#fff0f0;color:#a11}.pair{background:#eef5ff}.code{font:800 26px monospace;letter-spacing:3px;direction:ltr;text-align:center}.secret{direction:ltr;word-break:break-all;background:#f7f9fc;padding:10px;border-radius:9px;font-family:monospace}input{padding:10px;border:1px solid #ccd5e3;border-radius:9px}table{width:100%;border-collapse:collapse;font-size:12px}td,th{padding:8px;border-bottom:1px solid #edf0f5;text-align:right;white-space:nowrap}.scroll{overflow:auto}@media(max-width:760px){.wrap{padding:11px}.grid,.healthgrid{grid-template-columns:1fr}.top{flex-direction:column;align-items:flex-start}.btn{width:100%;text-align:center}.row form{width:100%}}
</style></head><body><div class="wrap">
<div class="top"><div><h1 style="margin:0 0 5px">Trade Control Center</h1><div class="muted">Backend v<?=h($version)?> — Bitpin + Nobitex</div></div><div class="badges"><a class="badge" href="/admin/exchanges.php">صرافی‌ها</a><a class="badge" href="/admin/bot/">ربات‌ها</a><a class="badge" href="/admin/update/">آپدیت</a><a class="badge" href="/admin/repair.php">تعمیر Bitpin</a><a class="badge" href="?logout=1">خروج</a></div></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif?><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?>
<div class="card"><h2 style="margin-top:0">وضعیت هوشمند سیستم</h2><div class="healthgrid" id="health"><div class="health">در حال بررسی…</div></div><button class="btn gray" style="margin-top:10px" onclick="loadHealth()">بررسی دوباره</button></div>
<div class="grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as$key=>$name):$e=$ex[$key];?><div class="card"><h2 style="margin-top:0"><?=$name?></h2><p>API: <b class="<?=$e['credentials_configured']?'ok':'bad'?>"><?=$e['credentials_configured']?'تنظیم شده':'تنظیم نشده'?></b> — Bot: <b><?=$e['bot_enabled']?'ON':'OFF'?></b> — Live: <b><?=$e['live_execution_enabled']?'ON':'OFF'?></b></p><div class="row"><a class="btn" href="/admin/exchanges.php">مدیریت <?=$name?></a><?php if($e['credentials_configured']):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="test_exchange"><input type="hidden" name="exchange" value="<?=$key?>"><button class="btn gray">تست API</button></form><?php endif?></div></div><?php endforeach?>
</div>
<div class="card"><h2 style="margin-top:0">توقف اضطراری سراسری</h2><p class="<?=$kill?'bad':'ok'?>"><b><?=$kill?'روشن — سفارش هر دو صرافی متوقف است':'خاموش'?></b></p><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$kill?'0':'1'?>"><button class="btn <?=$kill?'safe':'danger'?>"><?=$kill?'برداشتن توقف اضطراری':'توقف اضطراری هر دو صرافی'?></button></form></div>
<div class="card"><h2 style="margin-top:0">اتصال Android</h2><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create_pairing"><input name="label" value="Android phone" maxlength="120"><button class="btn">ساخت کد اتصال ۱۰ دقیقه‌ای</button></form><?php if($pairCode):?><div class="pair"><div class="code"><?=h($pairCode)?></div><a class="btn safe" href="<?=h($pairLink)?>">اتصال خودکار به اپ</a></div><?php endif?><details style="margin-top:12px"><summary>توکن دستی</summary><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="issue_token"><input name="label" value="Manual Android token"><button class="btn gray">ساخت توکن</button></form><?php if($issuedToken):?><div class="secret"><?=h($issuedToken)?></div><?php endif?></details></div>
<div class="grid"><div class="card"><h2 style="margin-top:0">دستگاه‌ها</h2><div class="scroll"><table><tr><th>نام</th><th>وضعیت</th><th></th></tr><?php foreach($tokens as$t):?><tr><td><?=h($t['label'])?></td><td><?=$t['revoked_at']?'لغوشده':'فعال'?></td><td><?php if(!$t['revoked_at']):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="<?=h($t['id'])?>"><button class="btn danger">لغو</button></form><?php endif?></td></tr><?php endforeach?></table></div></div><div class="card"><h2 style="margin-top:0">آخرین Cronها</h2><div class="scroll"><table><tr><th>Run</th><th>Status</th><th>UTC</th></tr><?php foreach($runs as$r):?><tr><td><?=h(substr((string)$r['run_id'],0,10))?></td><td><?=h($r['status'])?></td><td><?=h($r['started_at'])?></td></tr><?php endforeach?></table></div></div></div>
<div class="card"><h2 style="margin-top:0">آخرین سفارش‌ها</h2><div class="scroll"><table><tr><th>Exchange</th><th>ID</th><th>Market</th><th>Side</th><th>Status</th></tr><?php foreach($orders as$o):?><tr><td><?=h($o['exchange_name'])?></td><td><?=h($o['exchange_order_id']??'-')?></td><td><?=h($o['market_code'])?></td><td><?=h($o['side'])?></td><td><?=h($o['status'])?></td></tr><?php endforeach?></table></div></div>
</div><script>
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}function card(n,x){return `<div class="health"><b><span class="dot ${x.ok?'ok':'bad'}"></span>${n}</b><div>${esc(x.text)}</div></div>`;}async function loadHealth(){const el=document.getElementById('health');try{const r=await fetch('?ajax=health',{cache:'no-store',credentials:'same-origin'});const j=await r.json(),c=j.checks;el.innerHTML=card('Backend',c.backend)+card('Database',c.database)+card('Nobitex',c.nobitex)+card('Bitpin',c.bitpin)+card('Cron',c.cron)+card('HTTPS',c.https)+card('PHP',c.php)+card('Sodium/Ed25519',c.sodium)+card('App',c.app_tokens);}catch(e){el.innerHTML='<div class="health"><b class="bad">Health check failed</b></div>';}}loadHealth();
</script></body></html>
