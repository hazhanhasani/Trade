<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nav = file_get_contents($root . '/backend/public/admin/_nav.php') ?: '';
$ui = file_get_contents($root . '/backend/public/admin/assets/cockpit-ui.css') ?: '';
$js = file_get_contents($root . '/backend/public/admin/assets/cockpit.js') ?: '';
$live = file_get_contents($root . '/backend/public/admin/live-dashboard.php') ?: '';
$bale = file_get_contents($root . '/backend/src/Observability/BaleSystemAlert.php') ?: '';

function d24(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

d24(str_contains($nav, 'trade-theme-bootstrap'), 'pre-paint theme bootstrap missing');
d24(str_contains($nav, 'data-theme-ready'), 'theme reveal guard missing');
d24(str_contains($nav, 'cockpit-ui.css?v=7') && str_contains($nav, 'cockpit.js?v=7'), 'theme assets must be cache-busted');
d24(str_contains($ui, 'Trade Dark Contrast v7'), 'dark contrast layer missing');
d24(str_contains($ui, '.cc-radar .excellent') && str_contains($ui, '.cc-danger'), 'command-center dark surfaces are not covered');
d24(str_contains($js, 'prepareLiveDashboard'), 'dashboard truth loading state missing');
d24(str_contains($js, 'ارزش کیف پول نوبیتکس') && str_contains($js, 'wallet_total_toman'), 'wallet truth is not visible on dashboard');
d24(str_contains($js, 'ظرفیت تکمیل؛ خرید جدید تا آزاد شدن اسلات متوقف است'), 'portfolio_full explanation missing from dashboard');
d24(str_contains($live, 'NobitexPortfolioSnapshotCache') && str_contains($live, "'portfolio_truth'=>"), 'live dashboard does not expose Nobitex wallet truth');
d24(str_contains($bale, "'info' => 600") && str_contains($bale, '$dedupeSeconds'), 'routine Bale info dedupe is not enforced');

echo "Trade 1.4.24 dashboard truth/theme regression checks passed.\n";
