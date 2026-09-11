<?php

declare(strict_types=1);

function tradeAdminNav(string $active, string $version): void
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if ($path === '/admin/command-center.php') $active = 'command';
    elseif ($path === '/admin/project-health.php') $active = 'health';
    elseif ($path === '/admin/analytics.php') $active = 'analytics';
    elseif ($path === '/admin/notifications.php') $active = 'notifications';
    elseif ($path === '/admin/market.php') $active = 'market';
    elseif ($path === '/admin/timeline.php') $active = 'timeline';
    elseif (str_starts_with($path, '/admin/bot/') || $path === '/admin/tradingview.php') $active = 'trading';
    elseif (in_array($path, ['/admin/devices.php','/admin/system.php','/admin/cron-run.php','/admin/signing.php','/admin/repair.php','/admin/update/','/admin/update/index.php','/admin/logs.php'], true)) $active = 'system';
    elseif ($path === '/admin/exchanges.php') $active = 'exchanges';

    $items = [
        'dashboard' => ['/admin/', 'داشبورد'],
        'command' => ['/admin/command-center.php', 'مرکز فرمان'],
        'health' => ['/admin/project-health.php', 'پایش پروژه'],
        'market' => ['/admin/market.php', 'بازار و اخبار'],
        'trading' => ['/admin/bot/', 'معاملات'],
        'timeline' => ['/admin/timeline.php', 'تایم‌لاین'],
        'analytics' => ['/admin/analytics.php', 'عملکرد'],
        'exchanges' => ['/admin/exchanges.php', 'صرافی‌ها'],
        'notifications' => ['/admin/notifications.php', 'اعلان‌ها'],
        'system' => ['/admin/system.php', 'سیستم'],
    ];
    ?>
    <style id="trade-theme-prepaint">html:not([data-theme-ready="true"]) body{visibility:hidden!important}html:not(.theme-animated) *,html:not(.theme-animated) *::before,html:not(.theme-animated) *::after{transition:none!important}</style>
    <script id="trade-theme-bootstrap">
    (()=>{const root=document.documentElement;let stored=null;try{stored=localStorage.getItem('trade-theme')}catch(_){}const theme=(stored==='dark'||stored==='light')?stored:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');root.dataset.theme=theme;const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.setAttribute('content',theme==='dark'?'#0d111b':'#f3f5f9');})();
    </script>
    <link rel="stylesheet" href="/admin/assets/cockpit-ui.css?v=7">
    <script>document.documentElement.dataset.themeReady='true';requestAnimationFrame(()=>requestAnimationFrame(()=>document.documentElement.classList.add('theme-animated')));</script>
    <script defer src="/admin/assets/cockpit.js?v=7"></script>
    <style id="trade-mobile-overflow-guard">
    html,body{max-width:100%!important;overflow-x:clip!important}.admin-shell,.admin-shell>*{max-width:100%!important;min-width:0!important}
    @media(max-width:1100px){html,body{width:100%!important;max-width:100%!important;overflow-x:clip!important}.admin-shell{width:100%!important;max-width:100%!important;min-width:0!important;overflow-x:clip!important}.admin-topbar{width:100%!important;max-width:100%!important;margin-left:0!important;margin-right:0!important}.admin-toprow,.admin-brand,.admin-nav,.page-head,.hero-panel,.panel-grid,.panel,.stat-grid,.stat-card,.metric-grid,.metric,.field-grid,.field,.subnav,.actions,.table-wrap,form,fieldset,details{max-width:100%!important;min-width:0!important}.table-wrap{width:100%!important;overflow-x:hidden!important;overflow-y:visible!important;-webkit-overflow-scrolling:auto!important}.table-wrap table,table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed!important}thead,tbody,tr,th,td{max-width:100%!important;min-width:0!important}th,td{white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word!important}.admin-nav a,.subnav a,.btn,.badge,.hero-pill,.notice,.metric,.stat-card{max-width:100%!important;min-width:0!important;white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word!important}input,select,textarea,button,pre,code,iframe,canvas,video,img,svg{max-width:100%!important;min-width:0!important}pre,code,.code,.mono{white-space:pre-wrap!important;word-break:break-all!important;overflow-x:hidden!important}}
    @media(max-width:720px){.admin-topbar{position:static!important;margin:0 0 14px!important}.admin-nav{display:none!important}.mobile-nav-menu{display:block!important}.global-subnav{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important}}
    @media(max-width:380px){.admin-nav,.subnav,.stat-grid,.metric-grid,.global-subnav{grid-template-columns:1fr!important}}
    </style>
    <div class="admin-topbar"><div class="admin-toprow">
        <div class="admin-brand"><div class="admin-logo">T</div><div><b>مدیریت Trade</b><small>Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • معاملات واقعی نوبیتکس</small></div></div>
        <div class="trade-top-tools"><time class="trade-clock" data-iran-clock>زمان ایران…</time><button class="theme-toggle" type="button" data-theme-toggle>☾ تاریک</button></div>
        <details class="mobile-nav-menu">
            <summary><span>منوی اصلی</span><b><?=htmlspecialchars((string)($items[$active][1] ?? 'منو'), ENT_QUOTES, 'UTF-8')?></b></summary>
            <nav class="mobile-nav-grid" aria-label="منوی اصلی موبایل">
                <?php foreach ($items as $key => [$href, $label]): ?><a class="<?=$active === $key ? 'active' : ''?>" href="<?=$href?>" <?=$active === $key ? 'aria-current="page"' : ''?>><?=$label?></a><?php endforeach; ?>
                <a class="logout" href="/admin/?logout=1">خروج</a>
            </nav>
        </details>
        <nav class="admin-nav" aria-label="منوی اصلی">
            <?php foreach ($items as $key => [$href, $label]): ?><a class="<?=$active === $key ? 'active' : ''?>" href="<?=$href?>" <?=$active === $key ? 'aria-current="page"' : ''?>><?=$label?></a><?php endforeach; ?>
            <a class="logout" href="/admin/?logout=1">خروج</a>
        </nav>
    </div></div>
    <?php if($active==='trading'):
        $mods=[
            ['/admin/bot/','معاملات'],['/admin/bot/settings.php','تنظیمات معاملات'],['/admin/bot/reconcile.php','همگام‌سازی پوزیشن‌ها'],['/admin/bot/intelligence.php','ریسک هوشمند'],['/admin/bot/learning.php','یادگیری استراتژی'],
            ['/admin/bot/execution.php','یادگیری اجرا'],['/admin/bot/calibration.php','کالیبراسیون Edge'],['/admin/bot/rotation.php','تعویض فرصت‌ها'],['/admin/bot/advanced.php','پیشرفته'],['/admin/tradingview.php','TradingView'],
        ]; ?>
        <nav class="subnav global-subnav" aria-label="ماژول‌های معاملات"><?php foreach($mods as [$href,$label]):?><a class="<?=$path===$href?'active':''?>" href="<?=$href?>" <?=$path===$href?'aria-current="page"':''?>><?=$label?></a><?php endforeach?></nav>
    <?php elseif($active==='system'):
        $mods=[['/admin/system.php','سلامت سیستم'],['/admin/logs.php','خطا و لاگ'],['/admin/devices.php','دستگاه‌ها'],['/admin/update/','بروزرسانی'],['/admin/repair.php','عیب‌یابی']]; ?>
        <nav class="subnav global-subnav" aria-label="ماژول‌های سیستم"><?php foreach($mods as [$href,$label]):?><a class="<?=($path===$href||($href==='/admin/update/'&&str_starts_with($path,'/admin/update/')))?'active':''?>" href="<?=$href?>" <?=($path===$href||($href==='/admin/update/'&&str_starts_with($path,'/admin/update/')))?'aria-current="page"':''?>><?=$label?></a><?php endforeach?></nav>
    <?php endif;
}

function tradeAdminFooter(string $version): void
{
    ?><div class="admin-footer">Trade • Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • <span class="live-dot"></span>زمان و تقویم پنل: ایران / شمسی</div><?php
}
