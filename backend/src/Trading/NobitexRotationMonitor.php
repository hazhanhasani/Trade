<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Read-only observability for Nobitex portfolio rotation.
 *
 * The production rotation engine always re-scans the exchange before executing.
 * This monitor deliberately uses the latest persisted strategy signals so opening
 * the admin panel or Android app never consumes Nobitex API rate limits or places
 * orders. Its preview is diagnostic only; execution still passes every live guard.
 */
final class NobitexRotationMonitor
{
    private const SIGNAL_TTL_SECONDS = 300;
    private const DEFAULT_MIN_ADVANTAGE_PERCENT = 0.75;
    private const DEFAULT_MIN_HOLD_MINUTES = 45;
    private const DEFAULT_COOLDOWN_MINUTES = 30;
    private const DEFAULT_MAX_ROTATION_LOSS_PERCENT = 0.75;
    private const DEFAULT_FRICTION_MARGIN_PERCENT = 0.15;

    public function snapshot(int $historyLimit = 20): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $now = time();
        $historyLimit = max(1, min(50, $historyLimit));

        $config = $this->config($pdo);
        $maxPositions = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        $positions = $this->positions($pdo);
        $pendingCount = count(array_filter(
            $positions,
            static fn(array $p): bool => in_array((string)($p['status'] ?? ''), ['pending_open','pending_close'], true)
        ));

        $signals = $this->latestSignals($pdo, $now);
        $activeSymbols = [];
        $activeAssets = [];
        foreach ($positions as $position) {
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            $asset = $this->canonicalAsset((string)($position['asset'] ?? ''));
            if ($symbol !== '') $activeSymbols[$symbol] = true;
            if ($asset !== '') $activeAssets[$asset] = true;
        }

        $plannerPositions = [];
        $weakest = null;
        $freshPositionSignals = 0;
        foreach ($positions as $position) {
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            $signal = $signals[$symbol] ?? null;
            $edge = is_array($signal) ? $this->signalEdge($signal['details']) : null;
            $exitCost = is_array($signal)
                ? max(0.0, (float)($signal['details']['estimated_exit_cost_percent'] ?? 0.0))
                : 0.0;

            $row = $position;
            $row['asset'] = $this->canonicalAsset((string)($position['asset'] ?? ''));
            $row['forward_edge_percent'] = $edge;
            $row['estimated_exit_cost_percent'] = $exitCost;
            $row['signal_fresh'] = is_array($signal) ? (bool)$signal['fresh'] : false;
            $row['signal_at'] = is_array($signal) ? $signal['created_at'] : null;
            $plannerPositions[] = $row;

            if ((string)($position['status'] ?? '') !== 'open' || $edge === null || !($row['signal_fresh'] ?? false)) continue;
            $freshPositionSignals++;
            $candidate = $this->positionView($row);
            if ($weakest === null || (float)$candidate['forward_edge_percent'] < (float)$weakest['forward_edge_percent']) {
                $weakest = $candidate;
            }
        }

        $plannerCandidates = [];
        $bestCandidate = null;
        foreach ($signals as $signal) {
            $details = $signal['details'];
            if (!$signal['fresh']) continue;
            if (($details['ready'] ?? false) !== true) continue;
            if (strtolower((string)($signal['action'] ?? $details['action'] ?? '')) !== 'buy') continue;

            $symbol = strtoupper((string)($signal['symbol'] ?? ''));
            $asset = $this->canonicalAsset((string)($details['asset'] ?? $this->assetFromSymbol($symbol)));
            $quote = strtoupper((string)($details['quote_asset'] ?? $this->quoteFromSymbol($symbol)));
            $edge = $this->signalEdge($details);
            if ($symbol === '' || $asset === '' || !in_array($quote, ['IRT','USDT'], true) || $edge === null || $edge <= 0.0) continue;
            if (isset($activeSymbols[$symbol]) || isset($activeAssets[$asset])) continue;

            $candidate = [
                'symbol'=>$symbol,
                'asset'=>$asset,
                'quote_asset'=>$quote,
                'signal'=>[
                    'ready'=>true,
                    'action'=>'buy',
                    'tradable_net_edge_percent'=>$edge,
                    'expected_net_edge_percent'=>$details['expected_net_edge_percent'] ?? null,
                    'execution_quality_score'=>$details['execution_quality_score'] ?? null,
                ],
            ];
            $plannerCandidates[] = $candidate;

            $view = [
                'symbol'=>$symbol,
                'asset'=>$asset,
                'quote_asset'=>$quote,
                'tradable_net_edge_percent'=>round($edge, 4),
                'execution_quality_score'=>$details['execution_quality_score'] ?? null,
                'signal_at'=>$signal['created_at'],
                'age_seconds'=>$signal['age_seconds'],
            ];
            if ($bestCandidate === null || $edge > (float)$bestCandidate['tradable_net_edge_percent']) {
                $bestCandidate = $view;
            }
        }

