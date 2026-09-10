<?php

declare(strict_types=1);

function tradeAdminNav(string $active, string $version): void
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if ($path === '/admin/analytics.php') $active = 'analytics';
    elseif ($path === '/admin/notifications.php') $active = 'notifications';

    $items = [
        'dashboard' => ['/admin/', 'داشبورد'],
        'trading' => ['/admin/bot/', 'معاملات'],
        'intelligence' => ['/admin/bot/intelligence.php', 'مدیریت ریسک هوشمند'],
        'analytics' => ['/admin/analytics.php', 'عملکرد'],
        'exchanges' => ['/admin/exchanges.php', 'صرافی‌ها'],
        'notifications' => ['/admin/notifications.php', 'اعلان‌ها'],
        'devices' => ['/admin/devices.php', 'دستگاه‌ها'],
        'system' => ['/admin/system.php', 'سیستم'],
    ];
    ?>
    <style id="trade-mobile-overflow-guard">
    @media (max-width: 720px) {
        html, body {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
        }
        .admin-shell {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            padding: 10px !important;
        }
        .admin-topbar {
            position: static !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 0 14px !important;
            padding: 10px !important;
        }
        .admin-toprow,
        .admin-brand,
        .admin-nav,
        .page-head,
        .panel-grid,
        .panel,
        .stat-grid,
        .stat-card,
        .metric-grid,
        .metric,
        .field-grid,
        .field,
        .subnav,
        .actions,
        .table-wrap,
        form,
        fieldset,
        details {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
        .admin-nav {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px !important;
            overflow: hidden !important;
        }
        .admin-nav a,
        .subnav a,
        .btn,
        .badge,
        .hero-pill {
            max-width: 100% !important;
            min-width: 0 !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        .table-wrap {
            overflow-x: hidden !important;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: auto !important;
        }
        .table-wrap table,
        table {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            table-layout: fixed !important;
        }
        thead, tbody, tr {
            max-width: 100% !important;
            min-width: 0 !important;
        }
        th, td {
            width: auto !important;
            min-width: 0 !important;
            max-width: none !important;
            padding: 7px 4px !important;
            font-size: 9px !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        input, select, textarea, button, pre, code, iframe, canvas, video, img, svg {
            max-width: 100% !important;
            min-width: 0 !important;
        }
        pre, code, .code {
            white-space: pre-wrap !important;
            word-break: break-all !important;
            overflow-x: hidden !important;
        }
        .page-head *, .panel *, .stat-card *, .metric *, .notice *, .field * {
            max-width: 100%;
            overflow-wrap: anywhere;
        }
    }
    @media (max-width: 380px) {
        .admin-nav,
        .subnav,
        .stat-grid,
        .metric-grid {
            grid-template-columns: 1fr !important;
        }
    }
    </style>
    <div class="admin-topbar"><div class="admin-toprow">
        <div class="admin-brand"><div class="admin-logo">T</div><div><b>مدیریت Trade</b><small>نسخه Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • کنترل معاملات واقعی</small></div></div>
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
    ?><div class="admin-footer">Trade • Backend <?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • پنل مدیریت</div><?php
}
