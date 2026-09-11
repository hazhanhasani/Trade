<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

/**
 * Keeps Nobitex execution/risk arithmetic in exchange-native RLS while exposing
 * Toman display values. The account headline prefers Nobitex's own wallet-level
 * rialBalance truth and only falls back to local order-book valuation when the
 * exchange did not provide a Rial equivalent for an asset.
 */
final class NobitexPortfolioValuation
{
    private const RLS_PER_TOMAN = 10.0;
    private const QUOTE_ASSETS = ['RLS','IRT','USDT'];

    public function snapshot(NobitexClient $client, array $wallets, array $positions): array
    {
        $rows = $this->walletRows($wallets);
        $needsBooks = false;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper(trim((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? '')));
            $total = $this->walletRowTotalBalance($row);
            $officialRial = $this->walletRowRialValue($row, 'rialBalance');
            if ($asset !== '' && !in_array($asset,['RLS','IRT'],true) && $total > 0.0 && !($officialRial !== null && $officialRial > 0.0)) {
                $needsBooks = true;
                break;
            }
        }

        $books = [];
        if ($needsBooks) {
            try { $books = $this->bookRows($client->allOrderBooks()); } catch (\Throwable) {}
        }

        // Prefer the rate implied by Nobitex's own wallet valuation. This keeps
        // USDT exposure/quote conversions on the same price basis as the wallet
        // headline instead of mixing two slightly different market marks.
        $usdtToRls = $this->walletImpliedRlsRate($rows, 'USDT');
        if ($usdtToRls <= 0.0) $usdtToRls = $this->bookMark($books['USDTIRT'] ?? $books['USDTRLS'] ?? []);
        if (($needsBooks || $this->hasAsset($rows, 'USDT')) && $usdtToRls <= 0.0) $usdtToRls = $this->usdtToRls($client);

        $walletAssets = [];
        $walletTotalRls = 0.0;
        $walletTotalSellRls = 0.0;
        $cashTotalRls = 0.0;
        $unpricedAssets = [];
        $officialValueCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper(trim((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? '')));
            if ($asset === '') continue;

            $totalBalance = $this->walletRowTotalBalance($row);
            $availableBalance = $this->walletRowAvailableBalance($row);
            if ($totalBalance <= 0.0 && $availableBalance <= 0.0) continue;

            $officialRial = $this->walletRowRialValue($row, 'rialBalance');
            $officialRialSell = $this->walletRowRialValue($row, 'rialBalanceSell');
            $valueRls = 0.0;
            $availableValueRls = 0.0;
            $priceRls = null;
            $priceSource = null;

            if ($asset === 'RLS') {
                $valueRls = $totalBalance;
                $availableValueRls = $availableBalance;
                $priceRls = 1.0;
                $priceSource = 'native_rls';
            } elseif ($asset === 'IRT') {
                $valueRls = $totalBalance * self::RLS_PER_TOMAN;
                $availableValueRls = $availableBalance * self::RLS_PER_TOMAN;
                $priceRls = self::RLS_PER_TOMAN;
                $priceSource = 'native_irt';
            } elseif ($officialRial !== null && $officialRial > 0.0 && $totalBalance > 0.0) {
                $valueRls = $officialRial;
                $priceRls = $officialRial / $totalBalance;
                $availableValueRls = $availableBalance * $priceRls;
                $priceSource = 'nobitex_wallet_rialBalance';
                $officialValueCount++;
            } elseif ($asset === 'USDT') {
                if ($usdtToRls > 0.0) {
                    $valueRls = $totalBalance * $usdtToRls;
                    $availableValueRls = $availableBalance * $usdtToRls;
                    $priceRls = $usdtToRls;
                    $priceSource = 'USDTIRT';
                }
            } else {
                [$priceRls, $priceSource] = $this->assetToRls($asset, $books, $usdtToRls);
                if ($priceRls > 0.0) {
                    $valueRls = $totalBalance * $priceRls;
                    $availableValueRls = $availableBalance * $priceRls;
                }
            }

            if ($valueRls <= 0.0 && $totalBalance > 0.0) {
                $unpricedAssets[] = $asset;
            } else {
                $walletTotalRls += $valueRls;
                $walletTotalSellRls += ($officialRialSell !== null && $officialRialSell > 0.0) ? $officialRialSell : $valueRls;
                if (in_array($asset, self::QUOTE_ASSETS, true)) $cashTotalRls += $valueRls;
            }

