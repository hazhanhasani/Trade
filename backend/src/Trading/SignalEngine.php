<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Generic score-free profitability signal engine.
 *
 * `score` remains zero only because legacy signal tables still contain a NOT
 * NULL score column. It never participates in an entry/exit decision.
 */
final class SignalEngine
{
    private const BASE_ROUNDTRIP_EXECUTION_RESERVE_PERCENT = 0.55;
    private const MODEL_UNCERTAINTY_RESERVE_PERCENT = 0.15;
    private const MAX_EXECUTABLE_SPREAD_PERCENT = 1.50;

    public function analyze(array $market, int $legacyThreshold = 60): array
    {
        unset($legacyThreshold);
        $prices = array_values(array_filter(
            array_map(static fn(mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $market['prices'] ?? []),
            static fn(float $v): bool => is_finite($v) && $v > 0
        ));

        if (count($prices) < 26) {
            return [
                'ready'=>false,
                'score'=>0,
                'confidence'=>0,
                'confidence_is_gate'=>false,
                'action'=>'hold',
                'reason'=>'insufficient_market_history',
                'decision_model'=>'positive_expected_net_profit',
                'expected_net_profit'=>false,
                'expected_gross_move_percent'=>0.0,
                'estimated_roundtrip_cost_percent'=>0.0,
                'expected_net_edge_percent'=>0.0,
                'reasons'=>[],
                'indicators'=>['samples'=>count($prices)],
            ];
        }

        $rsi = $this->rsi($prices, 14);
        $emaFast = $this->ema($prices, 9);
        $emaSlow = $this->ema($prices, 21);
        $emaGap = $emaSlow > 0 ? (($emaFast - $emaSlow) / $emaSlow) * 100 : 0.0;
        $momentum = $this->momentum($prices, 5);
        $macd = $this->macd($prices);
        $trendConsistency = $this->trendConsistency($prices, 8);
        $volatility = $this->volatility($prices, 24);
        $spread = max(0.0, (float) ($market['spread_percent'] ?? 0.0));
        $imbalance = max(-1.0, min(1.0, (float) ($market['orderbook_imbalance'] ?? 0.0)));
        $change = (float) ($market['change_percent'] ?? 0.0);

        $rsiPenalty = 0.0;
        if ($rsi >= 78.0) $rsiPenalty = 0.55;
        elseif ($rsi >= 72.0) $rsiPenalty = 0.25;
        elseif ($rsi <= 22.0 && $momentum < 0.0) $rsiPenalty = 0.20;

        // Continuous economic estimate; no votes, points or score thresholds.
        $gross = ($this->clamp($momentum, -4.0, 4.0) * 0.55)
            + ($this->clamp($emaGap, -3.0, 3.0) * 0.45)
            + ($this->clamp((float) $macd['histogram_percent'], -1.0, 1.0) * 0.80)
            + (($trendConsistency - 0.5) * 0.80)
            + ($imbalance * 0.30)
            + ($this->clamp($change, -8.0, 8.0) * 0.05)
            - $rsiPenalty;

        $spreadCost = min(1.50, $spread * 1.25);
        $slippageNoiseReserve = min(0.60, max(0.0, $volatility) * 0.35);
        $estimatedCost = self::BASE_ROUNDTRIP_EXECUTION_RESERVE_PERCENT
            + $spreadCost
            + $slippageNoiseReserve
            + self::MODEL_UNCERTAINTY_RESERVE_PERCENT;
        $netEdge = $gross - $estimatedCost;

        $qualityReady = $spread <= self::MAX_EXECUTABLE_SPREAD_PERCENT && $volatility <= 6.0;
        $buy = $qualityReady && $netEdge > 0.0;
        $estimatedExitCost = ($estimatedCost - self::MODEL_UNCERTAINTY_RESERVE_PERCENT) * 0.50;
        $sell = $qualityReady && $gross < -max(0.05, $estimatedExitCost);

        $action = 'hold';
        $reason = 'expected_net_profit_not_positive';
        if (!$qualityReady) {
            $reason = $spread > self::MAX_EXECUTABLE_SPREAD_PERCENT ? 'spread_not_executable' : 'volatility_too_high';
        } elseif ($buy) {
            $action = 'buy';
            $reason = 'positive_expected_net_profit_after_costs';
        } elseif ($sell) {
            $action = 'sell';
            $reason = 'expected_forward_move_negative_after_exit_cost';
        }

        $edgeToCost = $estimatedCost > 0.0 ? $netEdge / $estimatedCost : 0.0;
        $confidence = $qualityReady ? min(100, max(0, (int) round(50.0 + ($edgeToCost * 35.0)))) : 0;

        return [
            'ready'=>$qualityReady,
            'score'=>0,
            'confidence'=>$confidence,
            'confidence_is_gate'=>false,
            'action'=>$action,
            'reason'=>$reason,
            'decision_model'=>'positive_expected_net_profit',
            'expected_net_profit'=>$qualityReady && $netEdge > 0.0,
            'expected_gross_move_percent'=>round($gross, 4),
            'estimated_roundtrip_cost_percent'=>round($estimatedCost, 4),
            'estimated_exit_cost_percent'=>round($estimatedExitCost, 4),
            'expected_net_edge_percent'=>round($netEdge, 4),
            'minimum_net_edge_percent'=>0.0,
            'cost_model'=>[
                'base_roundtrip_execution_reserve_percent'=>self::BASE_ROUNDTRIP_EXECUTION_RESERVE_PERCENT,
                'spread_cost_percent'=>round($spreadCost, 4),
                'slippage_noise_reserve_percent'=>round($slippageNoiseReserve, 4),
                'model_uncertainty_reserve_percent'=>self::MODEL_UNCERTAINTY_RESERVE_PERCENT,
            ],
            'reasons'=>[],
            'indicators'=>[
                'samples'=>count($prices),
                'rsi14'=>round($rsi, 4),
                'ema9'=>$emaFast,
                'ema21'=>$emaSlow,
                'ema_gap_percent'=>round($emaGap, 4),
                'macd'=>round($macd['macd'], 10),
                'macd_signal'=>round($macd['signal'], 10),
                'macd_histogram'=>round($macd['histogram'], 10),
                'macd_histogram_percent'=>round($macd['histogram_percent'], 4),
                'momentum_5_percent'=>round($momentum, 4),
                'trend_consistency'=>round($trendConsistency, 4),
                'volatility_percent'=>round($volatility, 4),
                'orderbook_imbalance'=>round($imbalance, 4),
                'spread_percent'=>round($spread, 6),
                'change_percent'=>$change,
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
            'macd'=>$macd,
            'signal'=>$signal,
            'histogram'=>$histogram,
            'histogram_percent'=>($histogram / $price) * 100.0,
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

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
