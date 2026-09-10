<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Adaptive Edge Calibration v1.
 *
 * Compares the predicted tradable net edge at entry with realized fee-aware
 * returns for the exact strategy/regime profile. Calibration is conservative:
 * it may only add an extra required margin or block a marginal BUY. It never
 * relaxes the base signal-engine edge requirement and never increases size.
 */
final class NobitexEdgeCalibration
{
    public const MODEL = 'adaptive_edge_calibration_v1';
    private const MIN_TRADES = 8;
    private const TOLERATED_BIAS_PERCENT = 0.10;
    private const MAX_PENALTY_PERCENT = 0.85;

    /** @var array<string,mixed>|null */
    private static ?array $runtimeSnapshot = null;

    public function snapshot(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $learning = (new NobitexStrategyLearning())->snapshot($pdo);
        $profiles = [];

        foreach ((array)($learning['profiles'] ?? []) as $profile) {
            if (!is_array($profile)) continue;
            $penalty = self::penaltyFromProfile($profile);
            $profiles[] = [
                'strategy_key'=>(string)($profile['strategy_key'] ?? 'none'),
                'regime'=>(string)($profile['regime'] ?? 'unknown'),
                'trades'=>(int)($profile['trades'] ?? 0),
                'average_entry_edge_percent'=>$profile['average_entry_edge_percent'] ?? null,
                'average_realized_return_percent'=>(float)($profile['average_return_percent'] ?? 0.0),
                'edge_capture_ratio'=>$profile['edge_capture_ratio'] ?? null,
                'calibration_penalty_percent'=>$penalty,
                'calibration_ready'=>(int)($profile['trades'] ?? 0) >= self::MIN_TRADES
                    && is_numeric($profile['average_entry_edge_percent'] ?? null)
                    && (float)$profile['average_entry_edge_percent'] > 0.0,
                'last_realized_at'=>$profile['last_realized_at'] ?? null,
            ];
        }

        usort($profiles, static function (array $a, array $b): int {
            $byPenalty = (float)$b['calibration_penalty_percent'] <=> (float)$a['calibration_penalty_percent'];
            if ($byPenalty !== 0) return $byPenalty;
            return (int)$b['trades'] <=> (int)$a['trades'];
        });

        $penalized = array_values(array_filter(
            $profiles,
            static fn(array $p): bool => (float)$p['calibration_penalty_percent'] > 0.000001
        ));

        return [
            'model'=>self::MODEL,
            'policy'=>'tighten_only_never_relax',
            'minimum_calibration_trades'=>self::MIN_TRADES,
            'tolerated_prediction_bias_percent'=>self::TOLERATED_BIAS_PERCENT,
            'maximum_extra_margin_percent'=>self::MAX_PENALTY_PERCENT,
            'profiles'=>$profiles,
            'penalized_profiles'=>count($penalized),
            'maximum_active_penalty_percent'=>$penalized === [] ? 0.0 : max(array_map(
                static fn(array $p): float => (float)$p['calibration_penalty_percent'],
                $penalized
            )),
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
        $strategy = NobitexStrategyLearning::strategyKey($signal);
        $regime = NobitexStrategyLearning::regimeKey($signal);
        $rawEdge = is_numeric($signal['tradable_net_edge_percent'] ?? null)
            ? (float)$signal['tradable_net_edge_percent']
            : 0.0;
        $baseBuffer = is_numeric($signal['required_edge_buffer_percent'] ?? null)
            ? max(0.0, (float)$signal['required_edge_buffer_percent'])
            : 0.0;

        if ($rawEdge <= 0.0) {
            return [
                'allowed'=>false,
                'reason'=>'edge_calibration_no_positive_raw_edge',
                'strategy_key'=>$strategy,
                'regime'=>$regime,
                'raw_tradable_net_edge_percent'=>$rawEdge,
                'calibration_penalty_percent'=>0.0,
                'calibrated_tradable_net_edge_percent'=>$rawEdge,
                'base_required_edge_buffer_percent'=>$baseBuffer,
                'calibrated_required_edge_buffer_percent'=>$baseBuffer,
                'profile'=>null,
            ];
        }

        $snapshot = self::$runtimeSnapshot ?? $this->activateRuntime($pdo);
        $profile = null;
        foreach ((array)($snapshot['profiles'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            if ((string)($candidate['strategy_key'] ?? '') === $strategy
                && (string)($candidate['regime'] ?? '') === $regime) {
                $profile = $candidate;
                break;
            }
        }

        $penalty = $profile === null ? 0.0 : (float)($profile['calibration_penalty_percent'] ?? 0.0);
        $adjusted = $rawEdge - $penalty;
        $ready = (bool)($profile['calibration_ready'] ?? false);

        return [
            'allowed'=>$adjusted > 0.0,
            'reason'=>$adjusted > 0.0
                ? ($penalty > 0.000001 ? 'edge_calibration_extra_margin_applied' : ($ready ? 'edge_calibration_neutral' : 'edge_calibration_warmup'))
                : 'edge_calibration_below_required_margin',
            'strategy_key'=>$strategy,
            'regime'=>$regime,
            'raw_tradable_net_edge_percent'=>round($rawEdge, 4),
            'calibration_penalty_percent'=>round($penalty, 4),
            'calibrated_tradable_net_edge_percent'=>round($adjusted, 4),
            'base_required_edge_buffer_percent'=>round($baseBuffer, 4),
            'calibrated_required_edge_buffer_percent'=>round($baseBuffer + $penalty, 4),
            'profile'=>$profile,
        ];
    }

    /**
     * Pure deterministic calibration used by regression tests.
     * A positive prediction bias smaller than 0.10% is treated as normal noise.
     */
    public static function penaltyFromProfile(array $profile): float
    {
        $trades = max(0, (int)($profile['trades'] ?? 0));
        $edge = $profile['average_entry_edge_percent'] ?? null;
        $realized = is_numeric($profile['average_return_percent'] ?? null)
            ? (float)$profile['average_return_percent']
            : 0.0;

        if ($trades < self::MIN_TRADES || !is_numeric($edge)) return 0.0;
        $edge = (float)$edge;
        if (!is_finite($edge) || !is_finite($realized) || $edge <= 0.0) return 0.0;

        $bias = max(0.0, $edge - $realized - self::TOLERATED_BIAS_PERCENT);
        if ($bias <= 0.0) return 0.0;

        // Bayesian-style shrinkage: small mature samples have a deliberately
        // limited influence and approach full weight only as evidence grows.
        $reliability = min(1.0, $trades / 24.0);
        $capture = is_numeric($profile['edge_capture_ratio'] ?? null)
            ? (float)$profile['edge_capture_ratio']
            : ($edge > 0.000001 ? $realized / $edge : 1.0);
        $severity = 0.60;
        if ($realized < 0.0) $severity = 0.75;
        elseif ($capture >= 0.75) $severity = 0.40;

        $penalty = $bias * $reliability * $severity;
        return round(max(0.0, min(self::MAX_PENALTY_PERCENT, $penalty)), 4);
    }
}
