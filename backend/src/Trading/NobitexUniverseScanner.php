<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

/**
 * Full-universe Nobitex spot scanner.
 *
 * No score, rank threshold or top-N shortlist decides which asset gets
 * analyzed. Every executable IRT/USDT market is evaluated with the same
 * 1m/5m/15m profitability model. Because Nobitex limits OHLC history to 60
 * requests/minute, histories are persisted and refreshed round-robin under a
 * shared request budget; the current order-book price updates every market on
 * every tick, so stale-history scheduling never becomes an eligibility score.
 */
final class NobitexUniverseScanner
{
    private const QUOTES = ['IRT', 'USDT'];
    private const EXCLUDED_BASES = ['IRT','RLS','USDT','USDC','DAI','TUSD','BUSD','FDUSD'];
    private const OHLC_REQUESTS_PER_60_SECONDS = 55; // keep 5 req/min headroom below official 60/min limit
    private const POSITION_HISTORY_REFRESH_SECONDS = 180;

    /** @var array<string,array> */
    private static array $processSnapshotCache = [];
    /** @var array<string,array> */
    private static array $processUniverseCache = [];
    private static bool $cacheSchemaEnsured = false;

    public function __construct(private readonly NobitexInternalSignalEngine $signals = new NobitexInternalSignalEngine()) {}

    public static function resetProcessCache(): void
    {
        self::$processSnapshotCache = [];
        self::$processUniverseCache = [];
    }

