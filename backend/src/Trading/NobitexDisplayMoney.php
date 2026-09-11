<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Display-only currency normalization for Nobitex.
 *
 * Nobitex order/risk/accounting values for logical IRT markets remain in the
 * exchange-native Rial (RLS) unit internally. User-facing IRT values are Toman.
 * Never use this class to mutate order payloads, risk budgets or stored fills.
 */
final class NobitexDisplayMoney
{
    public const RLS_PER_TOMAN = 10.0;

    public static function quoteValue(float|int|string|null $value, string $quote): float
    {
        $n = is_numeric($value) ? (float)$value : 0.0;
        return strtoupper(trim($quote)) === 'IRT' ? $n / self::RLS_PER_TOMAN : $n;
    }

    public static function quoteUnit(string $quote): string
    {
        return strtoupper(trim($quote)) === 'IRT' ? 'TOMAN' : strtoupper(trim($quote));
    }

    /** Convert the dedicated public wallet response to the same Toman unit the Nobitex UI shows. */
    public static function walletResponse(array $response): array
    {
        $decorate = static function (array $row): array {
            $currency = strtoupper(trim((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? '')));
            $displayCurrency = $currency === 'RLS' ? 'IRT' : $currency;
            $balance = self::firstNumeric($row, ['activeBalance','available','free','balance']);
            $displayBalance = $balance;
            if ($currency === 'RLS' && $balance !== null) $displayBalance = $balance / self::RLS_PER_TOMAN;

            $valueToman = null;
            if (isset($row['irtValue']) && is_numeric($row['irtValue'])) $valueToman = (float)$row['irtValue'];
            else {
                $rawRial = self::firstNumeric($row, ['rialValue','rial_value','valueRls','value_rls']);
                if ($rawRial !== null) $valueToman = $rawRial / self::RLS_PER_TOMAN;
            }
            if ($currency === 'RLS' && $valueToman === null && $balance !== null) $valueToman = $balance / self::RLS_PER_TOMAN;

            foreach (['rialValue','rial_value','valueRls','value_rls'] as $field) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $row['raw_'.$field] = $row[$field];
                    $row[$field] = self::toman($row[$field]);
                }
            }
            if ($currency === 'RLS') {
                if (isset($row['currency'])) { $row['raw_currency']=$row['currency']; $row['currency']='IRT'; }
                if (isset($row['currencyCode'])) { $row['raw_currencyCode']=$row['currencyCode']; $row['currencyCode']='IRT'; }
                foreach (['activeBalance','available','free','balance'] as $field) {
                    if (isset($row[$field]) && is_numeric($row[$field])) {
                        $row['raw_'.$field] = $row[$field];
                        $row[$field] = round(((float)$row[$field]) / self::RLS_PER_TOMAN, 12);
                    }
                }
            }
            $row['display_currency'] = $displayCurrency;
            $row['display_unit'] = $displayCurrency === 'IRT' ? 'TOMAN' : $displayCurrency;
            if ($displayBalance !== null) $row['display_balance'] = round($displayBalance, 12);
            if ($valueToman !== null) $row['display_value_toman'] = round($valueToman, 8);
            return $row;
        };

        if (isset($response['wallets']) && is_array($response['wallets'])) $response['wallets'] = array_map($decorate, $response['wallets']);
        if (isset($response['data']) && is_array($response['data'])) {
            if (array_is_list($response['data'])) $response['data'] = array_map(static fn($r) => is_array($r) ? $decorate($r) : $r, $response['data']);
            elseif (isset($response['data']['wallets']) && is_array($response['data']['wallets'])) $response['data']['wallets'] = array_map($decorate, $response['data']['wallets']);
        }
        return self::metadata($response, true);
    }

    /**
     * Normalize V3 order books for public/UI consumers. Only the first item of
     * bid/ask tuples (price) changes; amount stays exchange-exact.
     */
    public static function orderBooksResponse(array $response): array
    {
        $books =& $response;
        if (isset($response['orderbooks']) && is_array($response['orderbooks'])) $books =& $response['orderbooks'];
        foreach ($books as $symbol => &$book) {
            if (!is_array($book) || !self::symbolUsesRialQuote((string)$symbol)) continue;
            foreach (['bids','asks'] as $side) {
                if (!isset($book[$side]) || !is_array($book[$side])) continue;
                foreach ($book[$side] as &$level) {
                    if (!is_array($level)) continue;
                    if (array_key_exists(0, $level) && is_numeric($level[0])) $level[0] = self::toman($level[0]);
                    elseif (isset($level['price']) && is_numeric($level['price'])) $level['price'] = self::toman($level['price']);
                }
                unset($level);
            }
            foreach (['lastTradePrice','lastPrice','close','high','low','open'] as $field) {
                if (isset($book[$field]) && is_numeric($book[$field])) $book[$field] = self::toman($book[$field]);
            }
            $book['display_unit'] = 'TOMAN';
        }
        unset($book);
        return self::metadata($response, false);
    }

    /** Normalize authenticated order-list/status responses for presentation only. */
    public static function ordersResponse(array $response): array
    {
        return self::metadata(self::walkOrders($response), false);
    }

    public static function moneyFields(array $row, string $quote, array $fields): array
    {
        if (strtoupper(trim($quote)) !== 'IRT') return $row;
        foreach ($fields as $field) if (array_key_exists($field, $row) && is_numeric($row[$field])) $row[$field] = self::toman($row[$field]);
        return $row;
    }

    private static function walkOrders(array $node): array
    {
        if (self::looksLikeOrder($node)) $node = self::normalizeOrderRow($node);
        foreach ($node as $key => $value) {
            if (!is_array($value) || str_starts_with((string)$key, '_trade_')) continue;
            $node[$key] = self::walkOrders($value);
        }
        return $node;
    }

    private static function looksLikeOrder(array $row): bool
    {
        return isset($row['srcCurrency']) || isset($row['dstCurrency']) || isset($row['market']) || isset($row['symbol'])
            || (isset($row['type']) && (isset($row['price']) || isset($row['amount'])));
    }

    private static function normalizeOrderRow(array $row): array
    {
        $dst = strtoupper(trim((string)($row['dstCurrency'] ?? $row['dst_currency'] ?? '')));
        $market = strtoupper(trim((string)($row['market'] ?? $row['symbol'] ?? $row['marketCode'] ?? $row['market_code'] ?? '')));
        $rialQuote = in_array($dst, ['RLS','IRT'], true) || self::symbolUsesRialQuote($market);
        if (!$rialQuote) return $row;

        foreach (['price','averagePrice','avgPrice','average_price','totalOrderPrice','totalPrice','matchedTotalPrice','unmatchedTotalPrice','quoteValue','quote_value'] as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                $row['raw_'.$field] = $row[$field];
                $row[$field] = self::toman($row[$field]);
            }
        }
        if ($dst === 'RLS') {
            if (isset($row['dstCurrency'])) { $row['raw_dstCurrency']=$row['dstCurrency']; $row['dstCurrency']='IRT'; }
            if (isset($row['dst_currency'])) { $row['raw_dst_currency']=$row['dst_currency']; $row['dst_currency']='IRT'; }
        }
        $row['display_unit'] = 'TOMAN';
        return $row;
    }

    private static function symbolUsesRialQuote(string $symbol): bool
    {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        return $symbol !== '' && (str_ends_with($symbol, 'IRT') || str_ends_with($symbol, 'RLS'));
    }

    private static function metadata(array $response, bool $rawFields): array
    {
        $response['_trade_display'] = [
            'logical_quote'=>'IRT','exchange_native_quote'=>'RLS','display_unit'=>'TOMAN','rls_per_toman'=>self::RLS_PER_TOMAN,
            'public_irt_fields_normalized'=>true,'raw_exchange_values_available_under'=>$rawFields ? 'raw_* where applicable' : 'raw_* for order fields',
        ];
        return $response;
    }

    private static function toman(float|int|string $value): float
    {
        return round(((float)$value) / self::RLS_PER_TOMAN, 12);
    }

    private static function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) if (array_key_exists($key, $row) && is_numeric($row[$key])) return (float)$row[$key];
        return null;
    }
}
