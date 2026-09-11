<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Guarded opportunity replacement for a full Nobitex portfolio.
 *
 * Rotation is intentionally a two-step operation: this component only exits a
 * weak incumbent. The normal portfolio engine owns the next entry after the
 * exit has been reconciled, so wallet state, exposure sizing and all regular
 * entry gates are re-evaluated before new risk is taken.
 *
 * When the configured maximum position count is lowered below the number of
 * already-open positions, capacity alignment takes priority over optional
 * opportunity rotation. One weak open position is sold at a time until the
 * live count is back within the configured limit.
 */
final class NobitexPortfolioRotation
{
    private const DEFAULT_MIN_ADVANTAGE_PERCENT = 0.75;
    private const DEFAULT_MIN_HOLD_MINUTES = 45;
    private const DEFAULT_COOLDOWN_MINUTES = 30;
    private const DEFAULT_MAX_ROTATION_LOSS_PERCENT = 0.75;
    private const DEFAULT_FRICTION_MARGIN_PERCENT = 0.15;

    public function __construct(
        private readonly NobitexOrderService $orders = new NobitexOrderService(),
        private readonly NobitexUniverseScanner $scanner = new NobitexUniverseScanner(),
    ) {}

    public function attempt(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();

        if (!NobitexSchema::botEnabled('nobitex')) {
            return $this->result('no_rotation', 'bot_disabled');
        }
        if ($this->boolSetting($pdo, 'kill_switch', false)) {
            return $this->result('no_rotation', 'kill_switch');
        }
        if (!$this->orders->credentialsConfigured() || !$this->orders->liveEnabled()) {
            return $this->result('no_rotation', 'live_execution_unavailable');
        }

        if ((int)$pdo->query("SELECT GET_LOCK('trade_nobitex_portfolio_v1',0)")->fetchColumn() !== 1) {
            return $this->result('no_rotation', 'another_nobitex_run_is_active');
        }

        try {
            return $this->attemptLocked($pdo);
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('trade_nobitex_portfolio_v1')"); } catch (\Throwable) {}
        }
    }

