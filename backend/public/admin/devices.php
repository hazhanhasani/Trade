<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

$pdo = Database::connection();
AppAccess::bootstrapLegacy($pdo);
AppAccess::cleanup($pdo);
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$message=''; $error=''; $pairCode=''; $pairLink=''; $issuedToken='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $posted=(string)($_POST['csrf']??''); $session=(string)($_SESSION['csrf']??'');
    if ($posted==='' || $session==='' || !hash_equals($session,$posted)) {
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        header('Location: /admin/devices.php?csrf_refresh=1', true, 303); exit;
    }
    try {
        $action=(string)($_POST['action']??'');
        if ($action==='create_pairing') {
            $pair=AppAccess::createPairing($pdo,(int)$_SESSION['admin_id'],trim((string)($_POST['label']??'Android phone')));
            $pairCode=(string)$pair['code'];
            $pairLink='trade://pair?server='.rawurlencode((string)Config::get('app.url','https://rado-taxi.sbs')).'&code='.rawurlencode($pairCode);
            $message='کد اتصال ۱۰ دقیقه‌ای ساخته شد.';
        } elseif ($action==='issue_token') {
            $issued=AppAccess::issueToken($pdo,trim((string)($_POST['label']??'Manual token')),(int)$_SESSION['admin_id']);
            $issuedToken=(string)$issued['token'];
            $message='توکن جدید ساخته شد؛ فقط همین یک‌بار نمایش داده می‌شود.';
        } elseif ($action==='revoke_token') {
            AppAccess::revoke($pdo,(int)($_POST['token_id']??0));
            $message='اتصال دستگاه لغو شد.';
        }
    } catch (Throwable $e) { $error=mb_substr($e->getMessage(),0,700); }
}
if (isset($_GET['csrf_refresh'])) $error='فرم امنیتی قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$tokens=AppAccess::tokens($pdo);
$activeCount=AppAccess::activeCount($pdo);
$csrf=h((string)$_SESSION['csrf']);
$version=Updater::currentVersion();
require __DIR__.'/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دستگاه‌ها — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.pair-code{font:900 30px monospace;letter-spacing:5px;direction:ltr;text-align:center;padding:16px;border-radius:16px;background:#111827;color:#fff}.secret{direction:ltr;word-break:break-all;background:#111827;color:#e5e7eb;padding:12px;border-radius:12px;font-family:monospace;margin-top:10px}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('devices',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">ACCESS / DEVICES</div><h1>دستگاه‌ها</h1><p>اتصال اپ Android، Pairing یک‌بارمصرف و مدیریت Tokenها فقط در این بخش انجام می‌شود.</p></div><span class="badge good"><?=$activeCount?> دستگاه فعال</span></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>

<div class="panel-grid">
<section class="panel soft"><div class="panel-head"><div><h2>اتصال سریع Android</h2><p>Pairing Code فقط ۱۰ دقیقه اعتبار دارد و نیاز به تایپ Token در اپ را حذف می‌کند.</p></div><span class="badge info">PAIRING</span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create_pairing"><div class="field"><label>نام دستگاه</label><input name="label" value="Android phone" maxlength="120"></div><div class="actions" style="margin-top:12px"><button class="btn">ساخت کد اتصال</button></div></form><?php if($pairCode!==''):?><div style="margin-top:14px"><div class="pair-code"><?=h($pairCode)?></div><div class="actions" style="margin-top:10px"><a class="btn safe" href="<?=h($pairLink)?>">اتصال مستقیم به اپ</a></div></div><?php endif?></section>

<section class="panel"><div class="panel-head"><div><h2>توکن دستی</h2><p>فقط برای شرایط اضطراری یا اتصال دستی؛ مقدار Token بعد از ساخت دوباره قابل نمایش نیست.</p></div><span class="badge warn">ADVANCED</span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="issue_token"><div class="field"><label>برچسب Token</label><input name="label" value="Manual Android token" maxlength="120"></div><div class="actions" style="margin-top:12px"><button class="btn secondary">ساخت Token</button></div></form><?php if($issuedToken!==''):?><div class="secret"><?=h($issuedToken)?></div><?php endif?></section>
</div>

<section class="panel"><div class="panel-head"><div><h2>دستگاه‌ها و Tokenهای ثبت‌شده</h2><p>لغو دسترسی فقط از این جدول انجام می‌شود.</p></div><span class="badge info"><?=count($tokens)?> TOTAL</span></div><div class="table-wrap"><table><thead><tr><th>نام</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php if($tokens===[]):?><tr><td colspan="3" class="empty">دستگاهی ثبت نشده است.</td></tr><?php endif?><?php foreach($tokens as $t):?><tr><td><b><?=h($t['label'])?></b></td><td><span class="badge <?=$t['revoked_at']?'bad':'good'?>"><?=$t['revoked_at']?'لغوشده':'فعال'?></span></td><td><?php if(!$t['revoked_at']):?><form method="post" onsubmit="return confirm('دسترسی این دستگاه لغو شود؟');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="<?=h($t['id'])?>"><button class="btn danger">لغو دسترسی</button></form><?php else:?>—<?php endif?></td></tr><?php endforeach?></tbody></table></div></section>
<?php tradeAdminFooter($version); ?>
</div></body></html>