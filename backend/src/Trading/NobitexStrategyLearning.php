<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Strategy Learning v2.
 *
 * Learns independently from realized, fee-aware returns of Trend/Momentum,
 * Breakout and Mean Reversion entries. Learning is reduction-only: it may keep
 * or reduce the next entry size for the same strategy/regime profile, and may
 * block a persistently bad mature profile. It can never enlarge configured risk.
 * Adaptive Edge Calibration is applied on top of that profile-specific history
 * and may only tighten the entry margin; it can never relax the raw signal gate.
 */
final class NobitexStrategyLearning
{
    public const MODEL = 'strategy_learning_v2';

    private const STRATEGIES = [
        'trend_momentum_v1',
        'breakout_v1',
        'mean_reversion_v1',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $runtimeSnapshot = null;

    public function snapshot(?PDO $pdo = null, int $limit = 240): array
    {
        $pdo ??= Database::connection();
        $limit = max(30, min(500, $limit));
        $samples = $this->realizedSamples($pdo, $limit);
        $groups = [];

        foreach ($samples as $sample) {
            $strategy = (string)($sample['strategy_key'] ?? '');
            $regime = (string)($sample['regime'] ?? 'unknown');
            if (!in_array($strategy, self::STRATEGIES, true)) continue;

            $key = $strategy . '|' . $regime;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'strategy_key'=>$strategy,
                    'regime'=>$regime,
                    'returns'=>[],
                    'entry_edges'=>[],
                    'realized_at'=>[],
                ];
            }
            $groups[$key]['returns'][] = (float)$sample['return_percent'];
            if ($sample['entry_edge_percent'] !== null) {
                $groups[$key]['entry_edges'][] = (float)$sample['entry_edge_percent'];
            }
            $groups[$key]['realized_at'][] = (string)$sample['realized_at'];
        }

        $profiles = [];
        foreach ($groups as $group) {
            $stats = self::statistics($group['returns']);
            $multiplier = self::sizeMultiplierFromStats(
                $stats['trades'],
                $stats['win_rate'],
                $stats['average_return_percent'],
                $stats['profit_factor'],
                $stats['recent_loss_streak']
            );
            $blocked = self::shouldBlockProfile(
                $stats['trades'],
                $stats['win_rate'],
                $stats['average_return_percent'],
                $stats['profit_factor']
            );
            $avgEdge = $group['entry_edges'] === [] ? null : array_sum($group['entry_edges']) / count($group['entry_edges']);
            $edgeCapture = $avgEdge !== null && abs($avgEdge) > 0.000001
                ? $stats['average_return_percent'] / $avgEdge
                : null;

            $profiles[] = [
                'strategy_key'=>$group['strategy_key'],
                'regime'=>$group['regime'],
                'trades'=>$stats['trades'],
                'wins'=>$stats['wins'],
                'losses'=>$stats['losses'],
                'win_rate'=>round($stats['win_rate'], 4),
                'average_return_percent'=>round($stats['average_return_percent'], 4),
                'median_return_percent'=>round($stats['median_return_percent'], 4),
                'profit_factor'=>round(min(99.0, $stats['profit_factor']), 4),
                'recent_loss_streak'=>$stats['recent_loss_streak'],
                'average_entry_edge_percent'=>$avgEdge === null ? null : round($avgEdge, 4),
                'edge_capture_ratio'=>$edgeCapture === null ? null : round($edgeCapture, 4),
                'size_multiplier'=>round($multiplier, 4),
                'learning_ready'=>$stats['trades'] >= 5,
                'block_ready'=>$stats['trades'] >= 12,
                'blocked'=>$blocked,
                'last_realized_at'=>$group['realized_at'] === [] ? null : end($group['realized_at']),
            ];
        }

        usort($profiles, static function(array $a, array $b): int {
            $byStrategy = strcmp((string)$a['strategy_key'], (string)$b['strategy_key']);
            if ($byStrategy !== 0) return $byStrategy;
            return ($b['trades'] <=> $a['trades']);
        });