        $lastRotation = $this->setting($pdo, 'nobitex_last_rotation_at');
        $cooldownUntil = null;
        $cooldownRemaining = 0;
        if ($lastRotation !== null && trim($lastRotation) !== '') {
            $lastTs = strtotime($lastRotation . ' UTC');
            if ($lastTs !== false) {
                $nextTs = $lastTs + ($config['cooldown_minutes'] * 60);
                if ($nextTs > $now) {
                    $cooldownRemaining = $nextTs - $now;
                    $cooldownUntil = gmdate(DATE_ATOM, $nextTs);
                }
            }
        }

        $plan = (new NobitexPortfolioRotation())->plan($plannerPositions, $plannerCandidates, [
            'min_advantage_percent'=>$config['min_advantage_percent'],
            'min_hold_minutes'=>$config['min_hold_minutes'],
            'max_rotation_loss_percent'=>$config['max_rotation_loss_percent'],
            'friction_margin_percent'=>$config['friction_margin_percent'],
        ], $now);

        $reason = (string)($plan['reason'] ?? 'rotation_preview_unavailable');
        $state = 'watching';
        if (!$config['enabled']) {
            $state = 'disabled';
            $reason = 'rotation_disabled';
        } elseif ($this->boolSetting($pdo, 'kill_switch', false)) {
            $state = 'blocked';
            $reason = 'kill_switch';
        } elseif (count($positions) < $maxPositions) {
            $state = 'standby';
            $reason = 'portfolio_has_free_slot';
        } elseif ($pendingCount > 0) {
            $state = 'blocked';
            $reason = 'pending_order_present';
        } elseif ($cooldownRemaining > 0) {
            $state = 'cooldown';
            $reason = 'rotation_cooldown_active';
        } elseif (($plan['rotate'] ?? false) === true) {
            $state = 'ready';
            $reason = 'superior_opportunity_after_rotation_costs';
        } elseif ($freshPositionSignals === 0 && count($positions) > 0) {
            $state = 'waiting_data';
            $reason = 'position_signal_data_stale';
        }

