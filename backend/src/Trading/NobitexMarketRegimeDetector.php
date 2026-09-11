<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Classifies the current market structure before a strategy is allowed to act.
 * It intentionally uses only data already available to the full-universe scan:
 * 1m closes, 1m/5m/15m indicators and the live order book snapshot.
 */
final class NobitexMarketRegimeDetector
{
    public const TRENDING_UP = 'trending_up';
    public const TRENDING_DOWN = 'trending_down';
    public const RANGING = 'ranging';
    public const BREAKOUT_UP = 'breakout_up';
    public const BREAKOUT_DOWN = 'breakout_down';
    public const HIGH_VOLATILITY = 'high_volatility';
    public const UNCERTAIN = 'uncertain';

    public function detect(array $market, array $minute, array $i1, array $i5, array $i15): array
    {
        $prices = $this->clean($minute, 180);
        if (count($prices) < 60) {
            return $this->result(self::UNCERTAIN, 0, [
                'reason'=>'insufficient_regime_history',
                'samples'=>count($prices),
            ]);
        }

        $price = (float) end($prices);
        $prior = array_slice($prices, -61, 60);
        $priorHigh = max($prior);
        $priorLow = min($prior);
        $rangeMid = max(0.00000001, ($priorHigh + $priorLow) / 2.0);
        $rangePercent = (($priorHigh - $priorLow) / $rangeMid) * 100.0;
        $positionInRange = $priorHigh > $priorLow
            ? $this->clamp(($price - $priorLow) / ($priorHigh - $priorLow), -1.0, 2.0)
            : 0.5;

        $breakoutUp = $price > $priorHigh ? (($price - $priorHigh) / max($priorHigh, 0.00000001)) * 100.0 : 0.0;
        $breakoutDown = $price < $priorLow ? (($priorLow - $price) / max($priorLow, 0.00000001)) * 100.0 : 0.0;

        $m1 = (float)($i1['momentum_5_percent'] ?? 0.0);
        $m5 = (float)($i5['momentum_5_percent'] ?? 0.0);
        $m15 = (float)($i15['momentum_5_percent'] ?? 0.0);
        $e1 = (float)($i1['ema_gap_percent'] ?? 0.0);
        $e5 = (float)($i5['ema_gap_percent'] ?? 0.0);
        $e15 = (float)($i15['ema_gap_percent'] ?? 0.0);
        $t1 = $this->clamp((float)($i1['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t5 = $this->clamp((float)($i5['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t15 = $this->clamp((float)($i15['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $vol1 = max(0.0, (float)($i1['volatility_percent'] ?? 0.0));
        $vol5 = max(0.0, (float)($i5['volatility_percent'] ?? 0.0));
        $volatility = ($vol1 * 0.70) + ($vol5 * 0.30);
        $imbalance = $this->clamp((float)($market['orderbook_imbalance'] ?? 0.0), -1.0, 1.0);

        $positiveVotes = 0;
        $negativeVotes = 0;
        foreach ([$m1,$m5,$m15,$e1,$e5,$e15] as $value) {
            if ($value > 0.03) $positiveVotes++;
            elseif ($value < -0.03) $negativeVotes++;
        }
        $upAlignment = $positiveVotes / 6.0;
        $downAlignment = $negativeVotes / 6.0;
        $trendConsistency = ($t1 * 0.20) + ($t5 * 0.45) + ($t15 * 0.35);
        $efficiency = $this->efficiencyRatio($prices, 36);
        $directionalMove = $this->momentum($prices, 30);
        $breakoutThreshold = $this->clamp(max(0.08, $volatility * 0.75), 0.08, 0.80);

        $metrics = [
            'samples'=>count($prices),
            'price'=>$price,
            'prior_range_high'=>$priorHigh,
            'prior_range_low'=>$priorLow,
            'range_percent'=>round($rangePercent, 4),
            'position_in_range'=>round($positionInRange, 4),
            'breakout_up_percent'=>round($breakoutUp, 4),
            'breakout_down_percent'=>round($breakoutDown, 4),
            'breakout_threshold_percent'=>round($breakoutThreshold, 4),
            'efficiency_ratio'=>round($efficiency, 4),
            'directional_move_percent'=>round($directionalMove, 4),
            'up_alignment'=>round($upAlignment, 4),
            'down_alignment'=>round($downAlignment, 4),
            'trend_consistency'=>round($trendConsistency, 4),
            'volatility_percent'=>round($volatility, 4),
            'orderbook_imbalance'=>round($imbalance, 4),
        ];

        // A decisive range escape gets first priority, before generic trend labels.
        if ($breakoutUp >= $breakoutThreshold && $upAlignment >= 0.66 && $imbalance > -0.35) {
            $confidence = $this->confidence(58.0 + min(20.0, $breakoutUp * 18.0) + ($upAlignment * 14.0) + max(0.0, $imbalance) * 8.0);
            return $this->result(self::BREAKOUT_UP, $confidence, $metrics + ['reason'=>'upside_range_expansion']);
        }
        if ($breakoutDown >= $breakoutThreshold && $downAlignment >= 0.66 && $imbalance < 0.35) {
            $confidence = $this->confidence(58.0 + min(20.0, $breakoutDown * 18.0) + ($downAlignment * 14.0) + max(0.0, -$imbalance) * 8.0);
            return $this->result(self::BREAKOUT_DOWN, $confidence, $metrics + ['reason'=>'downside_range_expansion']);
        }

        // Extreme volatility gets its own guarded strategy instead of becoming
        // a permanent no-entry dead zone. Directional and economic gates are
        // still enforced downstream before any BUY can be submitted.
        if ($volatility >= 1.80) {
            $confidence = $this->confidence(65.0 + min(30.0, ($volatility - 1.80) * 12.0));
            return $this->result(self::HIGH_VOLATILITY, $confidence, $metrics + ['reason'=>'short_term_volatility_extreme']);
        }

        $trendStrength = ($efficiency * 0.42)
            + (max($upAlignment, $downAlignment) * 0.33)
            + (abs($e15) > 0.08 ? 0.15 : 0.0)
            + (abs($directionalMove) > 0.25 ? 0.10 : 0.0);

        if ($upAlignment >= 0.66 && $efficiency >= 0.30 && $e15 > 0.02 && $directionalMove > 0.0) {
            $confidence = $this->confidence(48.0 + ($trendStrength * 42.0) + max(0.0, $imbalance) * 6.0);
            return $this->result(self::TRENDING_UP, $confidence, $metrics + ['reason'=>'multi_timeframe_uptrend']);
        }
        if ($downAlignment >= 0.66 && $efficiency >= 0.30 && $e15 < -0.02 && $directionalMove < 0.0) {
            $confidence = $this->confidence(48.0 + ($trendStrength * 42.0) + max(0.0, -$imbalance) * 6.0);
            return $this->result(self::TRENDING_DOWN, $confidence, $metrics + ['reason'=>'multi_timeframe_downtrend']);
        }

        $flatMacro = abs($e15) <= 0.60 && abs($directionalMove) <= max(1.60, $rangePercent * 0.85);
        $insideRange = $positionInRange >= -0.05 && $positionInRange <= 1.05;
        if ($efficiency <= 0.34 && $flatMacro && $insideRange) {
            $rangeConfidence = 58.0
                + ((0.34 - $efficiency) / 0.34) * 20.0
                + min(12.0, max(0.0, $rangePercent) * 2.0);
            return $this->result(self::RANGING, $this->confidence($rangeConfidence), $metrics + ['reason'=>'low_efficiency_mean_reverting_range']);
        }

        return $this->result(self::UNCERTAIN, $this->confidence(45.0 + max($upAlignment, $downAlignment) * 20.0), $metrics + ['reason'=>'mixed_market_structure']);
    }

    private function result(string $regime, int $confidence, array $metrics): array
    {
        return [
            'regime'=>$regime,
            'confidence'=>$confidence,
            'entry_enabled'=>in_array($regime, [self::TRENDING_UP,self::RANGING,self::BREAKOUT_UP,self::HIGH_VOLATILITY], true),
            'metrics'=>$metrics,
        ];
    }

    private function efficiencyRatio(array $prices, int $lookback): float
    {
        $slice = array_slice($prices, -($lookback + 1));
        if (count($slice) < 3) return 0.0;
        $net = abs((float)end($slice) - (float)$slice[0]);
        $path = 0.0;
        for ($i=1,$n=count($slice);$i<$n;$i++) $path += abs((float)$slice[$i] - (float)$slice[$i-1]);
        return $path > 0.0 ? $this->clamp($net / $path, 0.0, 1.0) : 0.0;
    }

    private function momentum(array $prices, int $lookback): float
    {
        $last = (float)end($prices);
        $index = max(0, count($prices) - 1 - $lookback);
        $past = (float)$prices[$index];
        return $past > 0.0 ? (($last - $past) / $past) * 100.0 : 0.0;
    }

    private function clean(array $prices, int $limit): array
    {
        $out=[];
        foreach ($prices as $price) {
            if (!is_numeric($price)) continue;
            $n=(float)$price;
            if (is_finite($n) && $n>0.0) $out[]=$n;
        }
        return array_slice($out,-$limit);
    }

    private function confidence(float $value): int
    {
        return (int)round($this->clamp($value, 0.0, 100.0));
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
