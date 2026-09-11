<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Public order books can expose scaled display symbols that are not accepted
 * by Nobitex's authenticated spot order API as srcCurrency values.
 *
 * Examples observed from the exchange: 100KFLOKI, 1KBONK, 1MPEPE, 1KSHIB.
 * Mapping those aliases to the underlying asset would also require an exact
 * amount/price scale conversion, so the safe policy is to exclude them from
 * automated execution until Nobitex exposes an authoritative executable
 * mapping. Regular numeric names such as 1INCH remain valid.
 */
final class NobitexSymbolPolicy
{
    public const REJECTION_REASON = 'nobitex_order_api_multiplier_alias_unsupported';

    public static function isOrderApiCompatibleBase(string $base): bool
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($base)) ?? '');
        if ($base === '') return false;
        return !self::isScaledMultiplierAlias($base);
    }

    public static function isScaledMultiplierAlias(string $base): bool
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($base)) ?? '');
        if ($base === '') return false;

        // Do not match legitimate assets such as 1INCH. Only explicit K/M
        // multiplier prefixes are blocked.
        return preg_match('/^(?:1K|10K|100K|1M|10M|100M)[A-Z0-9]/', $base) === 1;
    }

    public static function assessment(string $symbol, string $base): array
    {
        return [
            'symbol'=>strtoupper($symbol),
            'base'=>strtoupper($base),
            'reason'=>self::REJECTION_REASON,
            'safety'=>'scaled_public_market_alias_not_sent_to_authenticated_order_api',
        ];
    }
}
