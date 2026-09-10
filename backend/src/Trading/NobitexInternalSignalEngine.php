<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Exchange-native multi-timeframe signal engine for Nobitex.
 *
 * One 1-minute OHLC series is reused to derive 5m and 15m closes locally. This
 * keeps decisions independent from TradingView while improving entry/exit
 * timing without multiplying Nobitex history requests.
 */
final class NobitexInternalSignalEngine
{
    public function __construct(private readonly SignalEngine $base = new SignalEngine()) {}

    public function analyze(array $market, array $minutePrices, int $threshold = 60): array
    {
        $threshold = max(40, min(90, $threshold));
        $minute = $this->clean($minutePrices, 480);
        $five = $this->aggregate($minute, 5);
        $fifteen = $this->aggregate($minute, 15);

        if (count($minute) < 60 || count($five) < 30 || count($fifteen) < 26) {
            return [
                'ready'=>false,
                'score'=>0,
                'confidence'=>0,
                'action'=>'hold',
                'reason'=>'insufficient_internal_mtf_history',
                'reasons'=>[],
                'source'=>'nobitex_internal_mtf',
                'timeframes'=>[
                    '1m'=>['samples'=>count($minute)],
                    '5m'=>['samples'=>count($five)],
                    '15m'=>['samples'=>count($fifteen)],
                ],
                'indicators'=>['samples'=>count($minute)],
                'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
            ];
        }

        $oneSignal = $this->base->analyze($market + ['prices'=>$minute], max(40, $threshold - 8));
        $fiveSignal = $this->base->analyze($market + ['prices'=>$five], max(40, $threshold - 10));
        $fifteenSignal = $this->base->analyze($market + ['prices'=>$fifteen], max(40, $threshold - 15));

        $s1 = (int) ($oneSignal['score'] ?? 0);
        $s5 = (int) ($fiveSignal['score'] ?? 0);
        $s15 = (int) ($fifteenSignal['score'] ?? 0);
        $weighted = (int) round(($s1 * 0.50) + ($s5 * 0.30) + ($s15 * 0.20));
        $weighted = max(-100, min(100, $weighted));
        $ready = (bool) ($oneSignal['ready'] ?? false)
            && (bool) ($fiveSignal['ready'] ?? false)
            && (bool) ($fifteenSignal['ready'] ?? false);

        $oneAction = (string) ($oneSignal['action'] ?? 'hold');
        $buyGate = $ready
            && $weighted >= max(40, $threshold - 4)
            && $oneAction === 'buy'
            && $s5 >= 15
            && $s15 >= -10;

        // Strategy exits deliberately require stronger confirmation than entry.
        // Hard stop-loss/take-profit are still enforced separately by RiskManager.
        $sellThreshold = max(50, $threshold - 3);
        $sellGate = $ready
            && $weighted <= -$sellThreshold
            && $oneAction === 'sell'
            && $s5 <= -20
            && $s15 <= 5;

        $action = 'hold';
        $reason = 'internal_mtf_hold';
        if ($buyGate) {
            $action = 'buy';
            $reason = 'internal_mtf_buy_confirmed';
        } elseif ($sellGate) {
            $action = 'sell';
            $reason = 'internal_mtf_sell_confirmed';
        } elseif ($weighted >= max(40, $threshold - 4)) {
            $reason = 'internal_mtf_buy_not_confirmed';
        } elseif ($weighted <= -$sellThreshold) {
            $reason = 'internal_mtf_sell_not_confirmed';
        }

        $agreement = $this->agreement($s1, $s5, $s15);
        $confidence = $ready
            ? min(100, max(0, (int) round((abs($weighted) * 0.75) + ($agreement * 25.0))))
            : 0;

        $indicators = is_array($oneSignal['indicators'] ?? null) ? $oneSignal['indicators'] : [];
        $indicators['mtf_1m_score'] = $s1;
        $indicators['mtf_5m_score'] = $s5;
        $indicators['mtf_15m_score'] = $s15;
        $indicators['mtf_weighted_score'] = $weighted;
        $indicators['mtf_agreement'] = round($agreement, 4);

        $reasons = [];
        foreach ([$oneSignal, $fiveSignal, $fifteenSignal] as $signal) {
            foreach ((array) ($signal['reasons'] ?? []) as $r) {
                if (is_string($r) && $r !== '') $reasons[] = $r;
            }
        }

        return [
            'ready'=>$ready,
            'score'=>$weighted,
            'confidence'=>$confidence,
            'action'=>$action,
            'reason'=>$reason,
            'reasons'=>array_values(array_unique($reasons)),
            'source'=>'nobitex_internal_mtf',
            'timeframes'=>[
                '1m'=>['score'=>$s1,'action'=>$oneAction,'confidence'=>(int)($oneSignal['confidence']??0),'samples'=>count($minute)],
                '5m'=>['score'=>$s5,'action'=>(string)($fiveSignal['action']??'hold'),'confidence'=>(int)($fiveSignal['confidence']??0),'samples'=>count($five)],
                '15m'=>['score'=>$s15,'action'=>(string)($fifteenSignal['action']??'hold'),'confidence'=>(int)($fifteenSignal['confidence']??0),'samples'=>count($fifteen)],
            ],
            'indicators'=>$indicators,
            'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
        ];
    }

    private function clean(array $prices, int $limit): array
    {
        $out = [];
        foreach ($prices as $price) {
            if (!is_numeric($price)) continue;
            $n = (float) $price;
            if (is_finite($n) && $n > 0) $out[] = $n;
        }
        return array_slice($out, -$limit);
    }

    private function aggregate(array $minute, int $minutes): array
    {
        if ($minute === [] || $minutes <= 1) return $minute;
        $usable = intdiv(count($minute), $minutes) * $minutes;
        if ($usable < $minutes) return [];
        $aligned = array_slice($minute, -$usable);
        $out = [];
        for ($i = $minutes - 1, $n = count($aligned); $i < $n; $i += $minutes) {
            $out[] = (float) $aligned[$i];
        }
        return $out;
    }

    private function agreement(int $s1, int $s5, int $s15): float
    {
        $signs = [($s1 > 0) <=> ($s1 < 0), ($s5 > 0) <=> ($s5 < 0), ($s15 > 0) <=> ($s15 < 0)];
        $positive = count(array_filter($signs, static fn(int $v): bool => $v > 0));
        $negative = count(array_filter($signs, static fn(int $v): bool => $v < 0));
        return max($positive, $negative) / 3.0;
    }
}