    public function rankedCandidates(
        NobitexClient $client,
        string $preferredQuote = 'IRT',
        int $legacyLimit = 20,
        int $legacyThreshold = 60
    ): array {
        unset($legacyLimit, $legacyThreshold);

        $preferredQuote = $this->quote($preferredQuote);
        if (isset(self::$processUniverseCache[$preferredQuote])) {
            return self::$processUniverseCache[$preferredQuote];
        }

        $all = $client->allOrderBooks();
        try { $stats = $client->stats(); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }

        $markets = $this->marketsFromAll($all, $stats, $options);
        $universeSize = count($markets);
        if ($markets === []) {
            self::$processUniverseCache[$preferredQuote] = [];
            return [];
        }

        $pdo = Database::connection();
        $this->ensureCacheSchema($pdo);
        $cache = $this->loadHistoryCache($pdo);

        $refreshQueue = $markets;
        usort($refreshQueue, static function (array $a, array $b) use ($cache): int {
            $aAt = trim((string) ($cache[(string) $a['symbol']]['fetched_at'] ?? ''));
            $bAt = trim((string) ($cache[(string) $b['symbol']]['fetched_at'] ?? ''));
            $aTs = $aAt === '' ? 0 : (strtotime($aAt . ' UTC') ?: 0);
            $bTs = $bAt === '' ? 0 : (strtotime($bAt . ' UTC') ?: 0);
            return $aTs <=> $bTs;
        });

        $grant = $this->reserveOhlcBudget($pdo, $universeSize);
        $refreshSymbols = [];
        foreach (array_slice($refreshQueue, 0, $grant) as $market) {
            $refreshSymbols[] = (string) $market['symbol'];
        }
        $histories = $refreshSymbols !== []
            ? $client->ohlcMany($refreshSymbols, '1', 480, 8)
            : [];

        $now = gmdate('Y-m-d H:i:s');
        $minute = gmdate('Y-m-d H:i:00');
        $upsert = $pdo->prepare(
            "INSERT INTO nobitex_market_history_cache
                (symbol,prices_json,fetched_at,last_observed_minute,updated_at)
             VALUES (:symbol,:prices,:fetched_at,:last_observed_minute,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                prices_json=VALUES(prices_json),
                fetched_at=VALUES(fetched_at),
                last_observed_minute=VALUES(last_observed_minute),
                updated_at=UTC_TIMESTAMP()"
        );

        $analyzed = [];
        foreach ($markets as $market) {
            $symbol = (string) $market['symbol'];
            $cached = is_array($cache[$symbol] ?? null) ? $cache[$symbol] : [];
            $cachedPrices = $this->cachedPrices($cached);
            $fetchedAt = trim((string) ($cached['fetched_at'] ?? '')) ?: null;

            $history = is_array($histories[$symbol] ?? null) ? $histories[$symbol] : [];
            $historyPrices = $this->minutePricesFromResponse($history, 0.0);
            if ($historyPrices !== []) {
                $prices = $historyPrices;
                $fetchedAt = $now;
            } else {
                $prices = $cachedPrices;
            }

            $prices = $this->mergeLivePrice(
                $prices,
                (float) $market['price'],
                trim((string) ($cached['last_observed_minute'] ?? '')),
                $minute
            );

            $upsert->execute([
                ':symbol'=>$symbol,
                ':prices'=>json_encode($prices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':fetched_at'=>$fetchedAt,
                ':last_observed_minute'=>$minute,
            ]);

            $market['prices'] = $prices;
            $market['analysis_resolution'] = '1m';
            $market['universe_size'] = $universeSize;
            $market['deep_scan_size'] = $universeSize;
            $market['full_universe_analysis'] = true;
            $market['history_refreshed_this_tick'] = $historyPrices !== [];
            $market['history_fetched_at'] = $fetchedAt;
            $market['ohlc_budget_granted'] = $grant;

            $signal = $this->signals->analyze($market, $prices);
            $market['signal'] = $signal;
            $market['expected_gross_move_percent'] = round((float) ($signal['expected_gross_move_percent'] ?? 0.0), 4);
            $market['estimated_roundtrip_cost_percent'] = round((float) ($signal['estimated_roundtrip_cost_percent'] ?? 0.0), 4);
            $market['expected_net_edge_percent'] = round((float) ($signal['expected_net_edge_percent'] ?? 0.0), 4);
            $market['expected_net_profit'] = (bool) ($signal['expected_net_profit'] ?? false);

            $analyzed[] = $market;
            self::$processSnapshotCache[$symbol] = $market;
        }

        usort($analyzed, static function (array $a, array $b) use ($preferredQuote): int {
            // The caller selects preferredQuote from the wallet that can actually
            // fund entries. Keep that quote first so an unfunded USDT BUY cannot
            // dominate an IRT-funded portfolio's candidate list or diagnostics.
            $aPreferred = (($a['quote_asset'] ?? '') === $preferredQuote) ? 1 : 0;
            $bPreferred = (($b['quote_asset'] ?? '') === $preferredQuote) ? 1 : 0;
            if ($aPreferred !== $bPreferred) return $bPreferred <=> $aPreferred;

            $aBuy = (($a['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            $bBuy = (($b['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            if ($aBuy !== $bBuy) return $bBuy <=> $aBuy;

            $aReady = (bool) ($a['signal']['ready'] ?? false);
            $bReady = (bool) ($b['signal']['ready'] ?? false);
            if ($aReady !== $bReady) return $bReady <=> $aReady;

            // Rank the actually tradable post-cost edge, not merely the raw edge.
            $aEdge = (float) ($a['signal']['tradable_net_edge_percent'] ?? $a['expected_net_edge_percent'] ?? -999.0);
            $bEdge = (float) ($b['signal']['tradable_net_edge_percent'] ?? $b['expected_net_edge_percent'] ?? -999.0);
            if (abs($aEdge - $bEdge) > 0.000001) return $bEdge <=> $aEdge;

            return ((float) ($b['depth_quote'] ?? 0.0)) <=> ((float) ($a['depth_quote'] ?? 0.0));
        });

        self::$processUniverseCache[$preferredQuote] = $analyzed;
        return $analyzed;
    }

    public function snapshotSymbol(NobitexClient $client, string $symbol, int $legacyThreshold = 60): array
    {
        unset($legacyThreshold);
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        if (isset(self::$processSnapshotCache[$symbol])) {
            return self::$processSnapshotCache[$symbol];
        }

        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '') throw new \InvalidArgumentException('Invalid Nobitex market symbol.');
        if (!NobitexSymbolPolicy::isOrderApiCompatibleBase($base)) {
            throw new \RuntimeException('Nobitex scaled market alias is not executable via order API: ' . $symbol);
        }

        $response = $client->orderBook($symbol);
        $book = $this->extractBook($response, $symbol);
        try { $stats = $client->stats(['srcCurrency'=>strtolower($base),'dstCurrency'=>strtolower($quote === 'IRT' ? 'rls' : $quote)]); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }

        $market = $this->market($symbol, $book, $stats, $options);
        if ($market === null) throw new \RuntimeException('Nobitex market is unavailable: ' . $symbol);

        $pdo = Database::connection();
        $this->ensureCacheSchema($pdo);
        $cached = $this->loadOneHistoryCache($pdo, $symbol);
        $prices = $this->cachedPrices($cached);
        $fetchedAt = trim((string) ($cached['fetched_at'] ?? '')) ?: null;
        $fetchTs = $fetchedAt ? (strtotime($fetchedAt . ' UTC') ?: 0) : 0;
        $needsRefresh = count($prices) < 60 || $fetchTs <= 0 || time() - $fetchTs >= self::POSITION_HISTORY_REFRESH_SECONDS;

        if ($needsRefresh && $this->reserveOhlcBudget($pdo, 1) === 1) {
            try {
                $history = $client->ohlc($symbol, '1', 480);
                $fresh = $this->minutePricesFromResponse($history, 0.0);
                if ($fresh !== []) {
                    $prices = $fresh;
                    $fetchedAt = gmdate('Y-m-d H:i:s');
                }
            } catch (\Throwable) {}
        }

        $minute = gmdate('Y-m-d H:i:00');
        $prices = $this->mergeLivePrice(
            $prices,
            (float) $market['price'],
            trim((string) ($cached['last_observed_minute'] ?? '')),
            $minute
        );
        $this->saveOneHistoryCache($pdo, $symbol, $prices, $fetchedAt, $minute);

        $market['prices'] = $prices;
        $market['analysis_resolution'] = '1m';
        $market['full_universe_analysis'] = false;
        $market['history_fetched_at'] = $fetchedAt;

        $signal = $this->signals->analyze($market, $prices);
        $market['signal'] = $signal;
        $market['expected_gross_move_percent'] = round((float) ($signal['expected_gross_move_percent'] ?? 0.0), 4);
        $market['estimated_roundtrip_cost_percent'] = round((float) ($signal['estimated_roundtrip_cost_percent'] ?? 0.0), 4);
        $market['expected_net_edge_percent'] = round((float) ($signal['expected_net_edge_percent'] ?? 0.0), 4);
        $market['expected_net_profit'] = (bool) ($signal['expected_net_profit'] ?? false);

        self::$processSnapshotCache[$symbol] = $market;
        return $market;
    }

    private function ensureCacheSchema(PDO $pdo): void
    {
        if (self::$cacheSchemaEnsured) return;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nobitex_market_history_cache (
                symbol VARCHAR(40) NOT NULL PRIMARY KEY,
                prices_json LONGTEXT NOT NULL,
                fetched_at DATETIME NULL,
                last_observed_minute DATETIME NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_nobitex_history_fetched (fetched_at),
                INDEX idx_nobitex_history_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nobitex_api_rate_budget (
                budget_key VARCHAR(50) NOT NULL PRIMARY KEY,
                window_started_at DATETIME NOT NULL,
                used_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$cacheSchemaEnsured = true;
    }

    private function reserveOhlcBudget(PDO $pdo, int $desired): int
    {
        $desired = max(0, $desired);
        if ($desired === 0) return 0;
        $this->ensureCacheSchema($pdo);

        return (int) Database::transaction(function (PDO $tx) use ($desired): int {
            $tx->exec(
                "INSERT IGNORE INTO nobitex_api_rate_budget
                    (budget_key,window_started_at,used_count,updated_at)
                 VALUES ('ohlc',UTC_TIMESTAMP(),0,UTC_TIMESTAMP())"
            );
            $stmt = $tx->query(
                "SELECT window_started_at,used_count
                 FROM nobitex_api_rate_budget WHERE budget_key='ohlc' FOR UPDATE"
            );
            $row = $stmt->fetch() ?: ['window_started_at'=>gmdate('Y-m-d H:i:s'),'used_count'=>0];
            $start = strtotime((string) $row['window_started_at'] . ' UTC') ?: time();
            $used = (int) $row['used_count'];

            if (time() - $start >= 60) {
                $start = time();
                $used = 0;
            }

            $remaining = max(0, self::OHLC_REQUESTS_PER_60_SECONDS - $used);
            $grant = min($desired, $remaining);
            $newUsed = $used + $grant;
            $update = $tx->prepare(
                "UPDATE nobitex_api_rate_budget
                 SET window_started_at=:started,used_count=:used,updated_at=UTC_TIMESTAMP()
                 WHERE budget_key='ohlc'"
            );
            $update->execute([
                ':started'=>gmdate('Y-m-d H:i:s', $start),
                ':used'=>$newUsed,
            ]);
            return $grant;
        });
    }

    /** @return array<string,array> */
    private function loadHistoryCache(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT symbol,prices_json,fetched_at,last_observed_minute FROM nobitex_market_history_cache')->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));
            if ($symbol !== '') $out[$symbol] = $row;
        }
        return $out;
    }

    private function loadOneHistoryCache(PDO $pdo, string $symbol): array
    {
        $stmt = $pdo->prepare('SELECT symbol,prices_json,fetched_at,last_observed_minute FROM nobitex_market_history_cache WHERE symbol=:symbol LIMIT 1');
        $stmt->execute([':symbol'=>$symbol]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private function saveOneHistoryCache(PDO $pdo, string $symbol, array $prices, ?string $fetchedAt, string $minute): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO nobitex_market_history_cache
                (symbol,prices_json,fetched_at,last_observed_minute,updated_at)
             VALUES (:symbol,:prices,:fetched_at,:minute,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                prices_json=VALUES(prices_json),fetched_at=VALUES(fetched_at),
                last_observed_minute=VALUES(last_observed_minute),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([
            ':symbol'=>$symbol,
            ':prices'=>json_encode(array_slice($prices, -480), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ':fetched_at'=>$fetchedAt,
            ':minute'=>$minute,
        ]);
    }

    private function cachedPrices(array $cached): array
    {
        $decoded = json_decode((string) ($cached['prices_json'] ?? '[]'), true);
        return is_array($decoded) ? $this->cleanPrices($decoded) : [];
    }

    private function cleanPrices(array $prices): array
    {
        $out = [];
        foreach ($prices as $price) {
            $n = $this->number($price);
            if ($n > 0) $out[] = $n;
        }
        return array_slice($out, -480);
    }

    private function mergeLivePrice(array $prices, float $price, string $lastObservedMinute, string $minute): array
    {
        $prices = $this->cleanPrices($prices);
        if ($price <= 0) return $prices;

        if ($lastObservedMinute === $minute && $prices !== []) {
            $prices[count($prices) - 1] = $price;
        } else {
            $prices[] = $price;
        }
        return array_slice($prices, -480);
    }

    private function minutePricesFromResponse(array $history, float $lastPrice): array
    {
        $prices = [];
        $closes = $history['c'] ?? [];
        if (is_array($closes)) {
            foreach ($closes as $close) {
                $n = $this->number($close);
                if ($n > 0) $prices[] = $n;
            }
        }
        if ($lastPrice > 0 && ($prices === [] || abs((float) end($prices) - $lastPrice) > 0.00000001)) {
            $prices[] = $lastPrice;
        }
        return array_slice($prices, -480);
    }

    private function marketsFromAll(array $response, array $stats, array $options): array
    {
        $rows = $response;
        if (is_array($response['data'] ?? null)) $rows = $response['data'];

        $out = [];
        foreach ($rows as $rawSymbol => $book) {
            if (!is_string($rawSymbol) || !is_array($book)) continue;
            $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $rawSymbol) ?? '');
            [$base, $quote] = $this->parseSymbol($symbol);
            if (
                $base === '' ||
                $quote === '' ||
                in_array($base, self::EXCLUDED_BASES, true) ||
                !NobitexSymbolPolicy::isOrderApiCompatibleBase($base)
            ) continue;

            $market = $this->market($symbol, $book, $stats, $options);
            if ($market !== null) $out[] = $market;
        }
        return $out;
    }

    private function market(string $symbol, array $book, array $stats, array $options): ?array
    {
        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '' || !NobitexSymbolPolicy::isOrderApiCompatibleBase($base)) return null;

        $last = $this->number($book['lastTradePrice'] ?? $book['last_trade_price'] ?? $book['lastPrice'] ?? 0);
        $bestAsk = $this->levelPrice($book['asks'][0] ?? null);
        $bestBid = $this->levelPrice($book['bids'][0] ?? null);
        $price = $last > 0 ? $last : (($bestAsk > 0 && $bestBid > 0) ? (($bestAsk + $bestBid) / 2.0) : max($bestAsk, $bestBid));
        if ($price <= 0 || $bestAsk <= 0 || $bestBid <= 0) return null;

        $spread = (($bestAsk - $bestBid) / max($price, 0.00000001)) * 100.0;
        if ($spread < 0 || $spread > 3.0) return null;

        $bidDepth = $this->depthQuote(is_array($book['bids'] ?? null) ? $book['bids'] : []);
        $askDepth = $this->depthQuote(is_array($book['asks'] ?? null) ? $book['asks'] : []);
        $depth = min($bidDepth, $askDepth);
        $minOrder = $this->minimumOrder($options, $quote);
        if ($depth <= 0 || ($minOrder > 0 && $depth < ($minOrder * 2.0))) return null;

        return [
            'exchange'=>'nobitex',
            'asset'=>$base,
            'exchange_asset'=>$base,
            'quote_asset'=>$quote,
            'symbol'=>$symbol,
            'market_id'=>$this->compatibilityMarketId($symbol),
            'price'=>$price,
            'best_ask'=>$bestAsk,
            'best_bid'=>$bestBid,
            'spread_percent'=>round($spread, 6),
            'depth_quote'=>$depth,
            'change_percent'=>$this->dayChange($stats, $base, $quote, $symbol),
            'orderbook_imbalance'=>$this->imbalance($book),
            'base_precision'=>$this->amountPrecision($options, $symbol, 8),
            'min_order_quote'=>$minOrder,
            'observed_at'=>gmdate(DATE_ATOM),
        ];
    }

    private function extractBook(array $response, string $symbol): array
    {
        if (is_array($response[$symbol] ?? null)) return $response[$symbol];
        if (is_array($response['data'][$symbol] ?? null)) return $response['data'][$symbol];
        if (is_array($response['data'] ?? null) && (isset($response['data']['asks']) || isset($response['data']['bids']))) return $response['data'];
        return $response;
    }

    private function dayChange(array $stats, string $base, string $quote, string $symbol): float
    {
        $rows = $stats['stats'] ?? $stats['data'] ?? $stats;
        if (!is_array($rows)) return 0.0;
        $wanted = [$symbol, $base . ($quote === 'IRT' ? 'RLS' : $quote)];

        foreach ($rows as $key => $row) {
            if (!is_array($row)) continue;
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $key) ?? '');
            if ($normalized !== '' && !in_array($normalized, $wanted, true)) continue;

            foreach (['dayChange','day_change','change','percentChange'] as $field) {
                if (!array_key_exists($field, $row)) continue;
                $v = $this->number($row[$field]);
                if ($v !== 0.0) return $v;
            }
        }
        return 0.0;
    }

    private function minimumOrder(array $options, string $quote): float
    {
        $n = $this->nobitexOptions($options);
        $mins = is_array($n['minOrders'] ?? null) ? $n['minOrders'] : [];
        foreach ($quote === 'IRT' ? ['rls','RLS','irt','IRT'] : ['usdt','USDT'] as $key) {
            $v = $this->number($mins[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return $quote === 'IRT' ? 3000000.0 : 11.0;
    }

    private function amountPrecision(array $options, string $symbol, int $fallback): int
    {
        $n = $this->nobitexOptions($options);
        $rows = is_array($n['amountPrecisions'] ?? null) ? $n['amountPrecisions'] : [];
        foreach ([$symbol, strtolower($symbol), strtoupper($symbol)] as $key) {
            if (array_key_exists($key, $rows)) return $this->precisionFromStep($rows[$key], $fallback);
        }
        return $fallback;
    }

    private function nobitexOptions(array $options): array
    {
        if (is_array($options['nobitex'] ?? null)) return $options['nobitex'];
        if (is_array($options['data']['nobitex'] ?? null)) return $options['data']['nobitex'];
        return [];
    }

    private function precisionFromStep(mixed $value, int $fallback): int
    {
        if (!is_numeric($value)) return $fallback;
        $s = strtolower(trim((string) $value));
        if (str_contains($s, 'e-')) {
            $parts = explode('e-', $s, 2);
            return max(0, min(18, (int) ($parts[1] ?? $fallback)));
        }
        if (!str_contains($s, '.')) return 0;
        $decimals = rtrim(substr(strrchr($s, '.'), 1), '0');
        return max(0, min(18, strlen($decimals)));
    }

    private function parseSymbol(string $symbol): array
    {
        foreach (self::QUOTES as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }
        return ['', ''];
    }

    private function quote(string $quote): string
    {
        $quote = strtoupper(trim($quote));
        return in_array($quote, self::QUOTES, true) ? $quote : 'IRT';
    }

    private function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private function depthQuote(array $levels): float
    {
        $sum = 0.0;
        foreach (array_slice($levels, 0, 20) as $level) {
            if (!is_array($level)) continue;
            $price = $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
            $amount = $this->number(array_is_list($level) ? ($level[1] ?? 0) : ($level['amount'] ?? $level['volume'] ?? 0));
            if ($price > 0 && $amount > 0) $sum += $price * $amount;
        }
        return $sum;
    }

    private function imbalance(array $book): float
    {
        $bid = $this->depthQuote(is_array($book['bids'] ?? null) ? $book['bids'] : []);
        $ask = $this->depthQuote(is_array($book['asks'] ?? null) ? $book['asks'] : []);
        $total = $bid + $ask;
        return $total > 0 ? max(-1.0, min(1.0, ($bid - $ask) / $total)) : 0.0;
    }

    private function compatibilityMarketId(string $symbol): int
    {
        return (int) (sprintf('%u', crc32('nobitex:' . $symbol)) ?: 1);
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float) $value;
        return is_finite($n) ? $n : 0.0;
    }
}