    private function attemptLocked(PDO $pdo): array
    {
        $positions = $pdo->query(
            "SELECT * FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close')
             ORDER BY id ASC LIMIT 30"
        )->fetchAll();

        $maxPositions = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        $activeCount = count($positions);
        $overLimit = $activeCount > $maxPositions;
        if ($activeCount < $maxPositions) {
            return $this->result('no_rotation', 'portfolio_has_free_slot', [
                'active_positions'=>$activeCount,
                'max_positions'=>$maxPositions,
            ]);
        }

        // Capacity alignment is a hard portfolio invariant. It must still run
        // when optional opportunity rotation is disabled.
        if (!$overLimit && !$this->boolSetting($pdo, 'nobitex_rotation_enabled', true)) {
            return $this->result('no_rotation', 'rotation_disabled');
        }

        // Never stack a new forced SELL on top of an unresolved open/close order.
        // The normal reconciliation path will settle it first, then the next tick
        // can continue reducing any remaining excess positions.
        foreach ($positions as $position) {
            if ((string)($position['status'] ?? '') !== 'open') {
                return $this->result('no_rotation', 'pending_order_present', [
                    'active_positions'=>$activeCount,
                    'max_positions'=>$maxPositions,
                    'excess_positions'=>max(0, $activeCount - $maxPositions),
                ]);
            }
        }

        // A configured rotation cooldown must not delay enforcement after the
        // administrator lowers max positions below the current live count.
        if (!$overLimit) {
            $cooldownMinutes = $this->intSetting(
                $pdo,
                'nobitex_rotation_cooldown_minutes',
                self::DEFAULT_COOLDOWN_MINUTES,
                5,
                240
            );
            $lastRotation = $this->setting($pdo, 'nobitex_last_rotation_at');
            if ($lastRotation !== null && trim($lastRotation) !== '') {
                $lastTs = strtotime($lastRotation . ' UTC');
                if ($lastTs !== false) {
                    $nextTs = $lastTs + ($cooldownMinutes * 60);
                    if (time() < $nextTs) {
                        return $this->result('no_rotation', 'rotation_cooldown_active', [
                            'cooldown_until'=>gmdate(DATE_ATOM, $nextTs),
                        ]);
                    }
                }
            }
        }

        $client = $this->orders->client();
        $wallets = $client->wallets();
        $irt = $this->walletAvailable($wallets, ['RLS','IRT']);
        $usdt = $this->walletAvailable($wallets, ['USDT']);
        $preferredQuote = $irt > 0 ? 'IRT' : ($usdt > 0 ? 'USDT' : 'IRT');

        $candidates = [];
        if (!$overLimit) {
            $candidates = $this->scanner->rankedCandidates($client, $preferredQuote, 20, 60);
            if ($candidates === []) {
                return $this->result('no_rotation', 'no_eligible_markets');
            }
        }

        $enriched = [];
        $marketsBySymbol = [];
        foreach ($positions as $position) {
            try {
                $market = $this->scanner->snapshotSymbol($client, (string)$position['symbol'], 60);
            } catch (\Throwable $e) {
                $this->event($pdo, 'warning', 'nobitex.rotation.position_scan_failed', [
                    'position_id'=>$position['id'] ?? null,
                    'symbol'=>$position['symbol'] ?? null,
                    'error'=>mb_substr($e->getMessage(), 0, 240),
                    'capacity_alignment'=>$overLimit,
                ]);
                continue;
            }

            $signal = is_array($market['signal'] ?? null) ? $market['signal'] : [];
            $symbol = strtoupper((string)$position['symbol']);
            $marketsBySymbol[$symbol] = $market;
            $exitCost = max(0.0, (float)($signal['estimated_exit_cost_percent'] ?? 0.0));
            $entryPrice = max(0.0, (float)($position['entry_price'] ?? 0.0));
            $markPrice = max(0.0, (float)($market['price'] ?? 0.0));
            $grossPnlPercent = $entryPrice > 0.0 && $markPrice > 0.0
                ? (($markPrice - $entryPrice) / $entryPrice) * 100.0
                : 0.0;
            $position['forward_edge_percent'] = $this->signalEdge($signal);
            $position['estimated_exit_cost_percent'] = $exitCost;
            $position['unrealized_net_pnl_percent'] = $grossPnlPercent - $exitCost;
            $enriched[] = $position;
        }

        if ($enriched === []) {
            return $this->result('no_rotation', 'no_position_market_data', [
                'active_positions'=>$activeCount,
                'max_positions'=>$maxPositions,
                'capacity_alignment'=>$overLimit,
            ]);
        }

        $plan = $overLimit
            ? self::capacityReductionPlan($enriched, $maxPositions, $activeCount)
            : $this->plan($enriched, $candidates, [
                'min_advantage_percent'=>$this->floatSetting(
                    $pdo,
                    'nobitex_rotation_min_advantage_percent',
                    self::DEFAULT_MIN_ADVANTAGE_PERCENT,
                    0.25,
                    5.0
                ),
                'min_hold_minutes'=>$this->intSetting(
                    $pdo,
                    'nobitex_rotation_min_hold_minutes',
                    self::DEFAULT_MIN_HOLD_MINUTES,
                    10,
                    1440
                ),
                'max_rotation_loss_percent'=>$this->floatSetting(
                    $pdo,
                    'nobitex_rotation_max_loss_percent',
                    self::DEFAULT_MAX_ROTATION_LOSS_PERCENT,
                    0.10,
                    5.0
                ),
                'friction_margin_percent'=>$this->floatSetting(
                    $pdo,
                    'nobitex_rotation_friction_margin_percent',
                    self::DEFAULT_FRICTION_MARGIN_PERCENT,
                    0.05,
                    1.0
                ),
            ]);

        if (($plan['rotate'] ?? false) !== true) {
            return $this->result('no_rotation', (string)($plan['reason'] ?? 'no_superior_replacement'), [
                'rotation'=>$plan,
            ]);
        }

        $victim = is_array($plan['victim'] ?? null) ? $plan['victim'] : [];
        $candidate = is_array($plan['candidate'] ?? null) ? $plan['candidate'] : [];
        $victimSymbol = strtoupper((string)($victim['symbol'] ?? ''));
        $market = $marketsBySymbol[$victimSymbol] ?? null;
        if (!is_array($market)) {
            return $this->result('no_rotation', 'victim_market_unavailable');
        }

        $availableBase = $this->walletAvailable($wallets, $this->assetAliases((string)($victim['asset'] ?? '')));
        $amount = $this->floorAmount(
            min(max(0.0, (float)($victim['amount'] ?? 0.0)), $availableBase),
            (int)($market['base_precision'] ?? 8)
        );
        if ($amount <= 0) {
            return $this->result('no_rotation', 'victim_balance_unavailable', [
                'victim_symbol'=>$victimSymbol,
            ]);
        }

        $identifier = 'nr' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
        $reference = (float)(($market['best_bid'] ?? 0) > 0 ? $market['best_bid'] : ($market['price'] ?? 0));
        if ($reference <= 0) {
            return $this->result('no_rotation', 'victim_price_unavailable');
        }

        $capacityReduction = $overLimit && (string)($plan['reason'] ?? '') === 'position_limit_reduction';
        $exitReason = $capacityReduction ? 'position_limit_reduction' : 'portfolio_rotation';
        $source = $capacityReduction ? 'autotrade_nobitex_position_limit' : 'autotrade_nobitex_rotation';

        $order = $this->orders->create([
            'symbol'=>$victimSymbol,
            'amount1'=>$amount,
            'price'=>max(0.00000001, $reference * 0.992),
            'mode'=>'market',
            'type'=>'sell',
            'identifier'=>$identifier,
        ], $source);

        $remote = is_array($order['order'] ?? null) ? $order['order'] : [];
        $exchangeId = trim((string)($remote['id'] ?? ''));
        $stmt = $pdo->prepare(
            "UPDATE nobitex_autotrade_positions
             SET status='pending_close',exit_identifier=:identifier,
                 exit_order_local_id=:local,exit_exchange_order_id=:exchange_id,
                 updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='open'"
        );
        $stmt->execute([
            ':identifier'=>$identifier,
            ':local'=>$order['local_id'] ?? null,
            ':exchange_id'=>$exchangeId !== '' ? $exchangeId : null,
            ':id'=>(int)($victim['id'] ?? 0),
        ]);

        if ($stmt->rowCount() !== 1) {
            $this->event($pdo, 'error', 'nobitex.rotation.local_state_conflict', [
                'victim'=>$victim,
                'candidate'=>$candidate,
                'exit_reason'=>$exitReason,
                'order_local_id'=>$order['local_id'] ?? null,
                'exchange_order_id'=>$exchangeId !== '' ? $exchangeId : null,
            ]);
            return $this->result('sell_submitted', 'rotation_state_reconcile_required', [
                'exit_reason'=>$exitReason,
                'rotation'=>$plan,
                'order'=>$order,
            ]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->setSetting($pdo, 'nobitex_last_trade_at', $now);
        if (!$capacityReduction) {
            $this->setSetting($pdo, 'nobitex_last_rotation_at', $now);
        }
        $this->setSetting($pdo, $this->symbolCooldownKey($victimSymbol), $now);
        $this->setSetting($pdo, 'nobitex_rotation_target_symbol', (string)($candidate['symbol'] ?? ''));

        $this->event(
            $pdo,
            'info',
            $capacityReduction ? 'nobitex.position_limit.sell_submitted' : 'nobitex.rotation.sell_submitted',
            [
                'victim'=>$victim,
                'candidate'=>$candidate,
                'exit_reason'=>$exitReason,
                'active_positions_before'=>$activeCount,
                'max_positions'=>$maxPositions,
                'remaining_excess_after_submit'=>max(0, $activeCount - $maxPositions - 1),
                'advantage_percent'=>$plan['advantage_percent'] ?? null,
                'required_advantage_percent'=>$plan['required_advantage_percent'] ?? null,
                'order_local_id'=>$order['local_id'] ?? null,
                'exchange_order_id'=>$exchangeId !== '' ? $exchangeId : null,
            ]
        );

        return $this->result('sell_submitted', $exitReason, [
            'exit_reason'=>$exitReason,
            'position'=>[
                'position_id'=>(int)($victim['id'] ?? 0),
                'local_id'=>$order['local_id'] ?? null,
                'exchange_order_id'=>$exchangeId !== '' ? $exchangeId : null,
                'amount'=>$amount,
            ],
            'active_positions_before'=>$activeCount,
            'max_positions'=>$maxPositions,
            'remaining_excess_after_submit'=>max(0, $activeCount - $maxPositions - 1),
            'rotation'=>$plan,
        ]);
    }

    /**
     * Pure capacity planner. When the live count exceeds the configured limit,
     * it selects one open position for reduction. The weakest forward edge is
     * removed first; if edges tie, the position with the better current net PnL
     * is preferred to avoid realizing a larger loss, then the older position.
     */
    public static function capacityReductionPlan(array $positions, int $maxPositions, ?int $activeCountOverride = null): array
    {
        $maxPositions = max(1, $maxPositions);
        $open = array_values(array_filter(
            $positions,
            static fn(array $position): bool => (string)($position['status'] ?? '') === 'open'
        ));
        $activeCount = max(count($open), $activeCountOverride ?? count($open));
        if ($activeCount <= $maxPositions) {
            return [
                'rotate'=>false,
                'reason'=>'position_limit_satisfied',
                'active_positions'=>$activeCount,
                'max_positions'=>$maxPositions,
                'excess_positions'=>0,
            ];
        }
        if ($open === []) {
            return [
                'rotate'=>false,
                'reason'=>'no_scannable_position_for_limit_reduction',
                'active_positions'=>$activeCount,
                'max_positions'=>$maxPositions,
                'excess_positions'=>$activeCount - $maxPositions,
            ];
        }

        usort($open, static function(array $a, array $b): int {
            $edgeA = is_numeric($a['forward_edge_percent'] ?? null) ? (float)$a['forward_edge_percent'] : 0.0;
            $edgeB = is_numeric($b['forward_edge_percent'] ?? null) ? (float)$b['forward_edge_percent'] : 0.0;
            if (abs($edgeA - $edgeB) > 0.000001) return $edgeA <=> $edgeB;

            $pnlA = is_numeric($a['unrealized_net_pnl_percent'] ?? null) ? (float)$a['unrealized_net_pnl_percent'] : 0.0;
            $pnlB = is_numeric($b['unrealized_net_pnl_percent'] ?? null) ? (float)$b['unrealized_net_pnl_percent'] : 0.0;
            if (abs($pnlA - $pnlB) > 0.000001) return $pnlB <=> $pnlA;

            $openedA = trim((string)($a['opened_at'] ?? ''));
            $openedB = trim((string)($b['opened_at'] ?? ''));
            $tsA = $openedA !== '' ? strtotime($openedA . ' UTC') : false;
            $tsB = $openedB !== '' ? strtotime($openedB . ' UTC') : false;
            $sortA = $tsA === false ? PHP_INT_MAX : $tsA;
            $sortB = $tsB === false ? PHP_INT_MAX : $tsB;
            if ($sortA !== $sortB) return $sortA <=> $sortB;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        $position = $open[0];
        $victimEdge = is_numeric($position['forward_edge_percent'] ?? null)
            ? (float)$position['forward_edge_percent']
            : 0.0;
        $netPnl = is_numeric($position['unrealized_net_pnl_percent'] ?? null)
            ? (float)$position['unrealized_net_pnl_percent']
            : 0.0;
        $exitCost = max(0.0, (float)($position['estimated_exit_cost_percent'] ?? 0.0));

        return [
            'rotate'=>true,
            'reason'=>'position_limit_reduction',
            'victim'=>[
                'id'=>(int)($position['id'] ?? 0),
                'symbol'=>strtoupper((string)($position['symbol'] ?? '')),
                'asset'=>strtoupper((string)($position['asset'] ?? '')),
                'quote_asset'=>strtoupper((string)($position['quote_asset'] ?? '')),
                'amount'=>(float)($position['amount'] ?? 0.0),
                'forward_edge_percent'=>round($victimEdge, 4),
                'unrealized_net_pnl_percent'=>round($netPnl, 4),
                'estimated_exit_cost_percent'=>round($exitCost, 4),
                'opened_at'=>trim((string)($position['opened_at'] ?? '')),
            ],
            'candidate'=>[],
            'active_positions'=>$activeCount,
            'max_positions'=>$maxPositions,
            'excess_positions'=>$activeCount - $maxPositions,
            'selection_policy'=>[
                'primary'=>'lowest_forward_edge',
                'tie_breaker'=>'highest_unrealized_net_pnl_then_oldest',
                'one_exit_at_a_time'=>true,
            ],
        ];
    }

    /**
     * Pure planner used by both production execution and regression tests.
     * It never submits an order.
     */
    public function plan(array $positions, array $candidates, array $config = [], ?int $now = null): array
    {
        $now ??= time();
        $minAdvantage = $this->boundedNumber(
            $config['min_advantage_percent'] ?? self::DEFAULT_MIN_ADVANTAGE_PERCENT,
            0.25,
            5.0,
            self::DEFAULT_MIN_ADVANTAGE_PERCENT
        );
        $minHoldMinutes = (int)$this->boundedNumber(
            $config['min_hold_minutes'] ?? self::DEFAULT_MIN_HOLD_MINUTES,
            10,
            1440,
            self::DEFAULT_MIN_HOLD_MINUTES
        );
        $maxLoss = $this->boundedNumber(
            $config['max_rotation_loss_percent'] ?? self::DEFAULT_MAX_ROTATION_LOSS_PERCENT,
            0.10,
            5.0,
            self::DEFAULT_MAX_ROTATION_LOSS_PERCENT
        );
        $frictionMargin = $this->boundedNumber(
            $config['friction_margin_percent'] ?? self::DEFAULT_FRICTION_MARGIN_PERCENT,
            0.05,
            1.0,
            self::DEFAULT_FRICTION_MARGIN_PERCENT
        );

        $activeSymbols = [];
        $activeAssets = [];
        foreach ($positions as $position) {
            if ((string)($position['status'] ?? '') !== 'open') continue;
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            $asset = strtoupper((string)($position['asset'] ?? ''));
            if ($symbol !== '') $activeSymbols[$symbol] = true;
            if ($asset !== '') $activeAssets[$asset] = true;
        }

        $bestByQuote = [];
        foreach ($candidates as $candidate) {
            $signal = is_array($candidate['signal'] ?? null) ? $candidate['signal'] : $candidate;
            if (($signal['ready'] ?? false) !== true || (string)($signal['action'] ?? '') !== 'buy') continue;

            $symbol = strtoupper((string)($candidate['symbol'] ?? ''));
            $asset = strtoupper((string)($candidate['asset'] ?? ''));
            $quote = strtoupper((string)($candidate['quote_asset'] ?? ''));
            $edge = $this->signalEdge($signal);
            if ($symbol === '' || $asset === '' || !in_array($quote, ['IRT','USDT'], true) || $edge <= 0.0) continue;
            if (isset($activeSymbols[$symbol]) || isset($activeAssets[$asset])) continue;

            $normalized = [
                'symbol'=>$symbol,
                'asset'=>$asset,
                'quote_asset'=>$quote,
                'tradable_net_edge_percent'=>round($edge, 4),
                'execution_quality_score'=>$signal['execution_quality_score'] ?? null,
            ];
            if (!isset($bestByQuote[$quote]) || $edge > (float)$bestByQuote[$quote]['tradable_net_edge_percent']) {
                $bestByQuote[$quote] = $normalized;
            }
        }

        if ($bestByQuote === []) {
            return ['rotate'=>false,'reason'=>'no_profitable_replacement_candidate'];
        }

        $bestPlan = null;
        $eligibleVictims = 0;
        foreach ($positions as $position) {
            if ((string)($position['status'] ?? '') !== 'open') continue;
            $quote = strtoupper((string)($position['quote_asset'] ?? ''));
            if (!isset($bestByQuote[$quote])) continue;

            $openedAt = trim((string)($position['opened_at'] ?? ''));
            $openedTs = $openedAt !== '' ? strtotime($openedAt . ' UTC') : false;
            if ($openedTs === false || $now - $openedTs < $minHoldMinutes * 60) continue;

            $netPnl = is_numeric($position['unrealized_net_pnl_percent'] ?? null)
                ? (float)$position['unrealized_net_pnl_percent']
                : 0.0;
            if ($netPnl < -$maxLoss) continue;

            $eligibleVictims++;
            $victimEdge = is_numeric($position['forward_edge_percent'] ?? null)
                ? (float)$position['forward_edge_percent']
                : 0.0;
            $exitCost = max(0.0, (float)($position['estimated_exit_cost_percent'] ?? 0.0));
            $candidate = $bestByQuote[$quote];
            $candidateEdge = (float)$candidate['tradable_net_edge_percent'];
            $advantage = $candidateEdge - $victimEdge;
            $required = max($minAdvantage, $exitCost + $frictionMargin);
            if ($advantage + 0.000001 < $required) continue;

            $surplus = $advantage - $required;
            $plan = [
                'rotate'=>true,
                'reason'=>'superior_opportunity_after_rotation_costs',
                'victim'=>[
                    'id'=>(int)($position['id'] ?? 0),
                    'symbol'=>strtoupper((string)($position['symbol'] ?? '')),
                    'asset'=>strtoupper((string)($position['asset'] ?? '')),
                    'quote_asset'=>$quote,
                    'amount'=>(float)($position['amount'] ?? 0.0),
                    'forward_edge_percent'=>round($victimEdge, 4),
                    'unrealized_net_pnl_percent'=>round($netPnl, 4),
                    'estimated_exit_cost_percent'=>round($exitCost, 4),
                    'opened_at'=>$openedAt,
                ],
                'candidate'=>$candidate,
                'advantage_percent'=>round($advantage, 4),
                'required_advantage_percent'=>round($required, 4),
                'advantage_surplus_percent'=>round($surplus, 4),
                'guards'=>[
                    'min_hold_minutes'=>$minHoldMinutes,
                    'max_rotation_loss_percent'=>$maxLoss,
                    'min_advantage_percent'=>$minAdvantage,
                    'friction_margin_percent'=>$frictionMargin,
                    'same_quote_required'=>true,
                ],
            ];

            if ($bestPlan === null
                || $surplus > (float)$bestPlan['advantage_surplus_percent'] + 0.000001
                || (abs($surplus - (float)$bestPlan['advantage_surplus_percent']) <= 0.000001
                    && $victimEdge < (float)$bestPlan['victim']['forward_edge_percent'])) {
                $bestPlan = $plan;
            }
        }

        if ($bestPlan !== null) return $bestPlan;
        if ($eligibleVictims === 0) {
            return ['rotate'=>false,'reason'=>'no_rotation_eligible_position'];
        }
        return ['rotate'=>false,'reason'=>'replacement_advantage_insufficient'];
    }

    private function signalEdge(array $signal): float
    {
        foreach (['tradable_net_edge_percent','expected_net_edge_percent'] as $key) {
            if (!is_numeric($signal[$key] ?? null)) continue;
            $value = (float)$signal[$key];
            if (is_finite($value)) return $value;
        }
        return 0.0;
    }

    private function assetAliases(string $asset): array
    {
        $asset = strtoupper(trim($asset));
        if (in_array($asset, ['TON','GRAM','TONCOIN'], true)) return ['TON','GRAM','TONCOIN'];
        return $asset === '' ? [] : [$asset];
    }

    private function walletAvailable(array $response, array $assets): float
    {
        $assets = array_map('strtoupper', $assets);
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        if (!is_array($rows)) return 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset, $assets, true)) continue;
            foreach (['activeBalance','available','free','balance'] as $key) {
                if (array_key_exists($key, $row)) return max(0.0, $this->number($row[$key]));
            }
        }
        return 0.0;
    }

    private function symbolCooldownKey(string $symbol): string
    {
        return 'nobitex_last_trade_' . substr(sha1(strtoupper($symbol)), 0, 16);
    }

    private function floorAmount(float $amount, int $precision): float
    {
        $precision = max(0, min(18, $precision));
        $factor = 10 ** $precision;
        return floor($amount * $factor) / $factor;
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
        if ($value === null || !is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }

    private function floatSetting(PDO $pdo, string $key, float $default, float $min, float $max): float
    {
        $value = $this->setting($pdo, $key);
        if ($value === null || !is_numeric($value)) return $default;
        return max($min, min($max, (float)$value));
    }

    private function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':key'=>$key, ':value'=>$value]);
    }

    private function boundedNumber(mixed $value, float $min, float $max, float $default): float
    {
        if (!is_numeric($value)) return $default;
        $number = (float)$value;
        if (!is_finite($number)) return $default;
        return max($min, min($max, $number));
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float)$value;
        return is_finite($number) ? $number : 0.0;
    }

    private function event(PDO $pdo, string $level, string $event, array $context = []): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:level,:event,:context,UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':level'=>$level,
            ':event'=>$event,
            ':context'=>$context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function result(string $status, string $reason, array $extra = []): array
    {
        return [
            'status'=>$status,
            'exchange'=>'nobitex',
            'reason'=>$reason,
            'rotation_engine'=>'guarded_opportunity_replacement_v2',
            'time_utc'=>gmdate(DATE_ATOM),
        ] + $extra;
    }
}