        $strategySummary = [];
        foreach (self::STRATEGIES as $strategy) {
            $strategyProfiles = array_values(array_filter(
                $profiles,
                static fn(array $p): bool => (string)$p['strategy_key'] === $strategy
            ));
            $trades = array_sum(array_map(static fn(array $p): int => (int)$p['trades'], $strategyProfiles));
            $weightedMultiplier = 0.0;
            $weight = 0;
            foreach ($strategyProfiles as $profile) {
                if ((int)$profile['trades'] < 5) continue;
                $w = (int)$profile['trades'];
                $weightedMultiplier += (float)$profile['size_multiplier'] * $w;
                $weight += $w;
            }
            $strategySummary[] = [
                'strategy_key'=>$strategy,
                'trades'=>$trades,
                'size_multiplier'=>$weight > 0 ? round(max(0.55, min(1.0, $weightedMultiplier / $weight)), 4) : 1.0,
                'mature_profiles'=>count(array_filter($strategyProfiles, static fn(array $p): bool => (bool)$p['learning_ready'])),
                'blocked_profiles'=>count(array_filter($strategyProfiles, static fn(array $p): bool => (bool)$p['blocked'])),
            ];
        }

        return [
            'model'=>self::MODEL,
            'learning_source'=>'realized_fee_aware_trade_returns',
            'risk_policy'=>'reduction_only_never_increase',
            'edge_calibration_model'=>NobitexEdgeCalibration::MODEL,
            'minimum_learning_trades'=>5,
            'minimum_block_trades'=>12,
            'strategies'=>$strategySummary,
            'profiles'=>$profiles,
            'realized_samples'=>count($samples),
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

    public function assessSignal(PDO $pdo, array $signal): array
    {
        $strategy = self::strategyKey($signal);
        $regime = self::regimeKey($signal);
        if (!in_array($strategy, self::STRATEGIES, true)) {
            return [
                'allowed'=>true,
                'reason'=>'strategy_learning_not_applicable',
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'size_multiplier'=>1.0,
                'stats'=>null,
            ] + self::calibrationContext($signal, null);
        }

        $snapshot = self::$runtimeSnapshot ?? $this->activateRuntime($pdo);
        $stats = null;
        foreach ((array)($snapshot['profiles'] ?? []) as $profile) {
            if ((string)($profile['strategy_key'] ?? '') === $strategy && (string)($profile['regime'] ?? '') === $regime) {
                $stats = $profile;
                break;
            }
        }

        if ($stats === null || (int)($stats['trades'] ?? 0) < 5) {
            return [
                'allowed'=>true,
                'reason'=>'strategy_learning_warmup',
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'size_multiplier'=>1.0,
                'stats'=>$stats,
            ] + self::calibrationContext($signal, $stats);
        }

        if ((bool)($stats['blocked'] ?? false)) {
            return [
                'allowed'=>false,
                'reason'=>'strategy_profile_persistently_unprofitable',
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'size_multiplier'=>(float)($stats['size_multiplier'] ?? 0.55),
                'stats'=>$stats,
            ] + self::calibrationContext($signal, $stats);
        }

        $calibration = self::calibrationContext($signal, $stats);
        if ((float)$calibration['raw_tradable_net_edge_percent'] > 0.0
            && (float)$calibration['calibrated_tradable_net_edge_percent'] <= 0.0) {
            return [
                'allowed'=>false,
                'reason'=>'edge_calibration_below_required_margin',
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'size_multiplier'=>max(0.55, min(1.0, (float)($stats['size_multiplier'] ?? 1.0))),
                'stats'=>$stats,
            ] + $calibration;
        }

        $learningReason = (float)($stats['size_multiplier'] ?? 1.0) < 0.9999
            ? 'strategy_learning_reduced_size'
            : 'strategy_learning_neutral';
        if ((float)$calibration['edge_calibration_penalty_percent'] > 0.000001) {
            $learningReason = $learningReason === 'strategy_learning_reduced_size'
                ? 'strategy_learning_reduced_size_with_edge_calibration'
                : 'edge_calibration_extra_margin_applied';
        }

        return [
            'allowed'=>true,
            'reason'=>$learningReason,
            'strategy_key'=>$strategy,
            'regime'=>$regime,
            'size_multiplier'=>max(0.55, min(1.0, (float)($stats['size_multiplier'] ?? 1.0))),
            'stats'=>$stats,
        ] + $calibration;
    }

    public static function strategyKey(array $signal): string
    {
        $key = trim((string)($signal['strategy_key'] ?? $signal['selected_strategy']['key'] ?? ''));
        return $key !== '' ? $key : 'none';
    }

    public static function regimeKey(array $signal): string
    {
        $regime = trim((string)($signal['market_regime']['regime'] ?? $signal['regime'] ?? ''));
        return $regime !== '' ? $regime : 'unknown';
    }

    /** @return array<string,mixed> */
    private static function calibrationContext(array $signal, ?array $profile): array
    {
        $rawEdge = is_numeric($signal['tradable_net_edge_percent'] ?? null)
            ? (float)$signal['tradable_net_edge_percent']
            : 0.0;
        $baseBuffer = is_numeric($signal['required_edge_buffer_percent'] ?? null)
            ? max(0.0, (float)$signal['required_edge_buffer_percent'])
            : 0.0;
        $penalty = $profile === null ? 0.0 : NobitexEdgeCalibration::penaltyFromProfile($profile);

        return [
            'edge_calibration_model'=>NobitexEdgeCalibration::MODEL,
            'edge_calibration_penalty_percent'=>round($penalty, 4),
            'raw_tradable_net_edge_percent'=>round($rawEdge, 4),
            'calibrated_tradable_net_edge_percent'=>round($rawEdge - $penalty, 4),
            'base_required_edge_buffer_percent'=>round($baseBuffer, 4),
            'calibrated_required_edge_buffer_percent'=>round($baseBuffer + $penalty, 4),
        ];
    }

    /**
     * Pure deterministic multiplier for regression tests.
     * Sample size below five remains neutral to avoid overfitting noise.
     */
    public static function sizeMultiplierFromStats(
        int $trades,
        float $winRate,
        float $averageReturn,
        float $profitFactor,
        int $recentLossStreak = 0
    ): float {
        if ($trades < 5) return 1.0;

        $multiplier = 1.0;
        if ($averageReturn < 0.0) {
            $multiplier -= min(0.22, abs($averageReturn) * 0.16);
        }
        if ($profitFactor < 1.0) {
            $multiplier -= min(0.20, (1.0 - max(0.0, $profitFactor)) * 0.30);
        }
        if ($winRate < 0.45) {
            $multiplier -= min(0.14, (0.45 - max(0.0, $winRate)) * 0.55);
        }
        if ($recentLossStreak >= 5) $multiplier = min($multiplier, 0.60);
        elseif ($recentLossStreak >= 3) $multiplier = min($multiplier, 0.78);

        // Young-but-eligible samples are intentionally bounded more softly.
        if ($trades < 8) $multiplier = max(0.80, $multiplier);
        return round(max(0.55, min(1.0, $multiplier)), 4);
    }

    public static function shouldBlockProfile(int $trades, float $winRate, float $averageReturn, float $profitFactor): bool
    {
        if ($trades < 12) return false;
        $deepNegative = $averageReturn <= -0.25 && $profitFactor < 0.80;
        $persistentFailure = $averageReturn < -0.10 && $profitFactor < 0.65 && $winRate < 0.35;
        return $deepNegative || $persistentFailure;
    }

    /** @return array{trades:int,wins:int,losses:int,win_rate:float,average_return_percent:float,median_return_percent:float,profit_factor:float,recent_loss_streak:int} */
    public static function statistics(array $returns): array
    {
        $clean = [];
        foreach ($returns as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) continue;
            $clean[] = max(-50.0, min(50.0, (float)$value));
        }
        $trades = count($clean);
        if ($trades === 0) {
            return ['trades'=>0,'wins'=>0,'losses'=>0,'win_rate'=>0.0,'average_return_percent'=>0.0,'median_return_percent'=>0.0,'profit_factor'=>1.0,'recent_loss_streak'=>0];
        }
        $wins = count(array_filter($clean, static fn(float $v): bool => $v > 0.0));
        $losses = count(array_filter($clean, static fn(float $v): bool => $v < 0.0));
        $positive = array_sum(array_filter($clean, static fn(float $v): bool => $v > 0.0));
        $negative = abs(array_sum(array_filter($clean, static fn(float $v): bool => $v < 0.0)));
        $profitFactor = $negative > 0.00000001 ? $positive / $negative : ($positive > 0.0 ? 99.0 : 1.0);
        $sorted = $clean;
        sort($sorted, SORT_NUMERIC);
        $middle = intdiv($trades, 2);
        $median = $trades % 2 === 0 ? (($sorted[$middle - 1] + $sorted[$middle]) / 2.0) : $sorted[$middle];
        $streak = 0;
        for ($i = $trades - 1; $i >= 0; $i--) {
            if ($clean[$i] < 0.0) $streak++; else break;
        }
        return [
            'trades'=>$trades,
            'wins'=>$wins,
            'losses'=>$losses,
            'win_rate'=>$wins / $trades,
            'average_return_percent'=>array_sum($clean) / $trades,
            'median_return_percent'=>$median,
            'profit_factor'=>$profitFactor,
            'recent_loss_streak'=>$streak,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function realizedSamples(PDO $pdo, int $limit): array
    {
        $rows = $pdo->query(
            "SELECT p.id,p.entry_order_local_id,p.symbol,p.quote_asset,
                    SUM(COALESCE(r.net_pnl,r.pnl)) AS net_pnl,
                    SUM((r.entry_price*r.amount)+COALESCE(r.entry_fee_quote,0)) AS cost_basis,
                    MAX(COALESCE(r.accounted_at,r.created_at)) AS realized_at,
                    MAX(s.details_json) AS details_json
             FROM nobitex_autotrade_positions p
             JOIN nobitex_autotrade_pnl r ON r.position_id=p.id
             LEFT JOIN nobitex_autotrade_signals s ON s.order_local_id=p.entry_order_local_id
             WHERE r.accounted_at IS NOT NULL
             GROUP BY p.id,p.entry_order_local_id,p.symbol,p.quote_asset
             HAVING cost_basis>0
             ORDER BY realized_at DESC
             LIMIT {$limit}"
        )->fetchAll();

        $out = [];
        foreach (array_reverse($rows) as $row) {
            $basis = (float)($row['cost_basis'] ?? 0.0);
            $net = (float)($row['net_pnl'] ?? 0.0);
            if ($basis <= 0.0 || !is_finite($net)) continue;
            $signal = json_decode((string)($row['details_json'] ?? ''), true);
            if (!is_array($signal)) $signal = [];
            $strategy = self::strategyKey($signal);
            if (!in_array($strategy, self::STRATEGIES, true)) continue;
            $edge = isset($signal['tradable_net_edge_percent']) && is_numeric($signal['tradable_net_edge_percent'])
                ? (float)$signal['tradable_net_edge_percent']
                : null;
            $out[] = [
                'position_id'=>(int)$row['id'],
                'symbol'=>(string)$row['symbol'],
                'quote_asset'=>(string)$row['quote_asset'],
                'strategy_key'=>$strategy,
                'regime'=>self::regimeKey($signal),
                'entry_edge_percent'=>$edge,
                'return_percent'=>($net / $basis) * 100.0,
                'realized_at'=>(string)$row['realized_at'],
            ];
        }
        return $out;
    }
}