        return [
            'mode'=>'read_only_preview',
            'state'=>$state,
            'reason'=>$reason,
            'generated_at'=>gmdate(DATE_ATOM, $now),
            'signal_ttl_seconds'=>self::SIGNAL_TTL_SECONDS,
            'portfolio'=>[
                'active_positions'=>count($positions),
                'max_positions'=>$maxPositions,
                'portfolio_full'=>count($positions) >= $maxPositions,
                'pending_orders'=>$pendingCount,
                'fresh_position_signals'=>$freshPositionSignals,
            ],
            'weakest_position'=>$weakest,
            'best_replacement'=>$bestCandidate,
            'preview_plan'=>$this->planView($plan),
            'cooldown'=>[
                'last_rotation_at'=>$lastRotation,
                'until'=>$cooldownUntil,
                'remaining_seconds'=>$cooldownRemaining,
                'active'=>$cooldownRemaining > 0,
            ],
            'config'=>$config,
            'last_target_symbol'=>$this->setting($pdo, 'nobitex_rotation_target_symbol'),
            'history'=>$this->history($pdo, $historyLimit),
            'note'=>'Preview uses persisted signals only; execution performs a fresh exchange scan and re-applies all risk guards.',
        ];
    }

    private function positions(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id,symbol,asset,quote_asset,amount,entry_price,status,mark_price,
                    unrealized_net_pnl_percent,highest_net_pnl_percent,opened_at,updated_at
             FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close')
             ORDER BY id ASC LIMIT 20"
        )->fetchAll();
    }

    private function latestSignals(PDO $pdo, int $now): array
    {
        $rows = $pdo->query(
            "SELECT id,symbol,asset,quote_asset,action,price,details_json,created_at
             FROM nobitex_autotrade_signals ORDER BY id DESC LIMIT 250"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $symbol = strtoupper((string)($row['symbol'] ?? ''));
            if ($symbol === '' || isset($out[$symbol])) continue;
            $details = json_decode((string)($row['details_json'] ?? ''), true);
            if (!is_array($details)) $details = [];
            $details['asset'] ??= $row['asset'] ?? null;
            $details['quote_asset'] ??= $row['quote_asset'] ?? null;
            $details['action'] ??= strtolower((string)($row['action'] ?? 'hold'));
            $createdAt = (string)($row['created_at'] ?? '');
            $ts = $createdAt !== '' ? strtotime($createdAt . ' UTC') : false;
            $age = $ts === false ? null : max(0, $now - $ts);
            $out[$symbol] = [
                'symbol'=>$symbol,
                'action'=>strtolower((string)($row['action'] ?? 'hold')),
                'created_at'=>$createdAt,
                'age_seconds'=>$age,
                'fresh'=>$age !== null && $age <= self::SIGNAL_TTL_SECONDS,
                'details'=>$details,
            ];
        }
        return $out;
    }

    private function history(PDO $pdo, int $limit): array
    {
        $stmt = $pdo->query(
            "SELECT id,level,event_name,context_json,created_at
             FROM nobitex_autotrade_events
             WHERE event_name LIKE 'nobitex.rotation.%'
             ORDER BY id DESC LIMIT {$limit}"
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as &$row) {
            $context = json_decode((string)($row['context_json'] ?? ''), true);
            $row['context'] = is_array($context) ? $context : [];
            unset($row['context_json']);
        }
        unset($row);
        return $rows;
    }

    private function config(PDO $pdo): array
    {
        return [
            'enabled'=>$this->boolSetting($pdo, 'nobitex_rotation_enabled', true),
            'min_advantage_percent'=>$this->floatSetting($pdo, 'nobitex_rotation_min_advantage_percent', self::DEFAULT_MIN_ADVANTAGE_PERCENT, 0.25, 5.0),
            'min_hold_minutes'=>$this->intSetting($pdo, 'nobitex_rotation_min_hold_minutes', self::DEFAULT_MIN_HOLD_MINUTES, 10, 1440),
            'cooldown_minutes'=>$this->intSetting($pdo, 'nobitex_rotation_cooldown_minutes', self::DEFAULT_COOLDOWN_MINUTES, 5, 240),
            'max_rotation_loss_percent'=>$this->floatSetting($pdo, 'nobitex_rotation_max_loss_percent', self::DEFAULT_MAX_ROTATION_LOSS_PERCENT, 0.10, 5.0),
            'friction_margin_percent'=>$this->floatSetting($pdo, 'nobitex_rotation_friction_margin_percent', self::DEFAULT_FRICTION_MARGIN_PERCENT, 0.05, 1.0),
        ];
    }

    private function planView(array $plan): array
    {
        return [
            'rotate'=>(bool)($plan['rotate'] ?? false),
            'reason'=>(string)($plan['reason'] ?? 'rotation_preview_unavailable'),
            'victim'=>is_array($plan['victim'] ?? null) ? $plan['victim'] : null,
            'candidate'=>is_array($plan['candidate'] ?? null) ? $plan['candidate'] : null,
            'advantage_percent'=>isset($plan['advantage_percent']) ? (float)$plan['advantage_percent'] : null,
            'required_advantage_percent'=>isset($plan['required_advantage_percent']) ? (float)$plan['required_advantage_percent'] : null,
            'advantage_surplus_percent'=>isset($plan['advantage_surplus_percent']) ? (float)$plan['advantage_surplus_percent'] : null,
            'guards'=>is_array($plan['guards'] ?? null) ? $plan['guards'] : null,
        ];
    }

    private function positionView(array $position): array
    {
        $openedAt = (string)($position['opened_at'] ?? '');
        $openedTs = $openedAt !== '' ? strtotime($openedAt . ' UTC') : false;
        return [
            'id'=>(int)($position['id'] ?? 0),
            'symbol'=>strtoupper((string)($position['symbol'] ?? '')),
            'asset'=>$this->canonicalAsset((string)($position['asset'] ?? '')),
            'quote_asset'=>strtoupper((string)($position['quote_asset'] ?? '')),
            'forward_edge_percent'=>is_numeric($position['forward_edge_percent'] ?? null) ? round((float)$position['forward_edge_percent'], 4) : null,
            'unrealized_net_pnl_percent'=>is_numeric($position['unrealized_net_pnl_percent'] ?? null) ? round((float)$position['unrealized_net_pnl_percent'], 4) : null,
            'estimated_exit_cost_percent'=>round(max(0.0, (float)($position['estimated_exit_cost_percent'] ?? 0.0)), 4),
            'opened_at'=>$openedAt !== '' ? $openedAt : null,
            'hold_minutes'=>$openedTs === false ? null : max(0, (int)floor((time() - $openedTs) / 60)),
            'signal_at'=>$position['signal_at'] ?? null,
        ];
    }

    private function signalEdge(array $signal): ?float
    {
        foreach (['tradable_net_edge_percent','expected_net_edge_percent'] as $key) {
            if (!is_numeric($signal[$key] ?? null)) continue;
            $value = (float)$signal[$key];
            if (is_finite($value)) return $value;
        }
        return null;
    }

    private function assetFromSymbol(string $symbol): string
    {
        foreach (['USDT','IRT'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return substr($symbol, 0, -strlen($quote));
            }
        }
        return '';
    }

    private function quoteFromSymbol(string $symbol): string
    {
        foreach (['USDT','IRT'] as $quote) {
            if (str_ends_with($symbol, $quote)) return $quote;
        }
        return '';
    }

    private function canonicalAsset(string $asset): string
    {
        $asset = strtoupper(trim($asset));
        return in_array($asset, ['TON','GRAM','TONCOIN'], true) ? 'TON' : $asset;
    }

    private function setting(PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    private function boolSetting(PDO $pdo, string $key, bool $default): bool
    {
        $value = $this->setting($pdo, $key);
        if ($value === null) return $default;
        return in_array(strtolower(trim($value)), ['1','true','yes','on'], true);
    }

    private function intSetting(PDO $pdo, string $key, int $default, int $min, int $max): int
    {
        $value = $this->setting($pdo, $key);
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }

    private function floatSetting(PDO $pdo, string $key, float $default, float $min, float $max): float
    {
        $value = $this->setting($pdo, $key);
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (float)$value));
    }
}
