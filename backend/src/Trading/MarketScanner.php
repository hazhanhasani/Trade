<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\BitpinClient;

final class MarketScanner
{
    private const CANONICAL_ASSET = 'GRAM';
    private const ASSET_ALIASES = ['GRAM', 'TON', 'TONCOIN'];
    private const MAX_MARKET_PAGES = 100;

    private int $lastScannedRecords = 0;
    private int $lastScannedPages = 0;

    public function snapshot(BitpinClient $client, string $preferredQuote = 'USDT'): array
    {
        $preferredQuote = strtoupper(trim($preferredQuote));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $preferredQuote)) {
            $preferredQuote = 'USDT';
        }

        $markets = $this->loadMarkets($client);
        $market = $this->chooseMarket($markets, $preferredQuote);
        if ($market === null) {
            $near = $this->nearbyMarketCodes($markets);
            $suffix = $near === [] ? '' : ' Similar codes: ' . implode(', ', $near) . '.';
            throw new \RuntimeException(
                'No tradable GRAM/TON market was found on Bitpin after scanning '
                . $this->lastScannedRecords . ' records across ' . $this->lastScannedPages . ' page(s).'
                . $suffix
            );
        }

        if ($market['price'] <= 0) {
            try {
                $market['price'] = $this->tickerPrice($client->tickers(), $market['symbol']);
            } catch (\Throwable) {
            }
        }
        if ($market['price'] <= 0) {
            throw new \RuntimeException('Bitpin returned no usable price for ' . $market['symbol'] . '.');
        }

        $trades = [];
        try {
            $trades = $this->records($client->recentTrades($market['symbol']));
        } catch (\Throwable) {
        }
        $prices = $this->priceSeries($trades);
        if ($prices === []) {
            $prices = [$market['price']];
        } elseif (abs((float) end($prices) - $market['price']) > 0.00000001) {
            $prices[] = $market['price'];
        }

        $book = [];
        try {
            $book = $client->orderBook($market['symbol']);
        } catch (\Throwable) {
        }

        return [
            'asset' => self::CANONICAL_ASSET,
            'exchange_asset' => $market['base'],
            'quote_asset' => $market['quote'],
            'market_id' => $market['id'],
            'symbol' => $market['symbol'],
            'price' => $market['price'],
            'change_percent' => $market['change_percent'],
            'base_precision' => $market['base_precision'],
            'prices' => $prices,
            'orderbook_imbalance' => $this->orderBookImbalance($book),
            'market_scan' => [
                'records' => $this->lastScannedRecords,
                'pages' => $this->lastScannedPages,
            ],
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function loadMarkets(BitpinClient $client): array
    {
        $all = [];
        $seen = [];
        $rawLoaded = 0;
        $page = 1;

        while ($page <= self::MAX_MARKET_PAGES) {
            $response = $client->markets(['page' => $page]);
            $records = $this->records($response);
            $this->lastScannedPages = $page;
            if ($records === []) break;

            $rawLoaded += count($records);
            foreach ($records as $record) {
                $normalized = $this->normalizeMarket($record);
                if ($normalized === null) continue;
                $dedupeKey = $normalized['symbol'];
                if (isset($seen[$dedupeKey])) continue;
                $seen[$dedupeKey] = true;
                $all[] = $normalized;
            }

            if (array_is_list($response)) break;
            $next = $response['next'] ?? null;
            if (is_string($next) && trim($next) !== '') {
                $page++;
                continue;
            }
            $reportedCount = (int) ($response['count'] ?? 0);
            if ($reportedCount > 0 && $rawLoaded < $reportedCount) {
                $page++;
                continue;
            }
            break;
        }

        $this->lastScannedRecords = $rawLoaded;
        return $all;
    }

    private function chooseMarket(array $markets, string $preferredQuote): ?array
    {
        $candidates = array_values(array_filter($markets, function (array $market): bool {
            return $market['tradable'] && $this->isTargetAsset(
                (string) $market['base'],
                (string) ($market['base_title'] ?? ''),
                (string) ($market['base_title_fa'] ?? '')
            );
        }));
        if ($candidates === []) return null;

        usort($candidates, function (array $a, array $b) use ($preferredQuote): int {
            $rank = function (array $market) use ($preferredQuote): int {
                $base = strtoupper((string) $market['base']);
                $quote = strtoupper((string) $market['quote']);
                $assetRank = match ($base) {
                    'GRAM' => 0,
                    'TON' => 1,
                    'TONCOIN' => 2,
                    default => 3,
                };
                if ($quote === $preferredQuote) return $assetRank;
                if ($quote === 'USDT') return 10 + $assetRank;
                if ($quote === 'IRT') return 20 + $assetRank;
                return 30 + $assetRank;
            };
            return $rank($a) <=> $rank($b);
        });
        return $candidates[0];
    }

    private function normalizeMarket(array $row): ?array
    {
        $id = max(0, (int) ($row['id'] ?? $row['market_id'] ?? 0));
        $currency1 = is_array($row['currency1'] ?? null) ? $row['currency1'] : [];
        $currency2 = is_array($row['currency2'] ?? null) ? $row['currency2'] : [];
        $baseCurrency = is_array($row['base_currency'] ?? null) ? $row['base_currency'] : [];
        $quoteCurrency = is_array($row['quote_currency'] ?? null) ? $row['quote_currency'] : [];

        $base = strtoupper(trim((string) ($row['base'] ?? $currency1['code'] ?? $baseCurrency['code'] ?? '')));
        $quote = strtoupper(trim((string) ($row['quote'] ?? $currency2['code'] ?? $quoteCurrency['code'] ?? '')));
        $rawSymbol = strtoupper(trim((string) ($row['symbol'] ?? $row['code'] ?? '')));
        [$parsedBase, $parsedQuote] = $this->parseSymbol($rawSymbol);
        if ($base === '') $base = $parsedBase;
        if ($quote === '') $quote = $parsedQuote;

        $symbol = $rawSymbol !== '' ? $rawSymbol : (($base !== '' && $quote !== '') ? $base . '_' . $quote : '');
        if ($base === '' || $quote === '' || $symbol === '') return null;
        if ($id <= 0) $id = $this->stableMarketId($symbol);

        $price = $this->number($row['price'] ?? $row['price_info']['price'] ?? $row['order_book_info']['price'] ?? $row['last_price'] ?? 0);
        $change = $this->number($row['change_percent'] ?? $row['daily_change_percent'] ?? $row['price_info']['change'] ?? $row['order_book_info']['change'] ?? 0);
        $precision = (int) ($row['base_amount_precision'] ?? $currency1['decimal_amount'] ?? $baseCurrency['decimal_amount'] ?? 8);

        $tradable = true;
        if (array_key_exists('tradable', $row)) $tradable = (bool) $row['tradable'];
        elseif (array_key_exists('tradable', $currency1)) $tradable = (bool) $currency1['tradable'];

        return [
            'id' => $id,
            'base' => $base,
            'quote' => $quote,
            'symbol' => $symbol,
            'tradable' => $tradable,
            'price' => $price,
            'change_percent' => $change,
            'base_precision' => max(0, min(18, $precision)),
            'base_title' => (string) ($row['name'] ?? $row['base_title'] ?? $currency1['title'] ?? $baseCurrency['title'] ?? ''),
            'base_title_fa' => (string) ($currency1['title_fa'] ?? $baseCurrency['title_fa'] ?? ''),
        ];
    }

    private function parseSymbol(string $symbol): array
    {
        if ($symbol === '') return ['', ''];
        $normalized = str_replace(['-', '/'], '_', $symbol);
        $parts = array_values(array_filter(explode('_', $normalized), static fn (string $part): bool => $part !== ''));
        if (count($parts) >= 2) return [$parts[0], $parts[count($parts) - 1]];
        foreach (['USDT', 'IRT', 'USDC', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }
        return ['', ''];
    }

    private function isTargetAsset(string $code, string $title = '', string $titleFa = ''): bool
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if (in_array($code, self::ASSET_ALIASES, true)) return true;
        $name = strtoupper(trim($title));
        if ($name !== '' && (str_contains($name, 'TONCOIN') || str_contains($name, 'THE OPEN NETWORK') || preg_match('/\bGRAM\b/', $name) === 1)) return true;
        $fa = trim($titleFa);
        return $fa !== '' && (str_contains($fa, 'تون کوین') || str_contains($fa, 'تون‌کوین') || str_contains($fa, 'گرام'));
    }

    private function nearbyMarketCodes(array $markets): array
    {
        $codes = [];
        foreach ($markets as $market) {
            $haystack = strtoupper((string)($market['symbol'] ?? '') . ' ' . (string)($market['base'] ?? '') . ' ' . (string)($market['base_title'] ?? ''));
            if (str_contains($haystack, 'TON') || str_contains($haystack, 'GRAM') || str_contains($haystack, 'OPEN NETWORK')) {
                $codes[] = (string) ($market['symbol'] ?? '');
            }
            if (count($codes) >= 12) break;
        }
        return array_values(array_unique(array_filter($codes)));
    }

    private function tickerPrice(array $response, string $symbol): float
    {
        $needle = $this->symbolKey($symbol);
        foreach ($this->records($response) as $row) {
            $code = strtoupper((string) ($row['symbol'] ?? $row['code'] ?? $row['market']['code'] ?? ''));
            if ($this->symbolKey($code) !== $needle) continue;
            $price = $this->number($row['price'] ?? $row['last_price'] ?? $row['price_info']['price'] ?? 0);
            if ($price > 0) return $price;
        }
        return 0.0;
    }

    private function symbolKey(string $symbol): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
    }

    private function stableMarketId(string $symbol): int
    {
        $unsigned = sprintf('%u', crc32($this->symbolKey($symbol)));
        $id = (int) $unsigned;
        return $id > 0 ? $id : 1;
    }

    private function priceSeries(array $trades): array
    {
        $series = [];
        foreach ($trades as $row) {
            $price = $this->number($row['price'] ?? $row['average_price'] ?? 0);
            if ($price <= 0) continue;
            $time = strtotime((string) ($row['created_at'] ?? $row['time'] ?? '')) ?: 0;
            $series[] = ['time' => $time, 'price' => $price];
        }
        if ($series === []) return [];
        $hasTimes = count(array_filter($series, static fn(array $x): bool => $x['time'] > 0)) >= 2;
        if ($hasTimes) usort($series, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        else $series = array_reverse($series);
        return array_values(array_map(static fn(array $x): float => (float)$x['price'], array_slice($series, -120)));
    }

    private function orderBookImbalance(array $book): float
    {
        $bids = $book['bids'] ?? $book['buy'] ?? $book['orders']['bids'] ?? [];
        $asks = $book['asks'] ?? $book['sell'] ?? $book['orders']['asks'] ?? [];
        $bidVolume = $this->bookVolume(is_array($bids) ? $bids : []);
        $askVolume = $this->bookVolume(is_array($asks) ? $asks : []);
        $total = $bidVolume + $askVolume;
        if ($total <= 0) return 0.0;
        return max(-1.0, min(1.0, ($bidVolume - $askVolume) / $total));
    }

    private function bookVolume(array $levels): float
    {
        $sum = 0.0;
        foreach (array_slice($levels, 0, 20) as $level) {
            if (is_array($level) && array_is_list($level)) $sum += $this->number($level[1] ?? 0);
            elseif (is_array($level)) $sum += $this->number($level['amount'] ?? $level['volume'] ?? $level['size'] ?? 0);
        }
        return $sum;
    }

    private function records(array $response): array
    {
        if (array_is_list($response)) return array_values(array_filter($response, 'is_array'));
        foreach (['results', 'data', 'items', 'markets'] as $key) {
            if (!isset($response[$key]) || !is_array($response[$key])) continue;
            $value = $response[$key];
            if (array_is_list($value)) return array_values(array_filter($value, 'is_array'));
            foreach (['results', 'data', 'items'] as $nestedKey) {
                if (isset($value[$nestedKey]) && is_array($value[$nestedKey]) && array_is_list($value[$nestedKey])) {
                    return array_values(array_filter($value[$nestedKey], 'is_array'));
                }
            }
        }
        return [];
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float)$value;
        return is_finite($number) ? $number : 0.0;
    }
}
