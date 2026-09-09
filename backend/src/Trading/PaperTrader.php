<?php

declare(strict_types=1);

namespace Trade\Trading;

final class PaperTrader
{
    public function simulate(string $side, float $price, float $amount): array
    {
        return [
            'mode' => 'paper',
            'side' => $side,
            'asset' => 'GRAM',
            'price' => $price,
            'amount' => $amount,
            'status' => 'simulated',
            'created_at' => gmdate('c'),
        ];
    }
}
