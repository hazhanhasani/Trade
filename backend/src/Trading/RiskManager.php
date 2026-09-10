<?php

declare(strict_types=1);

namespace Trade\Trading;

final class RiskManager
{
    private const STRATEGY_MIN_HOLD_SECONDS = 180;
    private const STRATEGY_NOISE_GUARD_PERCENT = 0.35;

    public function normalizeSettings(array $settings): array
    {
        $profile = strtolower((string) ($settings['risk_profile'] ?? 'balanced'));
        if (!in_array($profile, ['safe', 'balanced', 'aggressive'], true)) {
            $profile = 'balanced';
        }

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
        } else {
            $position = min($position, 7.5);
            $maxPosition = min($maxPosition, 12.0);
            $daily = min($daily, 5.0);
            $score = max(58, $score);
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
        if ($positionPercent > (float) $settings['max_position_percent'] + 0.0001) {
            return ['allowed'=>false,'reason'=>'max_position_percent_exceeded'];
        }

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

    public function stopLoss(float $entry, float $percent): float
    {
        return $entry * (1.0 - ($percent / 100.0));
    }

    public function takeProfit(float $entry, float $percent): float
    {
        return $entry * (1.0 + ($percent / 100.0));
    }

    public function exitReason(float $price, array $position, string $signalAction): ?string
    {
        $stop = (float) ($position['stop_loss'] ?? 0);
        $take = (float) ($position['take_profit'] ?? 0);
        if ($stop > 0 && $price <= $stop) return 'stop_loss';
        if ($take > 0 && $price >= $take) return 'take_profit';

        if ($signalAction === 'sell') {
            $openedAt = trim((string) ($position['opened_at'] ?? ''));
            if ($openedAt !== '') {
                $opened = strtotime($openedAt . ' UTC');
                if ($opened !== false && time() - $opened < self::STRATEGY_MIN_HOLD_SECONDS) return null;
            }

            $entry = (float) ($position['entry_price'] ?? 0);
            if ($entry > 0 && $price < $entry) {
                $drawdownPercent = (($price - $entry) / $entry) * 100.0;
                // Ignore tiny negative flips that are commonly just spread/fee noise.
                // A genuine hard loss is still handled immediately by stop-loss.
                if ($drawdownPercent > -self::STRATEGY_NOISE_GUARD_PERCENT) return null;
            }
            return 'strategy_sell';
        }
        return null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
