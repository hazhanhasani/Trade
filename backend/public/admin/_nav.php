<?php

declare(strict_types=1);

function tradeAdminNav(string $active, string $version): void
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if ($path === '/admin/analytics.php') $active = 'analytics';
    elseif ($path === '/admin/notifications.php') $active = 'notifications';
    elseif ($path === '/admin/market.php') $active = 'market';
    elseif ($path === '/admin/timeline.php') $active = 'timeline';
    elseif (str_starts_with($path, '/admin/bot/') || $path === '/admin/tradingview.php') $active = 'trading';
    elseif (in_array($path, ['/admin/devices.php','/admin/system.php','/admin/cron-run.php','/admin/signing.php','/admin/repair.php','/admin/update/','/admin/update/index.php','/admin/logs.php'], true)) $active = 'system';
    elseif ($path === '/admin/exchanges.php') $active = 'exchanges';

    $items = [
        'dashboard' => ['/admin/', 'داشبورد'],
        'market' => ['/admin/market.php', 'بازار و اخبار'],
        'trading' => ['/admin/bot/', 'معاملات'],
        'timeline' => ['/admin/timeline.php', 'تایم‌لاین'],
        'analytics' => ['/admin/analytics.php', 'عملکرد'],
        'exchanges' => ['/admin/exchanges.php', 'صرافی‌ها'],
        'notifications' => ['/admin/notifications.php', 'اعلان‌ها'],
        'system' => ['/admin/system.php', 'سیستم'],
    ];
    ?>
    <link rel="stylesheet" href="/admin/assets/cockpit-ui.css?v=4">
    <script defer src="/admin/assets/cockpit.js?v=4"></script>
    <style id="trade-mobile-overflow-guard">
    html, body { max-width:100%!important; overflow-x:clip!important; }
    .admin-shell,.admin-shell>*{max-width:100%!important;min-width:0!important}
    @media(max-width:1100px){
      html,body{width:100%!important;max-width:100%!important;overflow-x:clip!important}.admin-shell{width:100%!important;max-width:100%!important;min-width:0!important;overflow-x:clip!important}.admin-topbar{width:100%!important;max-width:100%!important;margin-left:0!important;margin-right:0!important}
      .admin-toprow,.admin-brand,.admin-nav,.page-head,.hero-panel,.panel-grid,.panel,.stat-grid,.stat-card,.metric-grid,.metric,.field-grid,.field,.subnav,.actions,.table-wrap,form,fieldset,details{max-width:100%!important;min-width:0!important}
      .table-wrap{width:100%!important;overflow-x:hidden!important;overflow-y:visible!important;-webkit-overflow-scrolling:auto!important}.table-wrap table,table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed!important}thead,tbody,tr,th,td{max-width:100%!important;min-width:0!important}th,td{white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word!important}
      .admin-nav a,.subnav a,.btn,.badge,.hero-pill,.notice,.metric,.stat-card{max-width:100%!important;min-width:0!important;white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word!important}input,select,textarea,button,pre,code,iframe,canvas,video,img,svg{max-width:100%!important;min-width:0!important}pre,code,.code,.mono{white-space:pre-wrap!important;word-break:break-all!important;overflow-x:hidden!important}
    }
    @media(max-width:720px){.admin-topbar{position:static!important;margin:0 0 14px!important}.admin-nav{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:6px!important;overflow:hidden!important}.admin-nav a{width:100%!important}}
    @media(max-width:380px){.admin-nav,.subnav,.stat-grid,.metric-grid{grid-template-columns:1fr!important}}
    </style>
    <div class="admin-topbar"><div class="admin-toprow">
        <div class="admin-brand"><div class="admin-logo">T</div><div><b>مدیریت Trade</b><small>Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • معاملات واقعی نوبیتکس</small></div></div>
        <div class="trade-top-tools"><time class="trade-clock" data-iran-clock>زمان ایران…</time><button class="theme-toggle" type="button" data-theme-toggle>☾ تاریک</button></div>
        <nav class="admin-nav" aria-label="منوی اصلی">
            <?php foreach ($items as $key => [$href, $label]): ?>
                <a class="<?=$active === $key ? 'active' : ''?>" href="<?=$href?>"><?=$label?></a>
            <?php endforeach; ?>
            <a class="logout" href="/admin/?logout=1">خروج</a>
        </nav>
    </div></div>
    <?php
}

function tradeAdminFooter(string $version): void
{
    ?><div class="admin-footer">Trade • Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • <span class="live-dot"></span>زمان و تقویم پنل: ایران / شمسی</div><?php
}
