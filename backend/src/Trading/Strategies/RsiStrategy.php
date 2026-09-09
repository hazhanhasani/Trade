<?php

declare(strict_types=1);

namespace Trade\Trading\Strategies;

use Trade\Trading\StrategyInterface;

final class RsiStrategy implements StrategyInterface
{
    public function name(): string
    {
        return 'RSI_GRAM_STRATEGY';
    }

    public function analyze(array $market): array
    {
        $rsi = (float)($market['rsi'] ?? 50);

        if ($rsi <= 30) {
            return ['signal' => 'buy', 'confidence' => 0.7, 'reason' => 'RSI oversold'];
        }

        if ($rsi >= 70) {
            return ['signal' => 'sell', 'confidence' => 0.7, 'reason' => 'RSI overbought'];
        }

        return ['signal' => 'hold', 'confidence' => 0.5, 'reason' => 'No strong signal'];
    }
}
