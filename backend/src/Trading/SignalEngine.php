<?php

declare(strict_types=1);

namespace Trade\Trading;

final class SignalEngine
{
    public function analyze(array $market, array $strategies): array
    {
        $score = 0;
        $signals = [];

        foreach ($strategies as $strategy) {
            $result = $strategy->analyze($market);
            $signals[] = $result;
            $score += (int)($result['score'] ?? 0);
        }

        $score = max(-100, min(100, $score));

        return [
            'score' => $score,
            'action' => $score >= 75 ? 'buy' : ($score <= -75 ? 'sell' : 'hold'),
            'signals' => $signals,
        ];
    }
}
