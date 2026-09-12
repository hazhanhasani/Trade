<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Config;
use Trade\Exchange\BitpinClient;

/**
 * Legacy Bitpin compatibility service.
 *
 * Bitpin is now a read-only market-data source. Live order creation and
 * cancellation are intentionally impossible even if old database flags or
 * credentials still exist.
 */
final class OrderService
{
    public function client(): BitpinClient
    {
        return new BitpinClient([
            'base_url'=>Config::get('bitpin.base_url','https://api.bitpin.market/api/v1'),
            'api_key'=>'','secret_key'=>'','access_token'=>null,'refresh_token'=>null,
            'timeout'=>(int)Config::get('bitpin.timeout',8),
            'endpoints'=>Config::get('bitpin.endpoints',[]),
        ]);
    }

    public function syncTokens(BitpinClient $client): void
    {
        unset($client);
        // Deliberately no-op: Bitpin authentication is not used by Trade anymore.
    }

    public function liveEnabled(): bool
    {
        return false;
    }

    public function create(array $input, string $source = 'api'): array
    {
        unset($input, $source);
        throw new \RuntimeException('Bitpin execution is permanently disabled by project policy; Bitpin is market-data-only.');
    }

    public function cancel(string $exchangeOrderId): array
    {
        unset($exchangeOrderId);
        throw new \RuntimeException('Bitpin execution is permanently disabled by project policy; Bitpin is market-data-only.');
    }
}
