<?php

declare(strict_types=1);

function tradeAdminNav(string $active, string $version): void
{
    $items = [
        'dashboard' => ['/admin/', 'داشبورد'],
        'trading' => ['/admin/bot/', 'معاملات'],
        'intelligence' => ['/admin/bot/intelligence.php', 'Intelligence'],
        'exchanges' => ['/admin/exchanges.php', 'صرافی‌ها'],
        'devices' => ['/admin/devices.php', 'دستگاه‌ها'],
        'system' => ['/admin/system.php', 'سیستم'],
    ];
    ?>
    <div class="admin-topbar"><div class="admin-toprow">
        <div class="admin-brand"><div class="admin-logo">T</div><div><b>Trade Cockpit</b><small>Backend v<?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • Production Control</small></div></div>
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
    ?><div class="admin-footer">Trade Cockpit • Backend v<?=htmlspecialchars($version, ENT_QUOTES, 'UTF-8')?> • Structured Admin v3</div><?php
}
