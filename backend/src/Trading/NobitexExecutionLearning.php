<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Execution Learning v1.
 *
 * Learns from real BUY fills versus the planner's arrival/reference price.
 * Learning is deliberately reduction-only: it can add an execution-cost margin
 * to future entries for the same strategy/regime/quote profile, but can never
 * make an entry easier than the underlying signal and risk gates.
 */
final class NobitexExecutionLearning
{
    public const MODEL = 'execution_learning_v1';
    private const MIN_SAMPLES = 6;
    private const MAX_PENALTY_PERCENT = 0.45;

    /** @var array<string,mixed>|null */
    private static ?array $runtimeSnapshot = null;

    public function snapshot(?PDO $pdo = null, int $limit = 240): array
    {
        $pdo ??= Database::connection();
        $limit = max(30, min(500, $limit));
        $rows = $pdo->query(
            "SELECT p.id AS position_id,p.symbol,p.quote_asset,p.entry_price,p.amount AS position_amount,p.opened_at,
                    o.local_id,o.order_mode,o.amount AS requested_amount,o.request_json,o.response_json,o.source,
                    s.details_json
             FROM nobitex_autotrade_positions p
             JOIN orders o ON o.local_id=p.entry_order_local_id AND o.exchange_name='nobitex' AND o.side='buy'
             LEFT JOIN nobitex_autotrade_signals s ON s.order_local_id=p.entry_order_local_id
             WHERE p.opened_at IS NOT NULL
               AND p.status IN ('open','pending_close','closed')
             ORDER BY p.opened_at DESC
             LIMIT {$limit}"
        )->fetchAll();

        $samples = [];
        foreach (array_reverse($rows) as $row) {
            $request = json_decode((string)($row['request_json'] ?? ''), true);
            if (!is_array($request)) $request = [];
            $response = json_decode((string)($row['response_json'] ?? ''), true);
            if (!is_array($response)) $response = [];
            $order = $this->firstOrder($response);
            $plan = is_array($request['_trade_execution_plan'] ?? null) ? $request['_trade_execution_plan'] : [];
            $context = is_array($request['_trade_entry_context'] ?? null) ? $request['_trade_entry_context'] : [];
            $signal = json_decode((string)($row['details_json'] ?? ''), true);
            if (!is_array($signal)) $signal = [];

            $strategy = trim((string)($context['strategy_key'] ?? NobitexStrategyLearning::strategyKey($signal)));
            $regime = trim((string)($context['market_regime'] ?? NobitexStrategyLearning::regimeKey($signal)));
            if ($strategy === '' || $strategy === 'none' || $regime === '' || $regime === 'unknown') continue;

            $reference = $this->number($plan['reference_price'] ?? $context['reference_price'] ?? 0.0);
            $fill = NobitexOrderFill::averagePrice($order, (float)($row['entry_price'] ?? 0.0));
            $requested = $this->number($row['requested_amount'] ?? 0.0);
            $matched = NobitexOrderFill::matchedAmount($order, (float)($row['position_amount'] ?? 0.0));
            if ($matched <= 0.0) $matched = max(0.0, (float)($row['position_amount'] ?? 0.0));
            if ($reference <= 0.0 || $fill <= 0.0 || $requested <= 0.0 || $matched <= 0.0) continue;

            $slippage = (($fill - $reference) / $reference) * 100.0;
            $slippage = max(-5.0, min(5.0, $slippage));
            $fillRatio = max(0.0, min(1.0, $matched / $requested));
            $reprice = str_contains((string)($row['source'] ?? ''), '_reprice');

            $samples[] = [
                'position_id'=>(int)$row['position_id'],
                'symbol'=>(string)$row['symbol'],
                'quote_asset'=>(string)$row['quote_asset'],
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'planned_mode'=>(string)($row['order_mode'] ?? $plan['mode'] ?? 'unknown'),
                'reference_price'=>$reference,
                'fill_price'=>$fill,
                'slippage_percent'=>$slippage,
                'fill_ratio'=>$fillRatio,
                'repriced'=>$reprice,
                'opened_at'=>(string)($row['opened_at'] ?? ''),
            ];
        }

        $groups = [];
        foreach ($samples as $sample) {
            $key = $sample['strategy_key'].'|'.$sample['regime'].'|'.$sample['quote_asset'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'strategy_key'=>$sample['strategy_key'],
                    'regime'=>$sample['regime'],
                    'quote_asset'=>$sample['quote_asset'],
                    'slippage'=>[],
                    'fill_ratios'=>[],
                    'reprices'=>0,
                    'last_sample_at'=>null,
                ];
            }
            $groups[$key]['slippage'][] = (float)$sample['slippage_percent'];
            $groups[$key]['fill_ratios'][] = (float)$sample['fill_ratio'];
            if ($sample['repriced']) $groups[$key]['reprices']++;
            $groups[$key]['last_sample_at'] = $sample['opened_at'];
        }

