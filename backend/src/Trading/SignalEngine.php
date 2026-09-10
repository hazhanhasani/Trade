<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Deterministic multi-factor signal engine.
 *
 * The model deliberately requires agreement between trend, momentum and MACD
 * before emitting BUY. RSI alone is never allowed to trigger an entry because
 * oversold markets can keep falling. Liquidity/spread are also considered so
 * the execution engine receives fewer low-quality candidates.
 */
final class SignalEngine
{
    public function analyze(array $market, int $threshold = 60): array
    {
        $prices = array_values(array_filter(
            array_map(static fn(mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $market['prices'] ?? []),
            static fn(float $v): bool => $v > 0
        ));
        $threshold = max(35, min(90, $threshold));
        if (count($prices) < 26) {
            return [
                'ready' => false,
                'score' => 0,
                'confidence' => 0,
                'action' => 'hold',
                'reason' => 'insufficient_market_history',
                'reasons' => [],
                'indicators' => ['samples' => count($prices)],
            ];
        }

        $score = 0.0;
        $reasons = [];
        $rsi = $this->rsi($prices, 14);
        $emaFast = $this->ema($prices, 9);
        $emaSlow = $this->ema($prices, 21);
        $emaGap = $emaSlow > 0 ? (($emaFast - $emaSlow) / $emaSlow) * 100 : 0.0;
        $momentum = $this->momentum($prices, 5);
        $macd = $this->macd($prices);
        $trendConsistency = $this->trendConsistency($prices, 8);
        $volatility = $this->volatility($prices, 24);
        $spread = max(0.0, (float) ($market['spread_percent'] ?? 0));
        $imbalance = max(-1.0, min(1.0, (float) ($market['orderbook_imbalance'] ?? 0)));
        $change = (float) ($market['change_percent'] ?? 0);

        // RSI is supporting evidence only, never a standalone entry trigger.
        if ($rsi >= 38 && $rsi <= 58) {
            $score += 8;
            $reasons[] = 'rsi_healthy';
        } elseif ($rsi < 30) {
            $score += 6;
            $reasons[] = 'rsi_oversold_watch';
        } elseif ($rsi >= 72) {
            $score -= 24;
            $reasons[] = 'rsi_overbought';
        } elseif ($rsi >= 65) {
            $score -= 10;
            $reasons[] = 'rsi_high';
        }

        if ($emaGap >= 0.35) {
            $score += 28;
            $reasons[] = 'ema_trend_strong';
        } elseif ($emaGap >= 0.12) {
            $score += 16;
            $reasons[] = 'ema_trend_positive';
        } elseif ($emaGap <= -0.35) {
            $score -= 30;
            $reasons[] = 'ema_trend_negative';
        } elseif ($emaGap <= -0.12) {
            $score -= 16;
            $reasons[] = 'ema_trend_weak';
        }

        if ($macd['histogram'] > 0 && $macd['macd'] > $macd['signal']) {
            $score += $macd['histogram_percent'] >= 0.08 ? 22 : 12;
            $reasons[] = 'macd_bullish';
        } elseif ($macd['histogram'] < 0 && $macd['macd'] < $macd['signal']) {
            $score -= abs($macd['histogram_percent']) >= 0.08 ? 22 : 12;
            $reasons[] = 'macd_bearish';
        }

        if ($momentum >= 0.60) {
            $score += 18;
            $reasons[] = 'momentum_up';
        } elseif ($momentum >= 0.20) {
            $score += 9;
        } elseif ($momentum <= -0.60) {
            $score -= 20;
            $reasons[] = 'momentum_down';
        } elseif ($momentum <= -0.20) {
            $score -= 9;
        }

        if ($trendConsistency >= 0.68) {
            $score += 12;
            $reasons[] = 'trend_consistent';
        } elseif ($trendConsistency <= 0.32) {
            $score -= 12;
            $reasons[] = 'trend_inconsistent';
        }

        if ($imbalance >= 0.15) {
            $score += 10;
            $reasons[] = 'bid_imbalance';
        } elseif ($imbalance <= -0.15) {
            $score -= 10;
            $reasons[] = 'ask_imbalance';
        }

        if ($change >= 0.5 && $change <= 8.0) {
            $score += 7;
            $reasons[] = 'daily_trend_up';
        } elseif ($change < -1.0) {
            $score -= 8;
            $reasons[] = 'daily_trend_down';
        } elseif ($change > 12.0) {
            $score -= 8;
            $reasons[] = 'extended_move_penalty';
        }

        if ($spread > 1.0) {
            $score -= 18;
            $reasons[] = 'wide_spread';
        } elseif ($spread > 0.5) {
            $score -= 7;
            $reasons[] = 'spread_penalty';
        }

        if ($volatility > 3.5) {
            $score -= 15;
            $reasons[] = 'high_short_term_volatility';
        } elseif ($volatility > 2.0) {
            $score -= 7;
            $reasons[] = 'elevated_volatility';
        }

        $score = (int) round(max(-100, min(100, $score)));
        $qualityReady = $spread <= 1.5 && $volatility <= 6.0;
        $buyConfirmed = $emaGap > -0.05
            && $macd['histogram'] >= 0
            && $momentum > -0.20
            && $trendConsistency >= 0.45;
        $sellConfirmed = $emaGap < 0.05 || $macd['histogram'] < 0 || $momentum < -0.20;

        $action = 'hold';
        $reason = 'score_below_threshold';
        if (!$qualityReady) {
            $reason = $spread > 1.5 ? 'spread_too_wide' : 'volatility_too_high';
        } elseif ($score >= $threshold && $buyConfirmed) {
            $action = 'buy';
            $reason = 'multi_factor_buy_confirmed';
        } elseif ($score >= $threshold) {
            $reason = 'buy_score_without_confirmation';
        } elseif ($score <= -$threshold && $sellConfirmed) {
            $action = 'sell';
            $reason = 'multi_factor_sell_confirmed';
        }

        $confidence = $qualityReady ? min(100, max(0, (int) round(abs($score)))) : 0;

        return [
            'ready' => $qualityReady,
            'score' => $score,
            'confidence' => $confidence,
            'action' => $action,
            'reason' => $reason,
            'reasons' => array_values(array_unique($reasons)),
            'indicators' => [
                'samples' => count($prices),
                'rsi14' => round($rsi, 4),
                'ema9' => $emaFast,
                'ema21' => $emaSlow,
                'ema_gap_percent' => round($emaGap, 4),
                'macd' => round($macd['macd'], 10),
                'macd_signal' => round($macd['signal'], 10),
                'macd_histogram' => round($macd['histogram'], 10),
                'macd_histogram_percent' => round($macd['histogram_percent'], 4),
                'momentum_5_percent' => round($momentum, 4),
                'trend_consistency' => round($trendConsistency, 4),
                'volatility_percent' => round($volatility, 4),
                'orderbook_imbalance' => round($imbalance, 4),
                'spread_percent' => round($spread, 6),
                'change_percent' => $change,
            ],
        ];
    }

    private function rsi(array $prices, int $period): float
    {
        $prices = array_slice($prices, -max($period * 4, $period + 1));
        if (count($prices) <= $period) return 50.0;

        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $delta = $prices[$i] - $prices[$i - 1];
            if ($delta >= 0) $gain += $delta; else $loss += abs($delta);
        }
        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        for ($i = $period + 1, $n = count($prices); $i < $n; $i++) {
            $delta = $prices[$i] - $prices[$i - 1];
            $g = max(0.0, $delta);
            $l = max(0.0, -$delta);
            $avgGain = (($avgGain * ($period - 1)) + $g) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $l) / $period;
        }
        if ($avgLoss <= 0.0) return $avgGain > 0 ? 100.0 : 50.0;
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

