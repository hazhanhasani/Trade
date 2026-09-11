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
        $consensus = is_array($snapshot['consensus'] ?? null)?$snapshot['consensus']:[];
        if (!($consensus['available'] ?? false)) return self::unavailable((string)($consensus['reason']??'consensus_unavailable')) + ['snapshot'=>$snapshot];

        return self::assessFromConsensus($candidate,$consensus) + [
            'asset'=>$asset,'quote'=>$quote,
            'candidate_price'=>round($candidate,12),
            'candidate_unit'=>$quote==='IRT'?'TOMAN':$quote,
            'snapshot'=>$snapshot,
        ];
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
    private static function positive(mixed$v):float{if(!is_numeric($v))return 0.0;$n=(float)$v;return is_finite($n)&&$n>0?$n:0.0;}
}
