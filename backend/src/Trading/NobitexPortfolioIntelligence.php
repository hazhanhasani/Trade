<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Portfolio Intelligence v2 for Nobitex live auto-trading.
 *
 * This layer never enlarges the configured risk budget. It can only keep the
 * normal size or reduce it after realized drawdown/strategy evidence. Before an
 * automated BUY reaches Nobitex it also rejects duplicate-asset exposure,
 * strongly underperforming strategy profiles and over-correlated portfolios.
 * SELL orders are deliberately outside this guard so exits are never delayed.
 */
final class NobitexPortfolioIntelligence
{
    public const MODEL = 'portfolio_intelligence_v2';
    public const CURRENT_STRATEGY = 'net_edge_after_execution_quality_and_adaptive_forecast_buffer_v5';

    private static float $runtimePositionMultiplier = 1.0;
    private static array $runtimeSnapshot = [];
    private static bool $storageEnsured = false;

    public function activateRuntimePolicy(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureStorage($pdo);
        $snapshot = $this->snapshot($pdo);
        self::$runtimePositionMultiplier = max(0.50, min(1.0, (float)($snapshot['position_size_multiplier'] ?? 1.0)));
        self::$runtimeSnapshot = $snapshot;
        return $snapshot;
    }

    public static function clearRuntimePolicy(): void
    {
        self::$runtimePositionMultiplier = 1.0;
        self::$runtimeSnapshot = [];
    }

    public static function runtimePositionMultiplier(): float
    {
        return max(0.50, min(1.0, self::$runtimePositionMultiplier));
    }

    public static function runtimeSnapshot(): array
    {
        return self::$runtimeSnapshot;
    }

