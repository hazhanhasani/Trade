<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;

final class RiskManager
{
    public function assertTradingAllowed(array $order): void
    {
        if (!(bool) Config::get('trading.enabled', false)) {
            throw new \RuntimeException('Trading is disabled by server configuration.');
        }

        $pdo = Database::connection();
        $kill = $pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn();
        if ((string) $kill === '1') {
            throw new \RuntimeException('Kill switch is active.');
        }

        $market = strtoupper((string) ($order['market'] ?? ''));
        $type = strtolower((string) ($order['type'] ?? ''));
        $mode = strtolower((string) ($order['mode'] ?? ''));
        $amount1 = (float) ($order['amount1'] ?? 0);
        $price = (float) ($order['price'] ?? 0);

        if ($market === '' || !in_array($type, ['buy', 'sell'], true)) {
            throw new \InvalidArgumentException('market and valid type are required.');
        }
        if (!in_array($mode, ['limit', 'market', 'stop_limit', 'oco'], true)) {
            throw new \InvalidArgumentException('Unsupported order mode.');
        }
        if ($amount1 <= 0) {
            throw new \InvalidArgumentException('Order amount must be positive.');
        }

        $allowlist = Config::get('trading.allowed_markets', []);
        if (is_array($allowlist) && $allowlist !== [] && !in_array($market, array_map('strtoupper', $allowlist), true)) {
            throw new \RuntimeException('Market is not in the server allowlist.');
        }

        $estimatedValue = $price > 0 ? $amount1 * $price : 0.0;
        $maxOrderValue = (float) Config::get('trading.max_order_value', 0);
        if ($maxOrderValue > 0 && $estimatedValue > $maxOrderValue) {
            throw new \RuntimeException('Order exceeds max_order_value.');
        }

        $maxOrdersPerHour = max(1, (int) Config::get('trading.max_orders_per_hour', 10));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() >= $maxOrdersPerHour) {
            throw new \RuntimeException('Hourly order limit reached.');
        }

        $maxDailyLoss = (float) Config::get('trading.max_daily_realized_loss', 0);
        if ($maxDailyLoss > 0) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(realized_pnl),0) FROM trades WHERE created_at >= UTC_DATE()");
            $stmt->execute();
            $pnl = (float) $stmt->fetchColumn();
            if ($pnl <= -abs($maxDailyLoss)) {
                throw new \RuntimeException('Daily realized-loss limit reached.');
            }
        }
    }
}
