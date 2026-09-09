<?php

declare(strict_types=1);

namespace Trade\Trading;

final class MarketScanner
{
    public function analyze(array $ticker): array
    {
        $price = (float)($ticker['price'] ?? 0);
        $volume = (float)($ticker['volume'] ?? 0);
        $change = (float)($ticker['change_percent'] ?? 0);

        return [
            'asset' => 'GRAM',
            'price' => $price,
            'volume' => $volume,
            'change_percent' => $change,
            'trend' => $this->trend($change),
            'timestamp' => gmdate('c'),
        ];
    }

    private function trend(float $change): string
    {
        if ($change > 2) return 'bullish';
        if ($change < -2) return 'bearish';
        return 'neutral';
    }
}