            $walletAssets[] = [
                'asset'=>$asset,
                'balance'=>round($totalBalance, 12),
                'available'=>round($availableBalance, 12),
                'price_rls'=>$priceRls !== null ? round($priceRls, 8) : null,
                'price_toman'=>$priceRls !== null ? round($this->rlsToToman($priceRls), 8) : null,
                'value_rls'=>round($valueRls, 8),
                'value_toman'=>round($this->rlsToToman($valueRls), 8),
                'available_value_toman'=>round($this->rlsToToman($availableValueRls), 8),
                'official_rial_balance'=>$officialRial !== null ? round($officialRial, 8) : null,
                'official_rial_balance_sell'=>$officialRialSell !== null ? round($officialRialSell, 8) : null,
                'value_sell_toman'=>$officialRialSell !== null ? round($this->rlsToToman($officialRialSell), 8) : null,
                'price_source'=>$priceSource,
            ];
        }

        // Risk exposure remains bot-position based. This avoids treating manually
        // held coins/dust as bot exposure while the account total still reflects
        // the complete Nobitex spot wallet.
        $notionalRaw = ['IRT'=>0.0,'USDT'=>0.0];
        foreach ($positions as $position) {
            if (!is_array($position)) continue;
            $quote = strtoupper((string)($position['quote_asset'] ?? ''));
            if (!isset($notionalRaw[$quote])) continue;
            $mark = $this->number($position['mark_price'] ?? 0);
            if ($mark <= 0) $mark = $this->number($position['entry_price'] ?? 0);
            $amount = max(0.0, $this->number($position['amount'] ?? 0));
            $notionalRaw[$quote] += $amount * max(0.0, $mark);
        }

        $positionExposureRls = $notionalRaw['IRT'] + ($usdtToRls > 0.0 ? $notionalRaw['USDT'] * $usdtToRls : 0.0);

        // If complete wallet pricing is temporarily unavailable, preserve safety
        // by never reporting a total lower than known quote cash + bot exposure.
        $fallbackTotalRls = $cashTotalRls + $positionExposureRls;
        $portfolioRls = max($walletTotalRls, $fallbackTotalRls);
        $conversionReady = $unpricedAssets === [];

        usort($walletAssets, static fn(array $a, array $b): int => ((float)$b['value_rls']) <=> ((float)$a['value_rls']));

        $cashToman = $this->rlsToToman($cashTotalRls);
        $exposureToman = $this->rlsToToman($positionExposureRls);
        $portfolioToman = $this->rlsToToman($portfolioRls);
        $walletTotalToman = $this->rlsToToman($walletTotalRls);

        return [
            'model'=>'nobitex_full_spot_wallet_valuation_v4',
            'numeraire'=>'IRT',
            'display_unit'=>'TOMAN',
            'internal_numeraire'=>'RLS',
            'rls_per_irt'=>self::RLS_PER_TOMAN,
            'valuation_source'=>$officialValueCount > 0
                ? 'nobitex_wallet_rialBalance'
                : ($walletTotalRls > 0.0 ? 'full_spot_wallet_orderbook_fallback' : 'quote_cash_plus_bot_positions_fallback'),
            'official_wallet_value_assets'=>$officialValueCount,
            'conversion_ready'=>$conversionReady,
            'unpriced_assets'=>array_values(array_unique($unpricedAssets)),
            'wallet_assets'=>$walletAssets,

            'usdt_to_irt_rate'=>$usdtToRls > 0.0 ? round($this->rlsToToman($usdtToRls), 8) : null,
            'usdt_to_rls_rate'=>$usdtToRls > 0.0 ? round($usdtToRls, 8) : null,

            'cash_by_quote'=>[
                'IRT'=>round($this->quoteCashToman($rows),8),
                'USDT'=>round($this->walletTotalByAsset($rows,'USDT'),8),
            ],
            'available_cash_by_quote'=>[
                'IRT'=>round($this->quoteAvailableToman($rows),8),
                'USDT'=>round($this->walletAvailableByAsset($rows,'USDT'),8),
            ],
            'active_notional_by_quote'=>[
                'IRT'=>round($this->rlsToToman($notionalRaw['IRT']),8),
                'USDT'=>round($notionalRaw['USDT'],8),
            ],

            'wallet_total_toman'=>round($walletTotalToman,8),
            'wallet_total_sell_toman'=>round($this->rlsToToman($walletTotalSellRls),8),
            'portfolio_value_irt'=>round($portfolioToman,8),
            'cash_irt'=>round($cashToman,8),
            'exposure_irt'=>round($exposureToman,8),

            'wallet_total_rls'=>round($walletTotalRls,8),
            'wallet_total_sell_rls'=>round($walletTotalSellRls,8),
            'portfolio_value_rls'=>round($portfolioRls,8),
            'cash_rls'=>round($cashTotalRls,8),
            'exposure_rls'=>round($positionExposureRls,8),

            'exposure_percent'=>$portfolioRls > 0.0 ? round(($positionExposureRls / $portfolioRls) * 100.0, 4) : 0.0,
            'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    public function quoteEquivalent(array $valuation, string $quote, float $irtValue): ?float
    {
        $quote = strtoupper($quote);
        if ($quote === 'IRT') return max(0.0, $irtValue);
        if ($quote !== 'USDT') return null;
        $rate = $this->number($valuation['usdt_to_irt_rate'] ?? 0);
        return $rate > 0.0 ? max(0.0, $irtValue / $rate) : null;
    }

    private function assetToRls(string $asset, array $books, float $usdtToRls): array
    {
        foreach ($this->assetAliases($asset) as $alias) {
            foreach ([$alias.'IRT', $alias.'RLS'] as $symbol) {
                $mark = $this->bookMark($books[$symbol] ?? []);
                if ($mark > 0.0) return [$mark, $symbol];
            }
            if ($usdtToRls > 0.0) {
                $symbol = $alias.'USDT';
                $mark = $this->bookMark($books[$symbol] ?? []);
                if ($mark > 0.0) return [$mark * $usdtToRls, $symbol.'→USDTIRT'];
            }
        }
        return [0.0, null];
    }

    private function assetAliases(string $asset): array
    {
        $asset = strtoupper(trim($asset));
        return match ($asset) {
            'TON','GRAM','TONCOIN' => ['TON','GRAM','TONCOIN'],
            default => [$asset],
        };
    }

    private function bookRows(array $response): array
    {
        $rows = $response['data'] ?? $response['orderbooks'] ?? $response;
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $symbol => $book) {
            if (!is_string($symbol) || !is_array($book)) continue;
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/','',$symbol) ?? '');
            if ($normalized !== '') $out[$normalized] = $book;
        }
        return $out;
    }

    private function bookMark(array $book): float
    {
        $last = $this->number($book['lastTradePrice'] ?? $book['last_trade_price'] ?? $book['lastPrice'] ?? 0);
        $ask = $this->levelPrice($book['asks'][0] ?? null);
        $bid = $this->levelPrice($book['bids'][0] ?? null);
        if ($last > 0.0) return $last;
        if ($ask > 0.0 && $bid > 0.0 && $ask >= $bid) return ($ask + $bid) / 2.0;
        return max($ask, $bid);
    }

    private function usdtToRls(NobitexClient $client): float
    {
        try {
            $response = $client->orderBook('USDTIRT');
            $book = is_array($response['USDTIRT'] ?? null)
                ? $response['USDTIRT']
                : (is_array($response['data']['USDTIRT'] ?? null)
                    ? $response['data']['USDTIRT']
                    : (is_array($response['data'] ?? null) ? $response['data'] : $response));
            return $this->bookMark($book);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function walletRows(array $response): array
    {
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        return is_array($rows) ? $rows : [];
    }

    private function walletRowTotalBalance(array $row): float
    {
        foreach (['balance','totalBalance','total_balance'] as $key) {
            if (array_key_exists($key,$row) && is_numeric($row[$key])) return max(0.0,$this->number($row[$key]));
        }
        $active = null;
        foreach (['activeBalance','available','free'] as $key) {
            if (array_key_exists($key,$row) && is_numeric($row[$key])) { $active=max(0.0,$this->number($row[$key])); break; }
        }
        $blocked = 0.0;
        foreach (['blockedBalance','blocked','locked','frozen'] as $key) {
            if (array_key_exists($key,$row) && is_numeric($row[$key])) { $blocked=max(0.0,$this->number($row[$key])); break; }
        }
        return max(0.0,($active ?? 0.0)+$blocked);
    }

    private function walletRowAvailableBalance(array $row): float
    {
        foreach (['activeBalance','available','free','balance'] as $key) {
            if (array_key_exists($key,$row) && is_numeric($row[$key])) return max(0.0,$this->number($row[$key]));
        }
        return 0.0;
    }

    private function walletRowRialValue(array $row, string $key): ?float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) return null;
        return max(0.0, $this->number($row[$key]));
    }

    private function walletImpliedRlsRate(array $rows, string $wanted): float
    {
        $wanted = strtoupper($wanted);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset !== $wanted) continue;
            $balance = $this->walletRowTotalBalance($row);
            $rial = $this->walletRowRialValue($row, 'rialBalance');
            if ($balance > 0.0 && $rial !== null && $rial > 0.0) return $rial / $balance;
        }
        return 0.0;
    }

    private function walletTotalByAsset(array $rows, string $wanted): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset === strtoupper($wanted)) $sum += $this->walletRowTotalBalance($row);
        }
        return $sum;
    }

    private function walletAvailableByAsset(array $rows, string $wanted): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset === strtoupper($wanted)) $sum += $this->walletRowAvailableBalance($row);
        }
        return $sum;
    }

    private function quoteCashToman(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset === 'RLS') $sum += $this->rlsToToman($this->walletRowTotalBalance($row));
            elseif ($asset === 'IRT') $sum += $this->walletRowTotalBalance($row);
        }
        return $sum;
    }

    private function quoteAvailableToman(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset === 'RLS') $sum += $this->rlsToToman($this->walletRowAvailableBalance($row));
            elseif ($asset === 'IRT') $sum += $this->walletRowAvailableBalance($row);
        }
        return $sum;
    }

    private function hasAsset(array $rows, string $wanted): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if ($asset === strtoupper($wanted) && $this->walletRowTotalBalance($row) > 0.0) return true;
        }
        return false;
    }

    private function rlsToToman(float $value): float { return $value / self::RLS_PER_TOMAN; }

    private function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float)$value;
        return is_finite($n) ? $n : 0.0;
    }
}
