<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);$storage=$root.'/storage';$lock=$storage.'/install.lock';
if(is_file($lock)){http_response_code(403);exit('Trade is already installed.');}
$errors=[];$success=false;$apiToken=null;
$requirements=['PHP >= 8.2'=>PHP_VERSION_ID>=80200,'PDO MySQL'=>extension_loaded('pdo_mysql'),'cURL'=>extension_loaded('curl'),'OpenSSL'=>extension_loaded('openssl'),'ZIP'=>extension_loaded('zip'),'JSON'=>extension_loaded('json')];

if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach($requirements as$name=>$ok)if(!$ok)$errors[]="Missing requirement: {$name}";
    $dbHost=trim((string)($_POST['db_host']??'localhost'));$dbPort=(int)($_POST['db_port']??3306);$dbName=trim((string)($_POST['db_name']??''));$dbUser=trim((string)($_POST['db_user']??''));$dbPass=(string)($_POST['db_pass']??'');
    $adminUser=trim((string)($_POST['admin_user']??'admin'));$adminPass=(string)($_POST['admin_pass']??'');
    $nobitexPublic=trim((string)($_POST['nobitex_public_key']??''));$nobitexPrivate=trim((string)($_POST['nobitex_private_key']??''));
    $maxOrderValue=max(0,(float)($_POST['max_order_value']??0));$maxOrdersPerHour=max(1,min(100,(int)($_POST['max_orders_per_hour']??10)));
    if($dbName===''||$dbUser==='')$errors[]='Database name and user are required.';
    if(strlen($adminPass)<12)$errors[]='Admin password must be at least 12 characters.';
    if(($nobitexPublic==='')xor($nobitexPrivate===''))$errors[]='Provide both Nobitex Public Key and Private Key, or leave both empty.';
    if($nobitexPublic!==''&&!function_exists('sodium_crypto_sign_detached'))$errors[]='PHP Sodium is required for Nobitex Ed25519 API keys.';

    if($errors===[]){
        try{
            if(!is_dir($storage)&&!mkdir($storage,0700,true)&&!is_dir($storage))throw new RuntimeException('Cannot create storage directory.');
            if(!is_writable($storage))throw new RuntimeException('storage directory is not writable by PHP.');
            $pdo=new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
            $schema=file_get_contents($root.'/database/schema.sql');if($schema===false)throw new RuntimeException('Cannot read database schema.');$pdo->exec($schema);
            $encryptionKey=base64_encode(random_bytes(32));$apiToken=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $config=[
                'app'=>['installed'=>true,'url'=>'https://rado-taxi.sbs','mode'=>'live_disabled','encryption_key'=>$encryptionKey,'api_token_hash'=>hash('sha256',$apiToken)],
                'database'=>['host'=>$dbHost,'port'=>$dbPort,'name'=>$dbName,'user'=>$dbUser,'password'=>$dbPass],
                'nobitex'=>['base_url'=>'https://apiv2.nobitex.ir','public_base_url'=>'https://api.nobitex.ir','timeout'=>12],
                'trading'=>['capital_asset'=>'IRT/USDT','max_order_value'=>$maxOrderValue,'max_orders_per_hour'=>$maxOrdersPerHour],
                'updates'=>['auto_backend'=>true,'check_interval_seconds'=>60,'manifest_url'=>'https://github.com/hazhanhasani/Trade/releases/download/trade-latest/latest.json'],
            ];
            $configPhp="<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($config,true).";\n";if(file_put_contents($storage.'/config.php',$configPhp,LOCK_EX)===false)throw new RuntimeException('Cannot write storage/config.php.');@chmod($storage.'/config.php',0600);
            $algo=defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT;$stmt=$pdo->prepare('INSERT INTO admins (username,password_hash,created_at) VALUES (:u,:p,UTC_TIMESTAMP())');$stmt->execute([':u'=>$adminUser,':p'=>password_hash($adminPass,$algo)]);
            $encrypt=static function(string $plaintext)use($encryptionKey):string{$key=base64_decode($encryptionKey,true);if($key===false||strlen($key)!==32)throw new RuntimeException('Invalid encryption key.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);if($cipher===false)throw new RuntimeException('Secret encryption failed.');return base64_encode($iv.$tag.$cipher);};
            if($nobitexPublic!==''&&$nobitexPrivate!==''){$stmt=$pdo->prepare("INSERT INTO exchange_credentials (exchange_name,api_key_enc,secret_key_enc,created_at,updated_at) VALUES ('nobitex',:k,:s,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([':k'=>$encrypt($nobitexPublic),':s'=>$encrypt($nobitexPrivate)]);}
            $settings=['kill_switch'=>'0','live_trading_nobitex_enabled'=>'0','autotrade_nobitex_enabled'=>'0','legacy_execution_cleanup_version'=>'3'];$stmt=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");foreach($settings as$k=>$v)$stmt->execute([':k'=>$k,':v'=>$v]);
            $pdo->exec("INSERT IGNORE INTO nobitex_autotrade_settings (id,enabled,quote_asset,risk_profile,position_percent,max_position_percent,stop_loss_percent,take_profit_percent,daily_loss_limit_percent,min_signal_score,cooldown_minutes,updated_at) VALUES (1,0,'IRT','balanced',5,10,3,6,5,60,15,UTC_TIMESTAMP())");
            file_put_contents($lock,gmdate(DATE_ATOM)."\n",LOCK_EX);@chmod($lock,0600);$success=true;
        }catch(Throwable $e){$errors[]=$e->getMessage();}
    }
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب Trade</title><style>body{font-family:Tahoma,Arial;background:#f4f7fb;color:#172033;margin:0}.wrap{max-width:820px;margin:30px auto;padding:16px}.card{background:#fff;border:1px solid #e4e9f2;border-radius:20px;padding:24px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}label{display:block;font-size:13px;margin:8px 0 5px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd5e3;border-radius:10px}button{width:100%;padding:13px;border:0;border-radius:12px;background:#1769ff;color:#fff;font-weight:700;margin-top:18px}.ok,.info,.err{padding:12px;border-radius:12px;margin:10px 0}.ok{background:#ecfff2}.info{background:#eef5ff}.err{background:#fff1f1;color:#a11}.token{direction:ltr;word-break:break-all;background:#f2f5fa;padding:12px;border-radius:10px}@media(max-width:650px){.grid{grid-template-columns:1fr}.wrap{margin:5px auto}.full{grid-column:auto}}</style></head><body><div class="wrap"><div class="card"><h1>نصب Trade</h1><div class="info"><b>Nobitex</b> تنها صرافی اجرای سفارش است. Bitpin، آبان‌تتر، بیت۲۴ و تبدیل از پنل صرافی‌ها فقط به‌عنوان منابع Market Data مدیریت می‌شوند.</div>
<?php foreach($requirements as$name=>$ok):?><div><?=$ok?'✅':'❌'?> <?=htmlspecialchars($name)?></div><?php endforeach?><?php foreach($errors as$e):?><div class="err"><?=htmlspecialchars($e)?></div><?php endforeach?>
<?php if($success):?><div class="ok"><b>نصب موفق بود.</b><p>پنل: <code>/admin/</code> — منابع داده/صرافی: <code>/admin/exchanges.php</code></p><p>توکن اتصال اپ:</p><div class="token"><?=htmlspecialchars((string)$apiToken)?></div><p>Cron را Every Minute روی <code>cron/tick.php</code> تنظیم کن.</p></div><?php else:?><form method="post" autocomplete="off"><div class="grid"><div><label>DB Host</label><input name="db_host" value="localhost" required></div><div><label>DB Port</label><input name="db_port" value="3306" required></div><div><label>Database</label><input name="db_name" required></div><div><label>DB User</label><input name="db_user" required></div><div class="full"><label>DB Password</label><input type="password" name="db_pass"></div><div><label>Admin username</label><input name="admin_user" value="admin" required></div><div><label>Admin password (حداقل ۱۲ کاراکتر)</label><input type="password" name="admin_pass" minlength="12" required></div><div class="full"><h3>Nobitex</h3><div class="info">کلید نوبیتکس اختیاری است و پس از نصب هم می‌توان از پنل وارد کرد. مجوز READ + TRADE کافی است؛ WITHDRAW لازم نیست.</div></div><div class="full"><label>Nobitex Public Key</label><input name="nobitex_public_key" autocomplete="off"></div><div class="full"><label>Nobitex Private Key</label><input type="password" name="nobitex_private_key" autocomplete="new-password"></div><div><label>حداکثر ارزش هر سفارش (۰ = بدون سقف)</label><input type="number" min="0" step="any" name="max_order_value" value="0"></div><div><label>حداکثر سفارش در ساعت</label><input type="number" min="1" max="100" name="max_orders_per_hour" value="10"></div></div><button type="submit">نصب Production</button></form><?php endif?></div></div></body></html>
