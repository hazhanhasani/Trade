<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nav = file_get_contents($root . '/backend/public/admin/_nav.php');
$ui = file_get_contents($root . '/backend/public/admin/assets/cockpit-ui.css');
$js = file_get_contents($root . '/backend/public/admin/assets/cockpit.js');
$app = file_get_contents($root . '/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt');

function ux(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

ux(is_string($nav) && is_string($ui) && is_string($js) && is_string($app), 'UI/UX sources unavailable');
ux(str_contains($nav, 'mobile-nav-menu'), 'mobile primary navigation disclosure missing');
ux(substr_count($nav, 'aria-current="page"') >= 4, 'active primary and module navigation must expose aria-current');
ux(!str_contains($nav, '.global-subnav ~ .subnav:not(.global-subnav)'), 'global nav must not hide page-local sub-navigation');
ux(!str_contains($nav, '?>>>') && !str_contains($nav, '?>> >'), 'navigation anchor markup contains an accidental extra closing bracket');
ux(str_contains($nav, 'cockpit-ui.css?v=6') && str_contains($nav, 'cockpit.js?v=6'), 'admin UI assets must use the current cache-busted revision');
ux(str_contains($ui, 'a:focus-visible'), 'keyboard focus treatment missing');
ux(str_contains($ui, 'prefers-reduced-motion:reduce'), 'reduced-motion support missing');
ux(str_contains($ui, 'min-height:44px'), 'mobile touch targets must be at least 44px');
ux(str_contains($js, 'wireMobileMenus'), 'mobile disclosure behavior missing');
ux(str_contains($app, 'CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl)'), 'Persian app must force RTL layout independently of device locale');
ux(str_contains($app, 'رادار بازار') && str_contains($app, 'نقشه حرارتی ریسک'), 'primary Android content is not localized');
ux(str_contains($app, 'داده زنده') && str_contains($app, 'نسخه آفلاین'), 'Android live/offline state labels must be localized and explicit');
ux(str_contains($app, 'listOf("info", "success", "warning", "critical").chunked(2)'), 'priority controls must not overflow narrow screens');
ux(str_contains($app, 'modifier = Modifier.fillMaxWidth(), singleLine = true'), 'custom risk inputs must have full-width mobile layout');
ux(str_contains($app, 'style = MaterialTheme.typography.titleLarge, maxLines = 2'), 'metric values must not be clipped to one line');
ux(str_contains($app, 'Text(value, fontWeight = FontWeight.Bold, maxLines = 2'), 'tiny metric values must remain readable');

echo "Trade 1.4.23 UI/UX regression checks passed.\n";
