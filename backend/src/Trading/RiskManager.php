<?php

declare(strict_types=1);

namespace Trade\Trading;

final class RiskManager
{
    public function check(float $balance, float $orderValue, array $settings = []): array
    {
        $maxPercent = (float)($settings['max_position_percent'] ?? 10);
        $allowed = $balance > 0 && (($orderValue / $balance) * 100) <= $maxPercent;

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? 'ok' : 'position_limit_exceeded',
        ];
    }

    public function stopLoss(float $entry, float $percent = 5): float
    {
        return $entry * (1 - ($percent / 100));
    }

    public function takeProfit(float $entry, float $percent = 10): float
    {
        return $entry * (1 + ($percent / 100));
    }
}