    private function emaSeries(array $values, int $period): array
    {
        if ($values === []) return [];
        $period = max(2, min($period, count($values)));
        $k = 2.0 / ($period + 1.0);
        $ema = (float) $values[0];
        $out = [$ema];
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            $ema = ((float) $values[$i] * $k) + ($ema * (1.0 - $k));
            $out[] = $ema;
        }
        return $out;
    }

    private function macd(array $prices): array
    {
        $fastSeries = $this->emaSeries($prices, 12);
        $slowSeries = $this->emaSeries($prices, 26);
        $macdSeries = [];
        foreach ($prices as $i => $_) {
            $macdSeries[] = ($fastSeries[$i] ?? 0.0) - ($slowSeries[$i] ?? 0.0);
        }
        $signalSeries = $this->emaSeries($macdSeries, 9);
        $macd = (float) end($macdSeries);
        $signal = (float) end($signalSeries);
        $histogram = $macd - $signal;
        $price = max(0.00000001, (float) end($prices));
        return [
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $histogram,
            'histogram_percent' => ($histogram / $price) * 100.0,
        ];
    }

    private function momentum(array $prices, int $lookback): float
    {
        $last = (float) end($prices);
        $index = max(0, count($prices) - 1 - $lookback);
        $past = (float) $prices[$index];
        return $past > 0 ? (($last - $past) / $past) * 100.0 : 0.0;
    }

    private function trendConsistency(array $prices, int $lookback): float
    {
        $slice = array_slice($prices, -($lookback + 1));
        if (count($slice) < 2) return 0.5;
        $up = 0;
        $steps = count($slice) - 1;
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i] >= $slice[$i - 1]) $up++;
        }
        return $steps > 0 ? $up / $steps : 0.5;
    }

    private function volatility(array $prices, int $lookback): float
    {
        $slice = array_slice($prices, -($lookback + 1));
        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            $prev = (float) $slice[$i - 1];
            if ($prev <= 0) continue;
            $returns[] = (((float) $slice[$i] - $prev) / $prev) * 100.0;
        }
        $n = count($returns);
        if ($n < 2) return 0.0;
        $mean = array_sum($returns) / $n;
        $sum = 0.0;
        foreach ($returns as $r) $sum += ($r - $mean) ** 2;
        return sqrt($sum / ($n - 1));
    }
}