    public function snapshot(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureStorage($pdo);

        $returns = $this->recentRealizedReturns($pdo, 60);
        $drawdown = self::drawdownPolicyFromReturns(array_column($returns, 'return_percent'));
        $strategies = $this->strategyPerformance($pdo, 160);
        $strategyMultiplier = $this->currentStrategyMultiplier($strategies);
        $positionMultiplier = max(0.50, min(1.0, $drawdown['size_multiplier'] * $strategyMultiplier));

        $active = $pdo->query(
            "SELECT symbol,asset,quote_asset,status,opened_at FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 20"
        )->fetchAll();

        $quoteCounts = ['IRT'=>0,'USDT'=>0];
        foreach ($active as $position) {
            $quote = strtoupper((string)($position['quote_asset'] ?? ''));
            if (isset($quoteCounts[$quote])) $quoteCounts[$quote]++;
        }

        return [
            'model'=>self::MODEL,
            'strategy_model'=>self::CURRENT_STRATEGY,
            'learning_mode'=>'realized_fee_aware_only',
            'position_size_multiplier'=>round($positionMultiplier, 4),
            'drawdown'=>$drawdown,
            'strategy_multiplier'=>round($strategyMultiplier, 4),
            'strategy_performance'=>$strategies,
            'active_positions'=>count($active),
            'active_by_quote'=>$quoteCounts,
            'correlation_guard'=>[
                'threshold'=>round($this->settingFloat($pdo, 'nobitex_intelligence_correlation_threshold', 0.86, 0.50, 0.99), 4),
                'max_correlated_positions'=>$this->settingInt($pdo, 'nobitex_intelligence_max_correlated_positions', 2, 1, 6),
                'minimum_samples'=>$this->settingInt($pdo, 'nobitex_intelligence_correlation_min_samples', 12, 6, 60),
                'lookback_minutes'=>$this->settingInt($pdo, 'nobitex_intelligence_correlation_lookback_minutes', 180, 30, 1440),
            ],
            'realized_samples'=>count($returns),
            'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    /**
     * Called immediately before an automated BUY. This is intentionally a hard
     * server-side guard because the Android/UI layer must not be able to bypass
     * diversification and learned-strategy safety rules.
     */
    public function assessAutomatedBuy(PDO $pdo, string $symbol): array
    {
        $this->ensureStorage($pdo);
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        if ($symbol === '') return ['allowed'=>false,'reason'=>'intelligence_symbol_invalid'];

        $active = $pdo->query(
            "SELECT symbol,asset,quote_asset FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 20"
        )->fetchAll();

        $candidateAsset = $this->canonicalAssetFromSymbol($symbol);
        foreach ($active as $position) {
            $activeAsset = $this->canonicalAsset((string)($position['asset'] ?? $this->canonicalAssetFromSymbol((string)($position['symbol'] ?? ''))));
            if ($candidateAsset !== '' && $activeAsset === $candidateAsset) {
                return ['allowed'=>false,'reason'=>'intelligence_duplicate_asset','asset'=>$candidateAsset];
            }
        }

        $signal = $this->latestSignalDetails($pdo, $symbol);
        $strategyKey = (string)($signal['decision_model'] ?? self::CURRENT_STRATEGY);
        $profileKey = $this->profileKey($signal);
        $snapshot = self::$runtimeSnapshot !== [] ? self::$runtimeSnapshot : $this->snapshot($pdo);
        $profileStats = $this->findStrategyStats((array)($snapshot['strategy_performance'] ?? []), $strategyKey, $profileKey);

        // A profile is never punished from a tiny sample. Only a persistent,
        // materially negative profile after at least eight completed positions is
        // blocked. Moderate underperformance only reduces the global next-entry
        // size through the runtime multiplier.
        if (
            $profileStats !== null
            && (int)($profileStats['trades'] ?? 0) >= 8
            && (float)($profileStats['size_multiplier'] ?? 1.0) <= 0.65
            && (float)($profileStats['average_return_percent'] ?? 0.0) < -0.10
        ) {
            return [
                'allowed'=>false,
                'reason'=>'strategy_profile_underperforming',
                'strategy_key'=>$strategyKey,
                'profile_key'=>$profileKey,
                'profile_stats'=>$profileStats,
            ];
        }

        $correlation = $this->candidateCorrelation($pdo, $symbol, $active);
        if (!($correlation['allowed'] ?? false)) {
            return [
                'allowed'=>false,
                'reason'=>$correlation['reason'] ?? 'portfolio_correlation_cluster_limit',
                'strategy_key'=>$strategyKey,
                'profile_key'=>$profileKey,
                'correlation'=>$correlation,
            ];
        }

        return [
            'allowed'=>true,
            'reason'=>'portfolio_intelligence_passed',
            'strategy_key'=>$strategyKey,
            'profile_key'=>$profileKey,
            'runtime_position_multiplier'=>self::runtimePositionMultiplier(),
            'profile_stats'=>$profileStats,
            'correlation'=>$correlation,
        ];
    }

    /** Record the exact signal model/profile that generated an accepted BUY. */
    public function recordEntryFromAction(PDO $pdo, array $action): array
    {
        $this->ensureStorage($pdo);
        if ((string)($action['status'] ?? '') !== 'buy_submitted') return ['status'=>'ignored'];
        $localId = trim((string)($action['order']['local_id'] ?? ''));
        $symbol = strtoupper(trim((string)($action['selected']['symbol'] ?? '')));
        if ($localId === '' || $symbol === '') return ['status'=>'missing_entry_reference'];

        $signal = $this->latestSignalDetailsByOrder($pdo, $localId, $symbol);
        $strategyKey = substr((string)($signal['decision_model'] ?? self::CURRENT_STRATEGY), 0, 120);
        $profileKey = substr($this->profileKey($signal), 0, 64);
        $quote = str_ends_with($symbol, 'USDT') ? 'USDT' : 'IRT';
        $edge = $this->finiteOrNull($signal['tradable_net_edge_percent'] ?? null);
        $quality = isset($signal['execution_quality_score']) && is_numeric($signal['execution_quality_score'])
            ? max(0, min(100, (int)$signal['execution_quality_score']))
            : null;
        $runtime = self::$runtimeSnapshot;
        $meta = [
            'runtime_position_multiplier'=>self::runtimePositionMultiplier(),
            'drawdown'=>$runtime['drawdown'] ?? null,
            'strategy_multiplier'=>$runtime['strategy_multiplier'] ?? null,
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO nobitex_intelligence_entries
             (local_order_id,symbol,quote_asset,strategy_key,profile_key,entry_edge_percent,execution_quality_score,meta_json,created_at)
             VALUES (:local,:symbol,:quote,:strategy,:profile,:edge,:quality,:meta,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE strategy_key=VALUES(strategy_key),profile_key=VALUES(profile_key),
                entry_edge_percent=VALUES(entry_edge_percent),execution_quality_score=VALUES(execution_quality_score),meta_json=VALUES(meta_json)"
        );
        $stmt->execute([
            ':local'=>$localId,
            ':symbol'=>$symbol,
            ':quote'=>$quote,
            ':strategy'=>$strategyKey,
            ':profile'=>$profileKey,
            ':edge'=>$edge,
            ':quality'=>$quality,
            ':meta'=>json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return ['status'=>'recorded','strategy_key'=>$strategyKey,'profile_key'=>$profileKey];
    }

    /**
     * Pure deterministic drawdown model used both at runtime and in regression
     * tests. Returns are fee-aware percentage returns of completed positions.
     */
    public static function drawdownPolicyFromReturns(array $returns): array
    {
        $clean = [];
        foreach ($returns as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) continue;
            $clean[] = max(-50.0, min(50.0, (float)$value));
        }
        if ($clean === []) {
            return [
                'samples'=>0,'equity_index'=>100.0,'current_drawdown_percent'=>0.0,
                'max_drawdown_percent'=>0.0,'losing_streak'=>0,'average_return_percent'=>0.0,'size_multiplier'=>1.0,
            ];
        }

        $equity = 100.0;
        $peak = 100.0;
        $maxDrawdown = 0.0;
        foreach ($clean as $return) {
            $equity *= max(0.01, 1.0 + ($return / 100.0));
            $peak = max($peak, $equity);
            $drawdown = $peak > 0.0 ? (($peak - $equity) / $peak) * 100.0 : 0.0;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }
        $currentDrawdown = $peak > 0.0 ? (($peak - $equity) / $peak) * 100.0 : 0.0;

        $multiplier = 1.0;
        if ($currentDrawdown >= 8.0) $multiplier = 0.50;
        elseif ($currentDrawdown >= 6.0) $multiplier = 0.65;
        elseif ($currentDrawdown >= 4.0) $multiplier = 0.78;
        elseif ($currentDrawdown >= 2.0) $multiplier = 0.90;

        $losingStreak = 0;
        for ($i = count($clean) - 1; $i >= 0; $i--) {
            if ($clean[$i] < 0.0) $losingStreak++; else break;
        }
        if ($losingStreak >= 5) $multiplier = min($multiplier, 0.60);
        elseif ($losingStreak >= 3) $multiplier = min($multiplier, 0.80);

        return [
            'samples'=>count($clean),
            'equity_index'=>round($equity, 4),
            'current_drawdown_percent'=>round($currentDrawdown, 4),
            'max_drawdown_percent'=>round($maxDrawdown, 4),
            'losing_streak'=>$losingStreak,
            'average_return_percent'=>round(array_sum($clean) / count($clean), 4),
            'size_multiplier'=>round(max(0.50, min(1.0, $multiplier)), 4),
        ];
    }

    public static function strategyMultiplierFromStats(int $trades, float $winRate, float $averageReturn, float $returnProfitFactor): float
    {
        if ($trades < 5) return 1.0;
        $multiplier = 1.0;
        if ($averageReturn < 0.0) $multiplier -= min(0.22, abs($averageReturn) * 0.12);
        if ($returnProfitFactor < 1.0) $multiplier -= min(0.20, (1.0 - max(0.0, $returnProfitFactor)) * 0.25);
        if ($winRate < 0.45) $multiplier -= min(0.15, (0.45 - max(0.0, $winRate)) * 0.60);
        return round(max(0.60, min(1.0, $multiplier)), 4);
    }

    public static function pearson(array $left, array $right): ?float
    {
        $n = min(count($left), count($right));
        if ($n < 2) return null;
        $x = array_slice(array_values($left), -$n);
        $y = array_slice(array_values($right), -$n);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $num = 0.0; $dx = 0.0; $dy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = (float)$x[$i] - $meanX;
            $b = (float)$y[$i] - $meanY;
            $num += $a * $b;
            $dx += $a * $a;
            $dy += $b * $b;
        }
        if ($dx <= 0.0 || $dy <= 0.0) return null;
        return max(-1.0, min(1.0, $num / sqrt($dx * $dy)));
    }

    private function ensureStorage(PDO $pdo): void
    {
        if (self::$storageEnsured) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nobitex_intelligence_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                local_order_id VARCHAR(64) NOT NULL UNIQUE,
                symbol VARCHAR(40) NOT NULL,
                quote_asset VARCHAR(20) NOT NULL,
                strategy_key VARCHAR(120) NOT NULL,
                profile_key VARCHAR(64) NOT NULL,
                entry_edge_percent DECIMAL(18,8) NULL,
                execution_quality_score SMALLINT NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_nobitex_intel_strategy (strategy_key,profile_key,created_at),
                INDEX idx_nobitex_intel_symbol (symbol,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$storageEnsured = true;
    }

    private function recentRealizedReturns(PDO $pdo, int $limit): array
    {
        $limit = max(10, min(200, $limit));
        $rows = $pdo->query(
            "SELECT p.id,p.quote_asset,
                    SUM(COALESCE(r.net_pnl,r.pnl)) AS net_pnl,
                    SUM((r.entry_price*r.amount)+COALESCE(r.entry_fee_quote,0)) AS cost_basis,
                    MAX(COALESCE(r.accounted_at,r.created_at)) AS realized_at
             FROM nobitex_autotrade_pnl r
             JOIN nobitex_autotrade_positions p ON p.id=r.position_id
             WHERE r.accounted_at IS NOT NULL
             GROUP BY p.id,p.quote_asset
             HAVING cost_basis>0
             ORDER BY realized_at DESC
             LIMIT {$limit}"
        )->fetchAll();
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $basis = (float)($row['cost_basis'] ?? 0.0);
            $net = (float)($row['net_pnl'] ?? 0.0);
            if ($basis <= 0.0 || !is_finite($net)) continue;
            $out[] = [
                'position_id'=>(int)$row['id'],
                'quote_asset'=>(string)$row['quote_asset'],
                'return_percent'=>($net / $basis) * 100.0,
                'realized_at'=>(string)$row['realized_at'],
            ];
        }
        return $out;
    }

    private function strategyPerformance(PDO $pdo, int $limit): array
    {
        $limit = max(20, min(300, $limit));
        $rows = $pdo->query(
            "SELECT e.strategy_key,e.profile_key,p.id,
                    SUM(COALESCE(r.net_pnl,r.pnl)) AS net_pnl,
                    SUM((r.entry_price*r.amount)+COALESCE(r.entry_fee_quote,0)) AS cost_basis,
                    MAX(COALESCE(r.accounted_at,r.created_at)) AS realized_at
             FROM nobitex_intelligence_entries e
             JOIN nobitex_autotrade_positions p ON p.entry_order_local_id=e.local_order_id
             JOIN nobitex_autotrade_pnl r ON r.position_id=p.id
             WHERE r.accounted_at IS NOT NULL
             GROUP BY e.strategy_key,e.profile_key,p.id
             HAVING cost_basis>0
             ORDER BY realized_at DESC
             LIMIT {$limit}"
        )->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $basis = (float)($row['cost_basis'] ?? 0.0);
            if ($basis <= 0.0) continue;
            $return = ((float)($row['net_pnl'] ?? 0.0) / $basis) * 100.0;
            $key = (string)$row['strategy_key'].'|'.(string)$row['profile_key'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'strategy_key'=>(string)$row['strategy_key'],
                    'profile_key'=>(string)$row['profile_key'],
                    'returns'=>[],
                ];
            }
            $groups[$key]['returns'][] = $return;
        }

        $out = [];
        foreach ($groups as $group) {
            $values = $group['returns'];
            $trades = count($values);
            $wins = count(array_filter($values, static fn(float $v): bool => $v > 0.0));
            $positive = array_sum(array_filter($values, static fn(float $v): bool => $v > 0.0));
            $negative = abs(array_sum(array_filter($values, static fn(float $v): bool => $v < 0.0)));
            $profitFactor = $negative > 0.00000001 ? $positive / $negative : ($positive > 0.0 ? 9.99 : 1.0);
            $winRate = $trades > 0 ? $wins / $trades : 0.0;
            $average = $trades > 0 ? array_sum($values) / $trades : 0.0;
            $out[] = [
                'strategy_key'=>$group['strategy_key'],
                'profile_key'=>$group['profile_key'],
                'trades'=>$trades,
                'wins'=>$wins,
                'win_rate'=>round($winRate, 4),
                'average_return_percent'=>round($average, 4),
                'return_profit_factor'=>round(min(9.99, $profitFactor), 4),
                'size_multiplier'=>self::strategyMultiplierFromStats($trades, $winRate, $average, $profitFactor),
                'learning_ready'=>$trades >= 5,
            ];
        }
        usort($out, static fn(array $a, array $b): int => ($b['trades'] <=> $a['trades']));
        return $out;
    }

    private function currentStrategyMultiplier(array $strategies): float
    {
        $weighted = 0.0; $weight = 0;
        foreach ($strategies as $stats) {
            if ((string)($stats['strategy_key'] ?? '') !== self::CURRENT_STRATEGY) continue;
            $trades = (int)($stats['trades'] ?? 0);
            if ($trades < 5) continue;
            $weighted += ((float)($stats['size_multiplier'] ?? 1.0)) * $trades;
            $weight += $trades;
        }
        return $weight > 0 ? max(0.60, min(1.0, $weighted / $weight)) : 1.0;
    }

    private function findStrategyStats(array $strategies, string $strategyKey, string $profileKey): ?array
    {
        foreach ($strategies as $stats) {
            if ((string)($stats['strategy_key'] ?? '') === $strategyKey && (string)($stats['profile_key'] ?? '') === $profileKey) return $stats;
        }
        return null;
    }

    private function profileKey(array $signal): string
    {
        $quality = is_array($signal['execution_quality'] ?? null) ? $signal['execution_quality'] : [];
        $positiveFrames = (int)($quality['positive_timeframe_count'] ?? 0);
        $imbalance = (float)($quality['orderbook_imbalance'] ?? $signal['indicators']['orderbook_imbalance'] ?? 0.0);
        $edge = (float)($signal['tradable_net_edge_percent'] ?? 0.0);
        if ($positiveFrames >= 3) return 'mtf_aligned';
        if ($imbalance >= 0.25) return 'flow_supported';
        if ($edge >= 0.75) return 'high_edge_mixed';
        return 'mixed_edge';
    }

    private function latestSignalDetails(PDO $pdo, string $symbol): array
    {
        $stmt = $pdo->prepare('SELECT details_json FROM nobitex_autotrade_signals WHERE symbol=:symbol ORDER BY id DESC LIMIT 1');
        $stmt->execute([':symbol'=>$symbol]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function latestSignalDetailsByOrder(PDO $pdo, string $localId, string $symbol): array
    {
        $stmt = $pdo->prepare('SELECT details_json FROM nobitex_autotrade_signals WHERE order_local_id=:local ORDER BY id DESC LIMIT 1');
        $stmt->execute([':local'=>$localId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        if (is_array($decoded)) return $decoded;
        return $this->latestSignalDetails($pdo, $symbol);
    }

    private function candidateCorrelation(PDO $pdo, string $candidate, array $active): array
    {
        if ($active === []) return ['allowed'=>true,'reason'=>'no_active_positions','correlated_positions'=>[],'max_correlation'=>null];

        $threshold = $this->settingFloat($pdo, 'nobitex_intelligence_correlation_threshold', 0.86, 0.50, 0.99);
        $maxCorrelated = $this->settingInt($pdo, 'nobitex_intelligence_max_correlated_positions', 2, 1, 6);
        $minSamples = $this->settingInt($pdo, 'nobitex_intelligence_correlation_min_samples', 12, 6, 60);
        $lookback = $this->settingInt($pdo, 'nobitex_intelligence_correlation_lookback_minutes', 180, 30, 1440);

        $symbols = [$candidate];
        foreach ($active as $position) {
            $s = strtoupper((string)($position['symbol'] ?? ''));
            if ($s !== '') $symbols[] = $s;
        }
        $symbols = array_values(array_unique($symbols));
        $series = $this->returnSeries($pdo, $symbols, $lookback);
        $candidateSeries = $series[$candidate] ?? [];
        $correlated = [];
        $maxCorrelation = null;

        foreach ($active as $position) {
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            if ($symbol === '' || $symbol === $candidate) continue;
            [$left, $right] = $this->alignedValues($candidateSeries, $series[$symbol] ?? []);
            if (count($left) < $minSamples) continue;
            $corr = self::pearson($left, $right);
            if ($corr === null) continue;
            $maxCorrelation = $maxCorrelation === null ? $corr : max($maxCorrelation, $corr);
            if ($corr >= $threshold) {
                $correlated[] = ['symbol'=>$symbol,'correlation'=>round($corr, 4),'samples'=>count($left)];
            }
        }

        usort($correlated, static fn(array $a, array $b): int => ($b['correlation'] <=> $a['correlation']));
        $allowed = count($correlated) < $maxCorrelated;
        return [
            'allowed'=>$allowed,
            'reason'=>$allowed ? 'correlation_guard_passed' : 'portfolio_correlation_cluster_limit',
            'threshold'=>round($threshold, 4),
            'max_correlated_positions'=>$maxCorrelated,
            'correlated_positions'=>$correlated,
            'max_correlation'=>$maxCorrelation === null ? null : round($maxCorrelation, 4),
            'minimum_samples'=>$minSamples,
            'lookback_minutes'=>$lookback,
        ];
    }

    /** @return array<string,array<string,float>> */
    private function returnSeries(PDO $pdo, array $symbols, int $lookbackMinutes): array
    {
        if ($symbols === []) return [];
        $params = [];
        $holders = [];
        foreach (array_values($symbols) as $i => $symbol) {
            $key = ':s'.$i;
            $holders[] = $key;
            $params[$key] = $symbol;
        }
        $lookbackMinutes = max(30, min(1440, $lookbackMinutes));
        $sql = "SELECT symbol,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS minute_bucket,AVG(price) AS avg_price
                FROM nobitex_autotrade_signals
                WHERE symbol IN (".implode(',', $holders).")
                  AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$lookbackMinutes} MINUTE)
                GROUP BY symbol,minute_bucket ORDER BY minute_bucket ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $prices = [];
        foreach ($stmt->fetchAll() as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            $price = (float)$row['avg_price'];
            if ($price > 0.0) $prices[$symbol][(string)$row['minute_bucket']] = $price;
        }

        $returns = [];
        foreach ($prices as $symbol => $points) {
            ksort($points);
            $previous = null;
            foreach ($points as $bucket => $price) {
                if ($previous !== null && $previous > 0.0) $returns[$symbol][$bucket] = (($price - $previous) / $previous) * 100.0;
                $previous = $price;
            }
        }
        return $returns;
    }

    private function alignedValues(array $left, array $right): array
    {
        $common = array_intersect(array_keys($left), array_keys($right));
        sort($common);
        $a = []; $b = [];
        foreach ($common as $key) {
            $a[] = (float)$left[$key];
            $b[] = (float)$right[$key];
        }
        return [$a, $b];
    }

    private function canonicalAssetFromSymbol(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        foreach (['USDT','IRT'] as $quote) {
            if (str_ends_with($symbol, $quote)) return $this->canonicalAsset(substr($symbol, 0, -strlen($quote)));
        }
        return $this->canonicalAsset($symbol);
    }

    private function canonicalAsset(string $asset): string
    {
        $asset = strtoupper(trim($asset));
        return in_array($asset, ['GRAM','TONCOIN'], true) ? 'TON' : $asset;
    }

    private function settingFloat(PDO $pdo, string $key, float $default, float $min, float $max): float
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        $value = $stmt->fetchColumn();
        $number = is_numeric($value) ? (float)$value : $default;
        return max($min, min($max, $number));
    }

    private function settingInt(PDO $pdo, string $key, int $default, int $min, int $max): int
    {
        return (int)round($this->settingFloat($pdo, $key, (float)$default, (float)$min, (float)$max));
    }

    private function finiteOrNull(mixed $value): ?float
    {
        if (!is_numeric($value) || !is_finite((float)$value)) return null;
        return (float)$value;
    }
}
