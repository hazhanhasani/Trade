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

    /**
     * Convert the dedicated public wallet response to the same Toman unit the
     * Nobitex UI shows. Raw exchange values are retained under raw_* keys.
     */
    public static function walletResponse(array $response): array
    {
        $decorate = static function (array $row): array {
            $currency = strtoupper(trim((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? '')));
            $displayCurrency = $currency === 'RLS' ? 'IRT' : $currency;

            $balance = self::firstNumeric($row, ['activeBalance','available','free','balance']);
            $displayBalance = $balance;
            if ($currency === 'RLS' && $balance !== null) $displayBalance = $balance / self::RLS_PER_TOMAN;

            $valueToman = null;
            if (isset($row['irtValue']) && is_numeric($row['irtValue'])) {
                $valueToman = (float)$row['irtValue'];
            } else {
                $rawRial = self::firstNumeric($row, ['rialValue','rial_value','valueRls','value_rls']);
                if ($rawRial !== null) $valueToman = $rawRial / self::RLS_PER_TOMAN;
            }
            if ($currency === 'RLS' && $valueToman === null && $balance !== null) $valueToman = $balance / self::RLS_PER_TOMAN;

            // Make legacy app consumers correct too: they currently read these
            // fields directly. Preserve exact exchange-native values first.
            foreach (['rialValue','rial_value','valueRls','value_rls'] as $field) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $row['raw_'.$field] = $row[$field];
                    $row[$field] = round(((float)$row[$field]) / self::RLS_PER_TOMAN, 8);
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

        $response['_trade_display'] = [
            'logical_quote'=>'IRT','exchange_native_quote'=>'RLS','display_unit'=>'TOMAN','rls_per_toman'=>self::RLS_PER_TOMAN,
            'legacy_public_fields_normalized'=>true,'raw_exchange_values_available_under'=>'raw_*',
        ];
        return $response;
    }

    public static function moneyFields(array $row, string $quote, array $fields): array
    {
        if (strtoupper(trim($quote)) !== 'IRT') return $row;
        foreach ($fields as $field) if (array_key_exists($field, $row) && is_numeric($row[$field])) $row[$field] = round(((float)$row[$field]) / self::RLS_PER_TOMAN, 8);
        return $row;
    }

    private static function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) if (array_key_exists($key, $row) && is_numeric($row[$key])) return (float)$row[$key];
        return null;
    }
}