        $profiles = [];
        foreach ($groups as $group) {
            $stats = self::statistics($group['slippage'], $group['fill_ratios'], (int)$group['reprices']);
            $profiles[] = [
                'strategy_key'=>$group['strategy_key'],
                'regime'=>$group['regime'],
                'quote_asset'=>$group['quote_asset'],
                'samples'=>$stats['samples'],
                'average_slippage_percent'=>round($stats['average_slippage_percent'], 4),
                'median_slippage_percent'=>round($stats['median_slippage_percent'], 4),
                'p75_slippage_percent'=>round($stats['p75_slippage_percent'], 4),
                'average_fill_ratio'=>round($stats['average_fill_ratio'], 4),
                'partial_fill_rate'=>round($stats['partial_fill_rate'], 4),
                'reprice_rate'=>round($stats['reprice_rate'], 4),
                'execution_penalty_percent'=>round(self::penaltyFromStatistics($stats), 4),
                'learning_ready'=>$stats['samples'] >= self::MIN_SAMPLES,
                'last_sample_at'=>$group['last_sample_at'],
            ];
        }

        usort($profiles, static function(array $a, array $b): int {
            $bySamples = (int)$b['samples'] <=> (int)$a['samples'];
            if ($bySamples !== 0) return $bySamples;
            return strcmp((string)$a['strategy_key'], (string)$b['strategy_key']);
        });

        return [
            'model'=>self::MODEL,
            'policy'=>'reduction_only_add_execution_margin',
            'minimum_samples'=>self::MIN_SAMPLES,
            'maximum_penalty_percent'=>self::MAX_PENALTY_PERCENT,
            'profiles'=>$profiles,
            'samples'=>count($samples),
            'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    public function activateRuntime(?PDO $pdo = null): array
    {
        self::$runtimeSnapshot = $this->snapshot($pdo);
        return self::$runtimeSnapshot;
    }

    public static function clearRuntime(): void
    {
        self::$runtimeSnapshot = null;
    }

    public function assessSignal(PDO $pdo, array $signal, array $market = []): array
    {
        $strategy = NobitexStrategyLearning::strategyKey($signal);
        $regime = NobitexStrategyLearning::regimeKey($signal);
        $quote = strtoupper(trim((string)($market['quote_asset'] ?? '')));
        if ($quote === '') $quote = 'IRT';

        $snapshot = self::$runtimeSnapshot ?? $this->activateRuntime($pdo);
        $profile = null;
        foreach ((array)($snapshot['profiles'] ?? []) as $candidate) {
            if ((string)($candidate['strategy_key'] ?? '') !== $strategy) continue;
            if ((string)($candidate['regime'] ?? '') !== $regime) continue;
            if (strtoupper((string)($candidate['quote_asset'] ?? '')) !== $quote) continue;
            $profile = $candidate;
            break;
        }

        $penalty = $profile !== null && (bool)($profile['learning_ready'] ?? false)
            ? max(0.0, min(self::MAX_PENALTY_PERCENT, (float)($profile['execution_penalty_percent'] ?? 0.0)))
            : 0.0;

        return [
            'model'=>self::MODEL,
            'strategy_key'=>$strategy,
            'regime'=>$regime,
            'quote_asset'=>$quote,
            'samples'=>(int)($profile['samples'] ?? 0),
            'learning_ready'=>(bool)($profile['learning_ready'] ?? false),
            'execution_penalty_percent'=>round($penalty, 4),
            'profile'=>$profile,
        ];
    }

    /** @return array{samples:int,average_slippage_percent:float,median_slippage_percent:float,p75_slippage_percent:float,average_fill_ratio:float,partial_fill_rate:float,reprice_rate:float} */
    public static function statistics(array $slippages, array $fillRatios, int $reprices = 0): array
    {
        $slip = [];
        foreach ($slippages as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) continue;
            $slip[] = max(-5.0, min(5.0, (float)$value));
        }
        $samples = count($slip);
        if ($samples === 0) {
            return [
                'samples'=>0,'average_slippage_percent'=>0.0,'median_slippage_percent'=>0.0,
                'p75_slippage_percent'=>0.0,'average_fill_ratio'=>1.0,'partial_fill_rate'=>0.0,'reprice_rate'=>0.0,
            ];
        }

        $ratios = [];
        foreach ($fillRatios as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) continue;
            $ratios[] = max(0.0, min(1.0, (float)$value));
        }
        while (count($ratios) < $samples) $ratios[] = 1.0;
        if (count($ratios) > $samples) $ratios = array_slice($ratios, 0, $samples);

