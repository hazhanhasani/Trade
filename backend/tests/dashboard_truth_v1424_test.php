<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nav = file_get_contents($root . '/backend/public/admin/_nav.php') ?: '';
$ui = file_get_contents($root . '/backend/public/admin/assets/cockpit-ui.css') ?: '';
$js = file_get_contents($root . '/backend/public/admin/assets/cockpit.js') ?: '';
$index = file_get_contents($root . '/backend/public/admin/index.php') ?: '';
$live = file_get_contents($root . '/backend/public/admin/live-dashboard.php') ?: '';
$valuation = file_get_contents($root . '/backend/src/Trading/NobitexPortfolioValuation.php') ?: '';
$bale = file_get_contents($root . '/backend/src/Observability/BaleSystemAlert.php') ?: '';

function d24(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

d24(str_contains($nav, 'trade-theme-bootstrap'), 'pre-paint theme bootstrap missing');
d24(str_contains($nav, 'data-theme-ready'), 'theme reveal guard missing');
d24(str_contains($nav, 'cockpit-ui.css?v=8') && str_contains($nav, 'cockpit.js?v=8'), 'dashboard truth assets must be cache-busted');
d24(str_contains($ui, 'Trade Dark Contrast v7'), 'dark contrast layer missing');
d24(str_contains($ui, '.cc-radar .excellent') && str_contains($ui, '.cc-danger'), 'command-center dark surfaces are not covered');

d24(str_contains($index, 'function faDigits') && str_contains($index, "'.'=> '٫'") === false, 'server-side Persian digit formatter missing');
d24(str_contains($index, "'.'=>'٫'") && str_contains($index, "','=>'٬'"), 'Persian decimal/thousands separators are not rendered on first paint');
d24(str_contains($index, 'NobitexPortfolioSnapshotCache') && str_contains($index, 'ارزش کیف پول نوبیتکس'), 'first paint does not use the same wallet truth as live dashboard');
d24(str_contains($index, 'IranClock::todayUtcRange()'), 'initial order count is not aligned to Iran day boundary');
d24(str_contains($index, 'data-no-date-localize') && str_contains($index, 'iranUtc('), 'server-rendered table dates are not localized before first paint');
d24(!str_contains($js, 'prepareLiveDashboard();'), 'client must not replace initial dashboard truth with loading placeholders');
d24(str_contains($js, 'available_cash_by_quote?.IRT') && str_contains($js, 'cashRows.reduce'), 'live dashboard does not prefer exact available cash or sum all Rial wallets');
d24(str_contains($js, 'ارزش کیف پول نوبیتکس') && str_contains($js, 'wallet_total_toman'), 'wallet truth is not visible on dashboard');
d24(str_contains($js, 'ظرفیت تکمیل؛ خرید جدید تا آزاد شدن اسلات متوقف است'), 'portfolio_full explanation missing from dashboard');

d24(str_contains($valuation, "'model'=>'nobitex_full_spot_wallet_valuation_v4'"), 'wallet valuation v4 marker missing');
d24(str_contains($valuation, "'nobitex_wallet_rialBalance'"), 'Nobitex official rialBalance is not the primary valuation source');
d24(str_contains($valuation, "'available_cash_by_quote'"), 'exact active cash field is missing from portfolio truth');
d24(str_contains($live, 'NobitexPortfolioSnapshotCache') && str_contains($live, "'portfolio_truth'=>"), 'live dashboard does not expose Nobitex wallet truth');
d24(str_contains($bale, "'info' => 600") && str_contains($bale, '$dedupeSeconds'), 'routine Bale info dedupe is not enforced');

echo "Trade dashboard first-paint truth/theme regression checks passed.\n";
