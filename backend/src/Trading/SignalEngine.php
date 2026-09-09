<?php

declare(strict_types=1);

namespace Trade\Trading;

final class SignalEngine
{
    public function analyze(array $market, int $threshold = 60): array
    {
        $prices = array_values(array_filter(
            array_map(static fn(mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $market['prices'] ?? []),
            static fn(float $v): bool => $v > 0
        ));
        $threshold = max(35, min(90, $threshold));
        if (count($prices) < 15) {
            return [
                'ready' => false,
                'score' => 0,
                'action' => 'hold',
                'reason' => 'insufficient_market_history',
                'indicators' => ['samples' => count($prices)],
            ];
        }

        $score = 0.0;
        $reasons = [];
        $rsi = $this->rsi($prices, 14);
        if ($rsi <= 28) {
            $score += 30;
            $reasons[] = 'rsi_oversold';
        } elseif ($rsi <= 35) {
            $score += 18;
            $reasons[] = 'rsi_low';
        } elseif ($rsi >= 72) {
            $score -= 30;
            $reasons[] = 'rsi_overbought';
        } elseif ($rsi >= 65) {
            $score -= 18;
            $reasons[] = 'rsi_high';
        }

        $emaFast = $this->ema($prices, min(12, count($prices)));
        $emaSlow = $this->ema($prices, min(26, count($prices)));
        $emaGap = $emaSlow > 0 ? (($emaFast - $emaSlow) / $emaSlow) * 100 : 0.0;
        if ($emaGap >= 0.35) {
            $score += 30;
            $reasons[] = 'ema_bullish';
        } elseif ($emaGap <= -0.35) {
            $score -= 30;
            $reasons[] = 'ema_bearish';
        } elseif ($emaGap >= 0.12) {
            $score += 15;
            $reasons[] = 'ema_mild_bullish';
        } elseif ($emaGap <= -0.12) {
            $score -= 15;
            $reasons[] = 'ema_mild_bearish';
        }

        $momentum = $this->momentum($prices, 5);
        if ($momentum >= 0.60) {
            $score += 20;
            $reasons[] = 'momentum_up';
        } elseif ($momentum <= -0.60) {
            $score -= 20;
            $reasons[] = 'momentum_down';
        } elseif ($momentum >= 0.25) {
            $score += 10;
        } elseif ($momentum <= -0.25) {
            $score -= 10;
        }

        $imbalance = max(-1.0, min(1.0, (float) ($market['orderbook_imbalance'] ?? 0)));
        if ($imbalance >= 0.12) {
            $score += 10;
            $reasons[] = 'bid_imbalance';
        } elseif ($imbalance <= -0.12) {
            $score -= 10;
            $reasons[] = 'ask_imbalance';
        }

        $change = (float) ($market['change_percent'] ?? 0);
        if ($change >= 1.0) {
            $score += 10;
            $reasons[] = 'daily_trend_up';
        } elseif ($change <= -1.0) {
            $score -= 10;
            $reasons[] = 'daily_trend_down';
        }

        $score = (int) round(max(-100, min(100, $score)));
        $action = $score >= $threshold ? 'buy' : ($score <= -$threshold ? 'sell' : 'hold');

        return [
            'ready' => true,
            'score' => $score,
            'action' => $action,
            'reason' => $action === 'hold' ? 'score_below_threshold' : 'signal_threshold_reached',
            'reasons' => $reasons,
            'indicators' => [
                'samples' => count($prices),
                'rsi14' => round($rsi, 4),
                'ema_fast' => $emaFast,
                'ema_slow' => $emaSlow,
                'ema_gap_percent' => round($emaGap, 4),
                'momentum_5_percent' => round($momentum, 4),
                'orderbook_imbalance' => round($imbalance, 4),
                'change_percent' => $change,
            ],
        ];
    }

    private function rsi(array $prices, int $period): float
    {
        $slice = array_slice($prices, -($period + 1));
        if (count($slice) < 2) {
            return 50.0;
        }
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1, $n = count($slice); $i < $n; $i++) {
            $delta = $slice[$i] - $slice[$i - 1];
            if ($delta >= 0) {
                $gains += $delta;
            } else {
                $losses += abs($delta);
            }
        }
        $steps = count($slice) - 1;
        $avgGain = $gains / $steps;
        $avgLoss = $losses / $steps;
        if ($avgLoss <= 0.0) {
            return $avgGain > 0 ? 100.0 : 50.0;
        }
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function ema(array $prices, int $period): float
    {
        $period = max(2, min($period, count($prices)));
        $k = 2.0 / ($period + 1.0);
        $seed = array_slice($prices, 0, $period);
        $ema = array_sum($seed) / count($seed);
        for ($i = $period, $n = count($prices); $i < $n; $i++) {
            $ema = ($prices[$i] * $k) + ($ema * (1.0 - $k));
        }
        return $ema;
    }

    private function momentum(array $prices, int $lookback): float
    {
        $last = (float) end($prices);
        $index = max(0, count($prices) - 1 - $lookback);
        $past = (float) $prices[$index];
        if ($past <= 0) {
            return 0.0;
        }
        return (($last - $past) / $past) * 100.0;
    }
}
