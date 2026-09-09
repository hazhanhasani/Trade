<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$storage = $root . '/storage';
$lock = $storage . '/install.lock';

if (is_file($lock)) {
    http_response_code(403);
    exit('Trade is already installed.');
}

$errors = [];
$success = false;
$apiToken = null;

$requirements = [
    'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'cURL' => extension_loaded('curl'),
    'OpenSSL' => extension_loaded('openssl'),
    'JSON' => extension_loaded('json'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($requirements as $name => $ok) {
        if (!$ok) {
            $errors[] = "Missing requirement: {$name}";
        }
    }

    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminUser = trim((string) ($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $bitpinKey = trim((string) ($_POST['bitpin_key'] ?? ''));
    $bitpinSecret = trim((string) ($_POST['bitpin_secret'] ?? ''));

    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'Database name and user are required.';
    }
    if (strlen($adminPass) < 12) {
        $errors[] = 'Admin password must be at least 12 characters.';
    }
    if (($bitpinKey === '') xor ($bitpinSecret === '')) {
        $errors[] = 'Provide both Bitpin API key and secret, or leave both empty.';
    }

    if ($errors === []) {
        try {
            if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
                throw new RuntimeException('Cannot create storage directory.');
            }
            if (!is_writable($storage)) {
                throw new RuntimeException('storage directory is not writable by PHP.');
            }

            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            $schema = file_get_contents($root . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Cannot read database schema.');
            }
            $pdo->exec($schema);

            $encryptionKey = base64_encode(random_bytes(32));
            $apiToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $apiTokenHash = hash('sha256', $apiToken);

            $config = [
                'app' => [
                    'installed' => true,
                    'mode' => 'read_only',
                    'encryption_key' => $encryptionKey,
                    'api_token_hash' => $apiTokenHash,
                ],
                'database' => [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'user' => $dbUser,
                    'password' => $dbPass,
                ],
                'bitpin' => [
                    'base_url' => 'https://api.bitpin.market/api/v1',
                    'timeout' => 12,
                    'endpoints' => [
                        'authenticate' => '/usr/authenticate/',
                        'refresh' => '/usr/refresh_token/',
                        'markets' => '/mkt/markets/',
                        'wallets' => '/wlt/wallets/',
                        'orders' => '/odr/orders/',
                    ],
                ],
            ];

            $configPhp = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($storage . '/config.php', $configPhp, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write storage/config.php.');
            }
            @chmod($storage . '/config.php', 0600);

            $passwordAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $stmt = $pdo->prepare('INSERT INTO admins (username,password_hash,created_at) VALUES (:u,:p,UTC_TIMESTAMP())');
            $stmt->execute([
                ':u' => $adminUser,
                ':p' => password_hash($adminPass, $passwordAlgo),
            ]);

            if ($bitpinKey !== '' && $bitpinSecret !== '') {
                $encrypt = static function (string $plaintext) use ($encryptionKey): string {
                    $key = base64_decode($encryptionKey, true);
                    if ($key === false || strlen($key) !== 32) {
                        throw new RuntimeException('Invalid encryption key.');
                    }
                    $iv = random_bytes(12);
                    $tag = '';
                    $cipher = openssl_encrypt(
                        $plaintext,
                        'aes-256-gcm',
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        '',
                        16
                    );
                    if ($cipher === false) {
                        throw new RuntimeException('Secret encryption failed.');
                    }
                    return base64_encode($iv . $tag . $cipher);
                };

                $stmt = $pdo->prepare(
                    'INSERT INTO exchange_credentials (exchange_name,api_key_enc,secret_key_enc,created_at,updated_at) VALUES (\'bitpin\',:k,:s,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                );
                $stmt->execute([
                    ':k' => $encrypt($bitpinKey),
                    ':s' => $encrypt($bitpinSecret),
                ]);
            }

            file_put_contents($lock, gmdate(DATE_ATOM) . "\n", LOCK_EX);
            @chmod($lock, 0600);
            $success = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب Trade</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f4f7fb;color:#172033;margin:0}.wrap{max-width:760px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e4e9f2;border-radius:20px;padding:24px;box-shadow:0 12px 35px rgba(31,44,70,.08)}h1{margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}label{display:block;font-size:13px;margin:8px 0 5px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd5e3;border-radius:10px}button{width:100%;padding:13px;border:0;border-radius:12px;background:#1769ff;color:#fff;font-weight:700;margin-top:18px}.ok{background:#ecfff2;border:1px solid #bce8c9;padding:14px;border-radius:12px}.info{background:#eef5ff;border:1px solid #cfe0ff;padding:12px;border-radius:12px;margin:12px 0}.err{background:#fff1f1;border:1px solid #ffc7c7;padding:12px;border-radius:12px;margin-bottom:10px}.req{font-size:13px;margin:5px 0}.token{direction:ltr;word-break:break-all;background:#f2f5fa;padding:12px;border-radius:10px}@media(max-width:650px){.grid{grid-template-columns:1fr}.wrap{margin:10px auto;padding:12px}}
</style>
</head>
<body><div class="wrap"><div class="card">
<h1>نصب سریع Trade</h1>
<div class="info">این نسخه برای مانیتورینگ، تحلیل و توسعه Paper Trading طراحی شده و سفارش واقعی جدید به صرافی ارسال نمی‌کند.</div>
<p>بررسی پیش‌نیازها:</p>
<?php foreach ($requirements as $name => $ok): ?>
<div class="req"><?= $ok ? '✅' : '❌' ?> <?= htmlspecialchars($name) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<div class="err"><?= htmlspecialchars($error) ?></div>
<?php endforeach; ?>
<?php if ($success): ?>
<div class="ok">
<strong>نصب با موفقیت انجام شد.</strong>
<p>توکن اتصال اپ فقط همین یک‌بار نمایش داده می‌شود. آن را در جای امن نگه دارید:</p>
<div class="token"><?= htmlspecialchars((string) $apiToken) ?></div>
<p>پنل مدیریت: <b>/admin/</b></p>
<p>حالا Cron را طبق README تنظیم کنید.</p>
</div>
<?php else: ?>
<form method="post" autocomplete="off"><div class="grid">
<div><label>DB Host</label><input name="db_host" value="localhost" required></div>
<div><label>DB Port</label><input name="db_port" value="3306" required></div>
<div><label>Database</label><input name="db_name" required></div>
<div><label>DB User</label><input name="db_user" required></div>
<div class="full"><label>DB Password</label><input type="password" name="db_pass"></div>
<div><label>Admin username</label><input name="admin_user" value="admin" required></div>
<div><label>Admin password (حداقل ۱۲ کاراکتر)</label><input type="password" name="admin_pass" minlength="12" required></div>
<div class="full"><label>Bitpin API Key (اختیاری)</label><input name="bitpin_key" autocomplete="off"></div>
<div class="full"><label>Bitpin Secret Key (اختیاری)</label><input type="password" name="bitpin_secret" autocomplete="new-password"></div>
</div><button type="submit">نصب Trade</button></form>
<?php endif; ?>
</div></div></body></html>