        $sorted = $slip;
        sort($sorted, SORT_NUMERIC);
        $median = self::percentile($sorted, 0.50);
        $p75 = self::percentile($sorted, 0.75);
        $partials = count(array_filter($ratios, static fn(float $v): bool => $v < 0.9999));

        return [
            'samples'=>$samples,
            'average_slippage_percent'=>array_sum($slip) / $samples,
            'median_slippage_percent'=>$median,
            'p75_slippage_percent'=>$p75,
            'average_fill_ratio'=>array_sum($ratios) / $samples,
            'partial_fill_rate'=>$partials / $samples,
            'reprice_rate'=>max(0, min($samples, $reprices)) / $samples,
        ];
    }

    /** @param array{samples:int,average_slippage_percent:float,median_slippage_percent:float,p75_slippage_percent:float,average_fill_ratio:float,partial_fill_rate:float,reprice_rate:float} $stats */
    public static function penaltyFromStatistics(array $stats): float
    {
        if ((int)$stats['samples'] < self::MIN_SAMPLES) return 0.0;

        $averageAdverse = max(0.0, (float)$stats['average_slippage_percent']);
        $p75Adverse = max(0.0, (float)$stats['p75_slippage_percent']);
        $fillPenalty = max(0.0, 1.0 - (float)$stats['average_fill_ratio']) * 0.10;
        $partialPenalty = max(0.0, (float)$stats['partial_fill_rate']) * 0.04;
        $repricePenalty = max(0.0, (float)$stats['reprice_rate']) * 0.03;

        // Ignore a tiny noise floor so normal micro-slippage does not cause the
        // learner to ratchet the entry gate upward forever.
        $raw = ($averageAdverse * 0.55)
            + ($p75Adverse * 0.45)
            + $fillPenalty
            + $partialPenalty
            + $repricePenalty
            - 0.02;

        return round(max(0.0, min(self::MAX_PENALTY_PERCENT, $raw)), 4);
    }

    private static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float)$sorted[0];
        $index = max(0.0, min((float)($n - 1), ($n - 1) * $p));
        $low = (int)floor($index);
        $high = (int)ceil($index);
        if ($low === $high) return (float)$sorted[$low];
        $weight = $index - $low;
        return ((float)$sorted[$low] * (1.0 - $weight)) + ((float)$sorted[$high] * $weight);
    }

    private function firstOrder(array $response): array
    {
        if (is_array($response['order'] ?? null)) return $response['order'];
        if (is_array($response['orders'][0] ?? null)) return $response['orders'][0];
        return $response;
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float)$value;
        return is_finite($number) ? $number : 0.0;
    }
}
