<?php

declare(strict_types=1);

namespace Trade\Trading;

final class RiskManager
{
    private const STRATEGY_MIN_HOLD_SECONDS = 180;
    private const STRATEGY_MIN_NET_PROFIT_PERCENT = 0.15;
    private const STRATEGY_REVERSAL_NET_LOSS_PERCENT = 0.75;
    private const STRATEGY_PROFIT_GIVEBACK_ARM_PERCENT = 0.60;
    private const STRATEGY_PROFIT_GIVEBACK_PERCENT = 0.45;
    private const STRATEGY_STALE_RELEASE_SECONDS = 21600;
    private const STRATEGY_STALE_MAX_NET_PERCENT = 0.10;
    private const CAPITAL_RECYCLE_SOFT_SECONDS = 43200;
    private const CAPITAL_RECYCLE_HARD_SECONDS = 86400;
    private const CAPITAL_RECYCLE_SOFT_MIN_NET_PERCENT = 0.05;
    private const CAPITAL_RECYCLE_HARD_MIN_NET_PERCENT = -0.25;
    private const CAPITAL_RECYCLE_MAX_NET_PERCENT = 0.35;
    private const IRT_TAKER_FEE_PERCENT = 0.25;
    private const USDT_TAKER_FEE_PERCENT = 0.13;

    public function normalizeSettings(array $settings): array
    {
        $profile = strtolower((string) ($settings['risk_profile'] ?? 'balanced'));
        if (!in_array($profile, ['safe', 'balanced', 'aggressive'], true)) $profile = 'balanced';

        $position = $this->clamp((float) ($settings['position_percent'] ?? 5), 0.5, 20.0);
        $maxPosition = $this->clamp((float) ($settings['max_position_percent'] ?? 10), 1.0, 25.0);
        $stop = $this->clamp((float) ($settings['stop_loss_percent'] ?? 3), 0.5, 15.0);
        $take = $this->clamp((float) ($settings['take_profit_percent'] ?? 6), 0.5, 50.0);
        $daily = $this->clamp((float) ($settings['daily_loss_limit_percent'] ?? 5), 1.0, 15.0);
        $score = max(35, min(90, (int) ($settings['min_signal_score'] ?? 60)));
        $cooldown = max(1, min(1440, (int) ($settings['cooldown_minutes'] ?? 15)));

        if ($profile === 'safe') {
            $position = min($position, 3.0);
            $maxPosition = min($maxPosition, 6.0);
            $stop = min($stop, 3.0);
            $daily = min($daily, 3.0);
            $score = max($score, 70);
            $cooldown = max($cooldown, 30);
        } elseif ($profile === 'aggressive') {
            $position = min($position, 12.0);
            $maxPosition = min($maxPosition, 20.0);
            $daily = min($daily, 8.0);
            $score = max(50, $score);
            // Fast-cycle execution scans fresh order books roughly every 12s.
            // A legacy 30/45 minute cooldown silently defeats that architecture.
            // Keep a small anti-churn interval while allowing other risk/edge
            // gates to remain authoritative.
            $cooldown = min($cooldown, 3);
        } else {
            $position = min($position, 7.5);
            $maxPosition = min($maxPosition, 12.0);
            $daily = min($daily, 5.0);
            $score = max(58, $score);
            $cooldown = min($cooldown, 3);
        }

        // Both adaptive layers are reduction-only. Portfolio Intelligence learns
        // from realized performance; GlobalRiskRuntime reserves one exposure
        // budget across IRT + USDT. Neither layer can enlarge configured risk.
        $intelligenceMultiplier = NobitexPortfolioIntelligence::runtimePositionMultiplier();
        $globalExposureMultiplier = NobitexGlobalRiskRuntime::runtimePositionMultiplier();
        $combinedMultiplier = max(0.0, min(1.0, min($intelligenceMultiplier, $globalExposureMultiplier)));
        if ($combinedMultiplier < 0.9999) {
            $position = max(0.05, $position * $combinedMultiplier);
        }

        return [
            'risk_profile' => $profile,
            'position_percent' => $position,
            'max_position_percent' => max($position, $maxPosition),
            'stop_loss_percent' => $stop,
            'take_profit_percent' => $take,
            'daily_loss_limit_percent' => $daily,
            'min_signal_score' => $score,
            'cooldown_minutes' => $cooldown,
        ];
    }

    public function calculateBaseAmount(float $quoteAvailable, float $price, float $positionPercent, int $precision): float
    {
        if ($quoteAvailable <= 0 || $price <= 0) return 0.0;
        $quoteToUse = $quoteAvailable * ($positionPercent / 100.0);
        $raw = $quoteToUse / $price;
        $precision = max(0, min(18, $precision));
        $factor = 10 ** $precision;
        return floor($raw * $factor) / $factor;
    }

    public function entryDecision(
        float $portfolioValue,
        float $orderValue,
        float $existingExposureValue,
        float $dailyPnl,
        int $openPositions,
        ?string $lastTradeAt,
        array $settings
    ): array {
        if ($portfolioValue <= 0 || $orderValue <= 0) return ['allowed'=>false,'reason'=>'insufficient_balance'];
        if ($openPositions > 0) return ['allowed'=>false,'reason'=>'position_already_open'];
        $positionPercent = (($existingExposureValue + $orderValue) / $portfolioValue) * 100.0;
        if ($positionPercent > (float) $settings['max_position_percent'] + 0.0001) return ['allowed'=>false,'reason'=>'max_position_percent_exceeded'];
        $maxDailyLoss = $portfolioValue * ((float) $settings['daily_loss_limit_percent'] / 100.0);
        if ($dailyPnl <= -$maxDailyLoss) return ['allowed'=>false,'reason'=>'daily_loss_limit_reached'];
        if ($lastTradeAt) {
            $last = strtotime($lastTradeAt . ' UTC');
            if ($last !== false) {
                $next = $last + ((int) $settings['cooldown_minutes'] * 60);
                if (time() < $next) return ['allowed'=>false,'reason'=>'cooldown_active','cooldown_until'=>gmdate(DATE_ATOM,$next)];
            }
        }
        return ['allowed'=>true,'reason'=>'ok'];
    }

