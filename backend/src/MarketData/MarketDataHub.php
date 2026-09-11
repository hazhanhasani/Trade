<?php

declare(strict_types=1);

namespace Trade\MarketData;

/**
 * Read-only multi-exchange quote collector.
 *
 * Canonical external IRT prices are TOMAN. Nobitex keeps IRT markets in native
 * RLS internally; the trading oracle performs the explicit RLS/10 conversion.
 * No method in this class can submit, cancel or alter an exchange order.
 */
final class MarketDataHub
{
    public const MODEL = 'multi_exchange_market_data_v1';
    public const IRT_UNIT = 'TOMAN';
    private const MAX_CACHE_AGE_SECONDS = 8;
    private const MAX_FINAL_OUTLIER_PERCENT = 3.5;
    private const MAX_UNIT_OUTLIER_PERCENT = 12.0;

    /** @var array<string,array<string,mixed>> */
    private static array $runtimeCache = [];

    public function __construct(private readonly MarketDataCredentialStore $credentials = new MarketDataCredentialStore()) {}

    /** @return array<string,mixed> */
    public function snapshot(string $asset, string $quote = 'IRT', bool $force = false): array
    {
        $asset = $this->asset($asset);
        $quote = $this->quote($quote);
        $cacheKey = $asset . ':' . $quote;
        $cached = self::$runtimeCache[$cacheKey] ?? null;
        if (!$force && is_array($cached) && time() - (int)($cached['generated_unix'] ?? 0) <= self::MAX_CACHE_AGE_SECONDS) {
            return $cached + ['cache'=>'hit'];
        }

        $plans = $this->requestPlans($asset, $quote);
        $responses = $this->executePlans($plans);
        $sources = [];
        foreach ($responses as $source => $response) {
            try {
                $sources[$source] = $this->parseSource($source, $asset, $quote, $response);
            } catch (\Throwable $e) {
                $sources[$source] = [
                    'status'=>'error','source'=>$source,'asset'=>$asset,'quote'=>$quote,
                    'error'=>mb_substr($e->getMessage(), 0, 220),
                ];
            }
        }
        foreach (['bitpin','tabdeal','abantether','bit24'] as $source) {
            if (isset($sources[$source])) continue;
            $sources[$source] = [
                'status'=>'unavailable','source'=>$source,'asset'=>$asset,'quote'=>$quote,
                'reason'=>$this->unavailableReason($source, $quote),
            ];
        }

        $consensus = self::consensusFromQuotes($sources);
        $out = [
            'model'=>self::MODEL,
            'purpose'=>'read_only_market_intelligence',
            'asset'=>$asset,'quote'=>$quote,
            'canonical_quote_unit'=>$quote === 'IRT' ? self::IRT_UNIT : $quote,
            'generated_unix'=>time(),'generated_at_utc'=>gmdate(DATE_ATOM),
            'sources'=>$sources,'consensus'=>$consensus,'cache'=>'miss',
        ];
        self::$runtimeCache[$cacheKey] = $out;
        return $out;
    }

    public static function clearRuntime(): void { self::$runtimeCache = []; }

