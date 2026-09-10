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