    public function stopLoss(float $entry, float $percent): float { return $entry * (1.0 - ($percent / 100.0)); }
    public function takeProfit(float $entry, float $percent): float { return $entry * (1.0 + ($percent / 100.0)); }

    public function exitReason(float $price, array $position, string $signalAction): ?string
    {
        $entry = (float) ($position['entry_price'] ?? 0);
        $amount = (float) ($position['amount'] ?? 0);
        if ($entry <= 0 || $amount <= 0 || $price <= 0) return null;

        $stop = (float) ($position['stop_loss'] ?? 0);
        $take = (float) ($position['take_profit'] ?? 0);
        $trail = (float) ($position['trailing_stop'] ?? 0);
        $peak = (float) ($position['peak_price'] ?? 0);
        if ($stop > 0 && $price <= $stop) return 'stop_loss';
        if ($trail > 0 && $peak > $entry && $price <= $trail) return 'trailing_profit_lock';

        $entryNotional = $entry * $amount;
        $grossPnl = ($price - $entry) * $amount;
        $fallbackRate = strtoupper((string)($position['quote_asset'] ?? 'IRT')) === 'USDT'
            ? self::USDT_TAKER_FEE_PERCENT / 100.0
            : self::IRT_TAKER_FEE_PERCENT / 100.0;
        $entryFee = isset($position['entry_fee_quote']) && is_numeric($position['entry_fee_quote'])
            ? max(0.0, (float)$position['entry_fee_quote']) : $entryNotional * $fallbackRate;
        $exitFee = isset($position['estimated_exit_fee_quote']) && is_numeric($position['estimated_exit_fee_quote'])
            ? max(0.0, (float)$position['estimated_exit_fee_quote']) : ($price * $amount * $fallbackRate);
        $netPnl = $grossPnl - $entryFee - $exitFee;
        $costBasis = $entryNotional + $entryFee;
        $netMovePercent = $costBasis > 0 ? ($netPnl / $costBasis) * 100.0 : 0.0;

        $configuredStopPercent = $stop > 0 ? (($entry - $stop) / $entry) * 100.0 : 0.0;
        $configuredTakePercent = $take > 0 ? (($take - $entry) / $entry) * 100.0 : 0.0;
        if ($configuredStopPercent > 0 && $netMovePercent <= -$configuredStopPercent) return 'stop_loss_after_fees';
        if ($configuredTakePercent > 0 && $netMovePercent >= $configuredTakePercent) return 'take_profit_after_fees';

        // Capital recycling is independent from a SELL forecast. The previous
        // implementation placed stale-release logic behind the SELL-signal gate,
        // allowing near-flat positions to occupy slots indefinitely. Recycle is
        // deliberately bounded to old, near-flat positions only; it never closes
        // a material loss and never overrides stop/take/trailing safeguards.
        $heldSeconds = 0;
        $openedAt = trim((string) ($position['opened_at'] ?? ''));
        if ($openedAt !== '') {
            $opened = strtotime($openedAt . ' UTC');
            if ($opened !== false) $heldSeconds = max(0, time() - $opened);
        }
        if ($heldSeconds >= self::CAPITAL_RECYCLE_HARD_SECONDS
            && $netMovePercent >= self::CAPITAL_RECYCLE_HARD_MIN_NET_PERCENT
            && $netMovePercent <= self::CAPITAL_RECYCLE_MAX_NET_PERCENT) {
            return 'time_based_capital_recycle';
        }
        if ($heldSeconds >= self::CAPITAL_RECYCLE_SOFT_SECONDS
            && $netMovePercent >= self::CAPITAL_RECYCLE_SOFT_MIN_NET_PERCENT
            && $netMovePercent <= self::CAPITAL_RECYCLE_MAX_NET_PERCENT) {
            return 'time_based_small_profit_recycle';
        }

        if ($signalAction !== 'sell') return null;
        if ($heldSeconds < self::STRATEGY_MIN_HOLD_SECONDS) return null;
        if ($netMovePercent >= self::STRATEGY_MIN_NET_PROFIT_PERCENT) return 'strategy_net_profit_capture';

        $highestNetPercent = isset($position['highest_net_pnl_percent']) && is_numeric($position['highest_net_pnl_percent'])
            ? (float)$position['highest_net_pnl_percent'] : $netMovePercent;
        $giveback = $highestNetPercent - $netMovePercent;
        if ($highestNetPercent >= self::STRATEGY_PROFIT_GIVEBACK_ARM_PERCENT && $giveback >= self::STRATEGY_PROFIT_GIVEBACK_PERCENT && $netMovePercent > -self::STRATEGY_REVERSAL_NET_LOSS_PERCENT) {
            return 'strategy_profit_giveback_exit';
        }
        if ($heldSeconds >= self::STRATEGY_STALE_RELEASE_SECONDS && $netMovePercent <= self::STRATEGY_STALE_MAX_NET_PERCENT && $netMovePercent > -self::STRATEGY_REVERSAL_NET_LOSS_PERCENT) {
            return 'strategy_stale_capital_release';
        }
        if ($netMovePercent <= -self::STRATEGY_REVERSAL_NET_LOSS_PERCENT) return 'strategy_reversal_exit_after_costs';
        return null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