    /**
     * Deterministic robust consensus. Only normalized mid prices participate.
     * A first broad filter catches unit mistakes; a second filter catches stale
     * or venue-specific dislocations. At least two agreeing sources are required.
     *
     * @param array<string,array<string,mixed>> $quotes
     * @return array<string,mixed>
     */
    public static function consensusFromQuotes(array $quotes): array
    {
        $values = [];
        foreach ($quotes as $source => $row) {
            if (!is_array($row) || ($row['status'] ?? '') !== 'ok') continue;
            $mid = self::finitePositive($row['mid'] ?? null);
            if ($mid <= 0.0) continue;
            $values[(string)$source] = $mid;
        }
        if (count($values) < 2) return self::unavailableConsensus('insufficient_sources', $values);

        $initialMedian = self::median(array_values($values));
        $broad = [];
        $rejected = [];
        foreach ($values as $source => $value) {
            $deviation = self::deviationPercent($value, $initialMedian);
            if ($deviation > self::MAX_UNIT_OUTLIER_PERCENT) $rejected[$source] = ['price'=>$value,'deviation_percent'=>round($deviation,4),'stage'=>'unit_guard'];
            else $broad[$source] = $value;
        }
        if (count($broad) < 2) return self::unavailableConsensus('unit_consensus_failed', $values, $rejected);

        $median = self::median(array_values($broad));
        $inliers = [];
        foreach ($broad as $source => $value) {
            $deviation = self::deviationPercent($value, $median);
            if ($deviation > self::MAX_FINAL_OUTLIER_PERCENT) $rejected[$source] = ['price'=>$value,'deviation_percent'=>round($deviation,4),'stage'=>'market_outlier'];
            else $inliers[$source] = $value;
        }
        if (count($inliers) < 2) return self::unavailableConsensus('market_consensus_failed', $values, $rejected);

        $reference = self::median(array_values($inliers));
        $min = min($inliers); $max = max($inliers);
        $dispersion = $reference > 0.0 ? (($max - $min) / $reference) * 100.0 : 99.0;
        return [
            'available'=>true,'quality_ready'=>$dispersion <= 3.0,
            'reference_price'=>round($reference, 12),
            'source_count'=>count($inliers),'raw_source_count'=>count($values),
            'sources'=>array_keys($inliers),'prices'=>$inliers,'rejected'=>$rejected,
            'dispersion_percent'=>round($dispersion, 6),
            'method'=>'median_two_stage_outlier_filter',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function requestPlans(string $asset, string $quote): array
    {
        $plans = [
            'bitpin'=>[
                'url'=>'https://api.bitpin.market/api/v1/mth/orderbook/' . rawurlencode($asset . '_' . $quote) . '/',
                'headers'=>['Accept: application/json'],
            ],
            'tabdeal'=>[
                'url'=>'https://api1.tabdeal.org/r/api/v1/depth?' . http_build_query(['symbol'=>$asset . $quote,'limit'=>5]),
                'headers'=>['Accept: application/json'],
            ],
        ];

        if ($quote === 'IRT') {
            try {
                $credential = $this->credentials->credentials('abantether');
                if (is_array($credential) && trim((string)$credential['api_key']) !== '') {
                    $plans['abantether'] = [
                        'url'=>'https://mono.abantether.com/api/v1/otc/coin-price/?' . http_build_query(['coin'=>$asset]),
                        'headers'=>['Accept: application/json','Authorization: ' . trim((string)$credential['api_key'])],
                    ];
                }
            } catch (\Throwable) {}
        }

        try {
            $credential = $this->credentials->credentials('bit24');
            if (is_array($credential) && trim((string)$credential['api_key']) !== '') {
                $plans['bit24'] = [
                    'url'=>'https://rest.bit24.cash/pro/capi/v1/markets/order-books?' . http_build_query([
                        'base_coin'=>strtolower($asset),'quote_coin'=>strtolower($quote),
                    ]),
                    'headers'=>['Accept: application/json','X-BIT24-APIKEY: ' . trim((string)$credential['api_key'])],
                ];
            }
        } catch (\Throwable) {}

        return $plans;
    }

    /** @param array<string,array<string,mixed>> $plans @return array<string,array<string,mixed>> */
    private function executePlans(array $plans): array
    {
        if (!extension_loaded('curl')) {
            $out=[]; foreach($plans as $source=>$plan)$out[$source]=['ok'=>false,'error'=>'cURL unavailable','status'=>0,'body'=>'']; return $out;
        }
        if (!function_exists('curl_multi_init')) {
            $out=[]; foreach($plans as $source=>$plan)$out[$source]=$this->singleRequest($plan); return $out;
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            $out=[]; foreach($plans as $source=>$plan)$out[$source]=$this->singleRequest($plan); return $out;
        }
        $handles = [];
        foreach ($plans as $source => $plan) {
            $ch = $this->handle($plan);
            if ($ch === false) { $handles[$source]=null; continue; }
            $handles[$source] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                $selected = curl_multi_select($multi, 0.25);
                if ($selected === -1) usleep(20000);
            }
        } while ($active && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $source => $ch) {
            if ($ch === null) { $out[$source]=['ok'=>false,'error'=>'Unable to initialize cURL','status'=>0,'body'=>'']; continue; }
            $body = curl_multi_getcontent($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $out[$source] = ['ok'=>$errno===0 && $code>=200 && $code<300,'error'=>$error,'errno'=>$errno,'status'=>$code,'body'=>(string)$body];
            curl_multi_remove_handle($multi, $ch); curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function singleRequest(array $plan): array
    {
        $ch = $this->handle($plan);
        if ($ch === false) return ['ok'=>false,'error'=>'Unable to initialize cURL','status'=>0,'body'=>''];
        $body = curl_exec($ch); $error=curl_error($ch); $errno=curl_errno($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        return ['ok'=>$body!==false && $errno===0 && $code>=200 && $code<300,'error'=>$error,'errno'=>$errno,'status'=>$code,'body'=>$body===false?'':(string)$body];
    }

    /** @param array<string,mixed> $plan */
    private function handle(array $plan): \CurlHandle|false
    {
        $ch = curl_init((string)$plan['url']);
        if ($ch === false) return false;
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>array_merge((array)($plan['headers']??[]),['User-Agent: Trade-MarketData/1.0']),
            CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        ]);
        return $ch;
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function parseSource(string $source, string $asset, string $quote, array $response): array
    {
        if (!($response['ok'] ?? false)) {
            $message = trim((string)($response['error'] ?? ''));
            if ($message === '') $message = 'HTTP ' . (int)($response['status'] ?? 0);
            throw new \RuntimeException($message);
        }
        $json = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($json)) throw new \RuntimeException('Invalid JSON response.');

        [$bid,$ask,$last] = match($source) {
            'tabdeal' => $this->bookPrices($json),
            'bitpin' => $this->bookPrices($json),
            'bit24' => $this->bit24Prices($json),
            'abantether' => $this->abanPrices($json, $asset),
            default => [0.0,0.0,0.0],
        };
        $mid = self::mid($bid,$ask,$last);
        if ($mid <= 0.0) throw new \RuntimeException('No usable market price in response.');

        return [
            'status'=>'ok','source'=>$source,'asset'=>$asset,'quote'=>$quote,
            'quote_unit'=>$quote === 'IRT' ? self::IRT_UNIT : $quote,
            'bid'=>$bid>0?round($bid,12):null,'ask'=>$ask>0?round($ask,12):null,'last'=>$last>0?round($last,12):null,
            'mid'=>round($mid,12),'received_unix'=>time(),
            'spread_percent'=>($bid>0&&$ask>0&&$ask>=$bid)?round((($ask-$bid)/$mid)*100,6):null,
            'authenticated'=>in_array($source,['abantether','bit24'],true),
        ];
    }

    /** @return array{0:float,1:float,2:float} */
    private function bookPrices(array $json): array
    {
        $node = $this->unwrap($json);
        $bids = is_array($node['bids'] ?? null) ? $node['bids'] : (is_array($node['buy_orders'] ?? null)?$node['buy_orders']:[]);
        $asks = is_array($node['asks'] ?? null) ? $node['asks'] : (is_array($node['sell_orders'] ?? null)?$node['sell_orders']:[]);
        $bid = $this->bestLevel($bids, true); $ask = $this->bestLevel($asks, false);
        $last = $this->firstNumeric($node,['last','lastPrice','last_price','price','each_price']);
        return [$bid,$ask,$last];
    }

    /** @return array{0:float,1:float,2:float} */
    private function bit24Prices(array $json): array
    {
        $node = $this->unwrap($json);
        $bids = is_array($node['buy_orders'] ?? null)?$node['buy_orders']:[];
        $asks = is_array($node['sell_orders'] ?? null)?$node['sell_orders']:[];
        return [$this->bestLevel($bids,true),$this->bestLevel($asks,false),0.0];
    }

    /** @return array{0:float,1:float,2:float} */
    private function abanPrices(array $json, string $asset): array
    {
        $candidates = [];
        $this->collectPriceCandidates($json, strtoupper($asset), $candidates);
        $buy=[];$sell=[];$generic=[];
        foreach($candidates as $row){
            $value=self::finitePositive($row['value']??null); if($value<=0)continue;
            $key=strtolower((string)($row['key']??''));
            if(str_contains($key,'buy'))$buy[]=$value;
            elseif(str_contains($key,'sell'))$sell[]=$value;
            else $generic[]=$value;
        }
        $bid=$buy!==[]?self::median($buy):0.0;
        $ask=$sell!==[]?self::median($sell):0.0;
        $last=$generic!==[]?self::median($generic):0.0;
        if($bid<=0&&$ask<=0&&$last<=0&&$candidates!==[]){$all=array_map(static fn(array$r):float=>(float)$r['value'],$candidates);$last=self::median(array_values(array_filter($all,static fn(float$v):bool=>$v>0)));}
        return[$bid,$ask,$last];
    }

    private function collectPriceCandidates(mixed $node, string $asset, array &$out, string $path=''): void
    {
        if(!is_array($node))return;
        foreach($node as$key=>$value){
            $k=(string)$key;$p=$path===''?$k:$path.'.'.$k;
            if(is_array($value)){$this->collectPriceCandidates($value,$asset,$out,$p);continue;}
            if(!is_numeric($value))continue;
            $lower=strtolower($p);
            if(!preg_match('/(price|irt|toman|buy|sell)/',$lower))continue;
            if(preg_match('/(percent|change|volume|amount|qty|id|time|count)/',$lower))continue;
            $out[]=['key'=>$p,'value'=>(float)$value];
        }
    }

    private function unwrap(array $json): array
    {
        $node=$json;
        foreach(['data','result','results','orderbook','order_book']as$key){
            if(isset($node[$key])&&is_array($node[$key]))$node=$node[$key];
        }
        if(array_is_list($node)&&isset($node[0])&&is_array($node[0]))return$node[0];
        return$node;
    }

    private function bestLevel(array $levels, bool $highest): float
    {
        $best=0.0;
        foreach($levels as$level){
            if(!is_array($level))continue;
            $value=array_is_list($level)?($level[0]??null):($level['price']??$level['each_price']??null);
            $price=self::finitePositive($value); if($price<=0)continue;
            if($best<=0||($highest?$price>$best:$price<$best))$best=$price;
        }
        return$best;
    }

    private function firstNumeric(array $node,array $keys):float{foreach($keys as$key)if(isset($node[$key])&&is_numeric($node[$key]))return self::finitePositive($node[$key]);return 0.0;}
    private function unavailableReason(string $source,string $quote):string{if($source==='abantether'&&$quote!=='IRT')return'irt_only_source';if(in_array($source,['abantether','bit24'],true))return'credentials_not_configured_or_unavailable';return'request_not_available';}
    private function asset(string $asset):string{$asset=strtoupper(trim($asset));if(!preg_match('/^[A-Z0-9]{2,15}$/',$asset))throw new \InvalidArgumentException('Invalid market-data asset.');return$asset;}
    private function quote(string $quote):string{$quote=strtoupper(trim($quote));if(!in_array($quote,['IRT','USDT'],true))throw new \InvalidArgumentException('Market-data quote must be IRT or USDT.');return$quote;}
    private static function mid(float $bid,float $ask,float $last):float{if($bid>0&&$ask>0&&$ask>=$bid)return($bid+$ask)/2.0;if($last>0)return$last;return max($bid,$ask);}
    private static function finitePositive(mixed $v):float{if(!is_numeric($v))return 0.0;$n=(float)$v;return is_finite($n)&&$n>0?$n:0.0;}
    private static function deviationPercent(float $value,float $reference):float{return$reference>0?abs(($value-$reference)/$reference)*100.0:99.0;}
    private static function median(array $values):float{$values=array_values(array_filter(array_map(static fn($v):float=>is_numeric($v)?(float)$v:0.0,$values),static fn(float$v):bool=>$v>0&&is_finite($v)));if($values===[])return 0.0;sort($values,SORT_NUMERIC);$n=count($values);$m=intdiv($n,2);return$n%2===1?$values[$m]:($values[$m-1]+$values[$m])/2.0;}
    private static function unavailableConsensus(string $reason,array $values=[],array $rejected=[]):array{return['available'=>false,'quality_ready'=>false,'reason'=>$reason,'reference_price'=>null,'source_count'=>0,'raw_source_count'=>count($values),'sources'=>[],'prices'=>[],'rejected'=>$rejected,'dispersion_percent'=>null,'method'=>'median_two_stage_outlier_filter'];}
}
