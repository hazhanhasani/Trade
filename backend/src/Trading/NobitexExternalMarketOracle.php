<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Config;
use Trade\MarketData\MarketDataHub;

/**
 * Conservative external-market execution oracle.
 *
 * External venues may veto or reduce an internally profitable automated BUY,
 * but they can never manufacture positive edge, relax a Nobitex safety buffer,
 * or submit an order themselves.
 */
final class NobitexExternalMarketOracle
{
    public const MODEL = 'external_market_consensus_guard_v1';
    private const HARD_PREMIUM_BLOCK_PERCENT = 7.5;
    private const PENALTY_DEAD_BAND_PERCENT = 0.25;
    private const MAX_PENALTY_PERCENT = 0.30;
    private const MAX_SOURCE_AGE_SECONDS = 12;

    public function __construct(private readonly MarketDataHub $hub = new MarketDataHub()) {}

    /** @return array<string,mixed> */
    public function assess(array $market): array
    {
        if (!(bool)Config::get('market_data.external_consensus_enabled', true)) return self::unavailable('disabled_by_config');
        if (strtolower((string)getenv('CI')) === 'true') return self::unavailable('ci_network_disabled');

        $asset = strtoupper(trim((string)($market['asset'] ?? $market['exchange_asset'] ?? '')));
        $quote = strtoupper(trim((string)($market['quote_asset'] ?? 'IRT')));
        if ($asset === '' || !in_array($quote,['IRT','USDT'],true)) return self::unavailable('unsupported_market');

        $rawCandidate = self::candidateMid($market);
        if ($rawCandidate <= 0.0) return self::unavailable('invalid_candidate_price');
        $candidate = $quote === 'IRT' ? $rawCandidate / NobitexDisplayMoney::RLS_PER_TOMAN : $rawCandidate;

        try { $snapshot = $this->hub->snapshot($asset,$quote); }
        catch (\Throwable $e) { return self::unavailable('market_data_unavailable',$e->getMessage()); }

        // Never let a crossed/malformed or stale external book participate in an
        // execution decision. The collector is intentionally tolerant so admin
        // diagnostics can show provider responses; this oracle is the final live
        // execution quality gate and recomputes consensus from sanitized sources.
        $rawSources = is_array($snapshot['sources'] ?? null) ? $snapshot['sources'] : [];
        $sources = self::sanitizeSources($rawSources, time());
        $consensus = MarketDataHub::consensusFromQuotes($sources);
        $snapshot['sources'] = $sources;
        $snapshot['consensus'] = $consensus;
        $snapshot['execution_quality_gate'] = 'crossed_and_stale_sources_rejected';

        if (!($consensus['available'] ?? false)) return self::unavailable((string)($consensus['reason']??'consensus_unavailable')) + ['snapshot'=>$snapshot];

        return self::assessFromConsensus($candidate,$consensus) + [
            'asset'=>$asset,'quote'=>$quote,
            'candidate_price'=>round($candidate,12),
            'candidate_unit'=>$quote==='IRT'?'TOMAN':$quote,
            'snapshot'=>$snapshot,
        ];
    }

    /**
     * Pure quality gate for deterministic tests and execution diagnostics.
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,array<string,mixed>>
     */
    public static function sanitizeSources(array $sources, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach ($sources as $name => $row) {
            if (!is_array($row)) continue;
            $row['source'] = (string)($row['source'] ?? $name);
            if (($row['status'] ?? '') !== 'ok') {
                $out[(string)$name] = $row;
                continue;
            }

            $bid = self::positive($row['bid'] ?? 0);
            $ask = self::positive($row['ask'] ?? 0);
            if ($bid > 0.0 && $ask > 0.0 && $ask < $bid) {
                $row['status'] = 'error';
                $row['reason'] = 'crossed_orderbook_rejected';
                $row['quality_error'] = 'best_ask_below_best_bid';
                $out[(string)$name] = $row;
                continue;
            }

            $received = isset($row['received_unix']) && is_numeric($row['received_unix']) ? (int)$row['received_unix'] : 0;
            if ($received > 0 && max(0,$now-$received) > self::MAX_SOURCE_AGE_SECONDS) {
                $row['status'] = 'error';
                $row['reason'] = 'stale_external_quote_rejected';
                $row['age_seconds'] = max(0,$now-$received);
                $out[(string)$name] = $row;
                continue;
            }

            $out[(string)$name] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function assessFromConsensus(float $candidatePrice,array $consensus):array
    {
        $reference=is_numeric($consensus['reference_price']??null)?(float)$consensus['reference_price']:0.0;
        if($candidatePrice<=0||$reference<=0||!($consensus['available']??false))return self::unavailable('consensus_unavailable');
        $basis=(($candidatePrice-$reference)/$reference)*100.0;
        $quality=(bool)($consensus['quality_ready']??false);
        $sources=max(0,(int)($consensus['source_count']??0));
        $dispersion=max(0.0,(float)($consensus['dispersion_percent']??0.0));
        $hard=$quality&&$sources>=2&&$basis>=self::HARD_PREMIUM_BLOCK_PERCENT;
        $premium=max(0.0,$basis-self::PENALTY_DEAD_BAND_PERCENT);
        $penalty=$quality&&$sources>=2?min(self::MAX_PENALTY_PERCENT,($premium*0.06)+min(0.08,$dispersion*0.02)):0.0;
        return[
            'available'=>true,'quality_ready'=>$quality,'hard_block'=>$hard,
            'reason'=>$hard?'external_market_extreme_premium':($quality?'external_market_consensus_ready':'external_market_consensus_low_quality'),
            'reference_price'=>round($reference,12),'basis_percent'=>round($basis,6),
            'penalty_percent'=>round($penalty,4),'source_count'=>$sources,'dispersion_percent'=>round($dispersion,6),
            'model'=>self::MODEL,'safety_mode'=>'harden_only_never_add_edge',
        ];
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $reason,?string $error=null):array
    {
        $out=['available'=>false,'quality_ready'=>false,'hard_block'=>false,'reason'=>$reason,'penalty_percent'=>0.0,'model'=>self::MODEL,'safety_mode'=>'harden_only_never_add_edge'];
        if($error!==null&&trim($error)!=='')$out['error']=mb_substr(trim($error),0,220);
        return$out;
    }

    private static function candidateMid(array $market):float
    {
        $ask=self::positive($market['best_ask']??0);$bid=self::positive($market['best_bid']??0);
        if($ask>0&&$bid>0&&$ask>=$bid)return($ask+$bid)/2.0;
        return self::positive($market['price']??0);
    }

    private static function positive(mixed$v):float
    {
        if(!is_numeric($v))return 0.0;
        $n=(float)$v;
        return is_finite($n)&&$n>0?$n:0.0;
    }
}
