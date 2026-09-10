<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\BitpinClient;

/**
 * Score-free full-universe Bitpin scanner.
 *
 * Every tradable IRT/USDT spot market is observed. Price history is persisted
 * from all-market ticker/market responses, with a bounded recent-trades warmup
 * for new symbols. Order books are requested only when they can still change a
 * market's economic decision (spread/imbalance), not because of a rank score.
 */
final class MarketScanner
{
    private const QUOTES = ['IRT','USDT'];
    private const EXCLUDED_BASES = ['IRT','RLS','USDT','USDC','DAI','TUSD','BUSD','FDUSD'];
    private const MAX_MARKET_PAGES = 100;
    private const WARMUP_TRADES_PER_TICK = 16;
    private const MAX_IMBALANCE_EDGE_CONTRIBUTION = 0.30;

    private int $lastScannedRecords = 0;
    private int $lastScannedPages = 0;
    private static array $processMarkets = [];
    private static array $processSnapshots = [];
    private static bool $cacheSchemaEnsured = false;

    public function __construct(private readonly SignalEngine $signals = new SignalEngine()) {}

    public static function resetProcessCache(): void
    {
        self::$processMarkets = [];
        self::$processSnapshots = [];
    }

    /**
     * Backward-compatible single snapshot. It now returns the best currently
     * profitable market from the complete universe instead of GRAM/TON only.
     */
    public function snapshot(BitpinClient $client, string $preferredQuote = 'IRT'): array
    {
        $candidates = $this->rankedCandidates($client, $preferredQuote);
        foreach ($candidates as $market) {
            if (($market['signal']['ready'] ?? false) && (($market['signal']['action'] ?? '') === 'buy')) return $market;
        }
        if ($candidates !== []) return $candidates[0];
        throw new \RuntimeException('No executable Bitpin IRT/USDT market was found.');
    }

    public function rankedCandidates(BitpinClient $client, string $preferredQuote = 'IRT'): array
    {
        $preferredQuote = $this->quote($preferredQuote);
        $markets = $this->loadMarkets($client);
        if ($markets === []) return [];

        try { $tickers = $this->tickerMap($client->tickers()); } catch (\Throwable) { $tickers = []; }
        foreach ($markets as &$market) {
            $ticker = $tickers[$this->symbolKey((string) $market['symbol'])] ?? null;
            if (is_array($ticker)) {
                $price = $this->number($ticker['price'] ?? $ticker['last_price'] ?? $ticker['last'] ?? 0);
                if ($price > 0) $market['price'] = $price;
                $change = $this->number($ticker['daily_change_percent'] ?? $ticker['change_percent'] ?? $ticker['change'] ?? $ticker['daily_change_price'] ?? 0);
                if ($change !== 0.0) $market['change_percent'] = $change;
            }
        }
        unset($market);

        $pdo = Database::connection();
        $this->ensureCacheSchema($pdo);
        $cache = $this->loadHistoryCache($pdo);

        // Warm new/short histories without exploding the number of public calls.
        $warmQueue = $markets;
        usort($warmQueue, static function(array $a,array $b) use($cache):int {
            $ac = is_array($cache[(string)$a['symbol']]??null) ? (int)($cache[(string)$a['symbol']]['sample_count']??0) : 0;
            $bc = is_array($cache[(string)$b['symbol']]??null) ? (int)($cache[(string)$b['symbol']]['sample_count']??0) : 0;
            return $ac <=> $bc;
        });
        $seeded = [];
        foreach (array_slice($warmQueue, 0, self::WARMUP_TRADES_PER_TICK) as $market) {
            $symbol = (string)$market['symbol'];
            $cachedCount = (int)($cache[$symbol]['sample_count']??0);
            if ($cachedCount >= 26) continue;
            try {
                $series = $this->priceSeries($this->records($client->recentTrades($symbol)));
                if ($series !== []) $seeded[$symbol] = $series;
            } catch (\Throwable) {}
        }

        $minute = gmdate('Y-m-d H:i:00');
        $upsert = $pdo->prepare(
            "INSERT INTO bitpin_market_history_cache (symbol,prices_json,sample_count,last_observed_minute,updated_at)
             VALUES (:symbol,:prices,:count,:minute,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE prices_json=VALUES(prices_json),sample_count=VALUES(sample_count),last_observed_minute=VALUES(last_observed_minute),updated_at=UTC_TIMESTAMP()"
        );

        $analyzed = [];
        foreach ($markets as $market) {
            if ((float)$market['price'] <= 0) continue;
            $symbol = (string)$market['symbol'];
            $cached = is_array($cache[$symbol]??null) ? $cache[$symbol] : [];
            $prices = isset($seeded[$symbol]) ? $seeded[$symbol] : $this->cachedPrices($cached);
            $prices = $this->mergeLivePrice($prices, (float)$market['price'], (string)($cached['last_observed_minute']??''), $minute);

            $upsert->execute([
                ':symbol'=>$symbol,
                ':prices'=>json_encode($prices,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                ':count'=>count($prices),
                ':minute'=>$minute,
            ]);

            $market['prices']=$prices;
            $market['spread_percent']=0.0;
            $market['orderbook_imbalance']=0.0;
            $market['full_universe_analysis']=true;
            $market['history_samples']=count($prices);
            $preliminary=$this->signals->analyze($market);
            $market['signal']=$preliminary;

            // Spread can only worsen edge; bid imbalance can improve the model by
            // at most 0.30 percentage points. If a market is within that bound,
            // fetch its order book and make the final economic decision.
            $preEdge=(float)($preliminary['expected_net_edge_percent']??-999.0);
            if (($preliminary['ready']??false) && $preEdge > -(self::MAX_IMBALANCE_EDGE_CONTRIBUTION + 0.01)) {
                try {
                    $book=$client->orderBook($symbol);
                    $micro=$this->orderBookMetrics($book,(float)$market['price']);
                    $market=array_merge($market,$micro);
                    $market['signal']=$this->signals->analyze($market);
                    $market['execution_book_checked']=true;
                } catch (\Throwable) {
                    // Never buy without an execution-spread check.
                    $market['spread_percent']=99.0;
                    $market['signal']=$this->signals->analyze($market);
                    $market['execution_book_checked']=false;
                }
            } else {
                $market['execution_book_checked']=false;
            }

            $signal=$market['signal'];
            $market['expected_net_edge_percent']=round((float)($signal['expected_net_edge_percent']??0.0),4);
            $market['expected_net_profit']=(bool)($signal['expected_net_profit']??false);
            $analyzed[]=$market;
            self::$processSnapshots[$symbol]=$market;
        }

        usort($analyzed, static function(array $a,array $b) use($preferredQuote):int {
            $ab=(($a['signal']['action']??'')==='buy')?1:0;
            $bb=(($b['signal']['action']??'')==='buy')?1:0;
            if($ab!==$bb)return $bb<=>$ab;
            $ae=(float)($a['expected_net_edge_percent']??-999);
            $be=(float)($b['expected_net_edge_percent']??-999);
            if(abs($ae-$be)>0.000001)return $be<=>$ae;
            $ap=(($a['quote_asset']??'')===$preferredQuote)?1:0;
            $bp=(($b['quote_asset']??'')===$preferredQuote)?1:0;
            return $bp<=>$ap;
        });
        return $analyzed;
    }

    public function snapshotSymbol(BitpinClient $client,string $symbol):array
    {
        $symbol=strtoupper(trim($symbol));
        if(isset(self::$processSnapshots[$symbol]))return self::$processSnapshots[$symbol];

        $markets=$this->loadMarkets($client);
        $target=null;
        foreach($markets as $market){if(strtoupper((string)$market['symbol'])===$symbol){$target=$market;break;}}
        if($target===null)throw new \RuntimeException('Bitpin market is unavailable: '.$symbol);

        if((float)$target['price']<=0){
            try{$ticker=$this->tickerMap($client->tickers())[$this->symbolKey($symbol)]??null;if(is_array($ticker))$target['price']=$this->number($ticker['price']??$ticker['last_price']??0);}catch(\Throwable){}
        }
        if((float)$target['price']<=0)throw new \RuntimeException('Bitpin returned no usable price for '.$symbol.'.');

        $pdo=Database::connection();$this->ensureCacheSchema($pdo);$cached=$this->loadOneHistoryCache($pdo,$symbol);$prices=$this->cachedPrices($cached);
        if(count($prices)<26){try{$seed=$this->priceSeries($this->records($client->recentTrades($symbol)));if($seed!==[])$prices=$seed;}catch(\Throwable){}}
        $minute=gmdate('Y-m-d H:i:00');$prices=$this->mergeLivePrice($prices,(float)$target['price'],(string)($cached['last_observed_minute']??''),$minute);
        $this->saveHistory($pdo,$symbol,$prices,$minute);

        $target['prices']=$prices;$target['full_universe_analysis']=false;$target['history_samples']=count($prices);
        try{$target=array_merge($target,$this->orderBookMetrics($client->orderBook($symbol),(float)$target['price']));$target['execution_book_checked']=true;}
        catch(\Throwable){$target['spread_percent']=99.0;$target['orderbook_imbalance']=0.0;$target['execution_book_checked']=false;}
        $target['signal']=$this->signals->analyze($target);
        $target['expected_net_edge_percent']=round((float)($target['signal']['expected_net_edge_percent']??0.0),4);
        $target['expected_net_profit']=(bool)($target['signal']['expected_net_profit']??false);
        self::$processSnapshots[$symbol]=$target;
        return $target;
    }

    private function loadMarkets(BitpinClient $client): array
    {
        if(self::$processMarkets!==[])return self::$processMarkets;
        $all=[];$seen=[];$rawLoaded=0;$page=1;
        while($page<=self::MAX_MARKET_PAGES){
            $response=$client->markets(['page'=>$page]);$records=$this->records($response);$this->lastScannedPages=$page;if($records===[])break;
            $rawLoaded+=count($records);
            foreach($records as$record){$m=$this->normalizeMarket($record);if($m===null||!$m['tradable'])continue;if(!in_array($m['quote'],self::QUOTES,true)||in_array($m['base'],self::EXCLUDED_BASES,true))continue;$key=$m['symbol'];if(isset($seen[$key]))continue;$seen[$key]=true;$all[]=$m;}
            if(array_is_list($response))break;$next=$response['next']??null;if(is_string($next)&&trim($next)!==''){$page++;continue;}$count=(int)($response['count']??0);if($count>0&&$rawLoaded<$count){$page++;continue;}break;
        }
        $this->lastScannedRecords=$rawLoaded;self::$processMarkets=$all;return$all;
    }

    private function normalizeMarket(array $row): ?array
    {
        $id=max(0,(int)($row['id']??$row['market_id']??0));$c1=is_array($row['currency1']??null)?$row['currency1']:[];$c2=is_array($row['currency2']??null)?$row['currency2']:[];
        $base=strtoupper(trim((string)($row['base']??$c1['code']??'')));$quote=strtoupper(trim((string)($row['quote']??$c2['code']??'')));$raw=strtoupper(trim((string)($row['symbol']??$row['code']??'')));
        [$pb,$pq]=$this->parseSymbol($raw);if($base==='')$base=$pb;if($quote==='')$quote=$pq;$symbol=$raw!==''?$raw:(($base!==''&&$quote!=='')?$base.'_'.$quote:'');if($base===''||$quote===''||$symbol==='')return null;if($id<=0)$id=$this->stableMarketId($symbol);
        $price=$this->number($row['price']??$row['price_info']['price']??$row['order_book_info']['price']??$row['last_price']??0);$change=$this->number($row['change_percent']??$row['daily_change_percent']??$row['price_info']['change']??$row['order_book_info']['change']??0);$precision=(int)($row['base_amount_precision']??$c1['decimal_amount']??8);
        $tradable=array_key_exists('tradable',$row)?(bool)$row['tradable']:(array_key_exists('tradable',$c1)?(bool)$c1['tradable']:true);
        return['exchange'=>'bitpin','asset'=>$base,'exchange_asset'=>$base,'quote_asset'=>$quote,'market_id'=>$id,'symbol'=>$symbol,'tradable'=>$tradable,'price'=>$price,'change_percent'=>$change,'base_precision'=>max(0,min(18,$precision)),'observed_at'=>gmdate(DATE_ATOM)];
    }

    private function ensureCacheSchema(PDO $pdo):void
    {
        if(self::$cacheSchemaEnsured)return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS bitpin_market_history_cache (symbol VARCHAR(80) NOT NULL PRIMARY KEY,prices_json LONGTEXT NOT NULL,sample_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,last_observed_minute DATETIME NULL,updated_at DATETIME NOT NULL,INDEX idx_bitpin_history_updated(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");self::$cacheSchemaEnsured=true;
    }

    private function loadHistoryCache(PDO $pdo):array{$out=[];foreach($pdo->query('SELECT symbol,prices_json,sample_count,last_observed_minute FROM bitpin_market_history_cache')->fetchAll()as$row){if(is_array($row))$out[strtoupper((string)$row['symbol'])]=$row;}return$out;}
    private function loadOneHistoryCache(PDO $pdo,string $symbol):array{$s=$pdo->prepare('SELECT symbol,prices_json,sample_count,last_observed_minute FROM bitpin_market_history_cache WHERE symbol=:s LIMIT 1');$s->execute([':s'=>$symbol]);$r=$s->fetch();return is_array($r)?$r:[];}
    private function saveHistory(PDO $pdo,string $symbol,array $prices,string $minute):void{$s=$pdo->prepare("INSERT INTO bitpin_market_history_cache(symbol,prices_json,sample_count,last_observed_minute,updated_at)VALUES(:s,:p,:c,:m,UTC_TIMESTAMP())ON DUPLICATE KEY UPDATE prices_json=VALUES(prices_json),sample_count=VALUES(sample_count),last_observed_minute=VALUES(last_observed_minute),updated_at=UTC_TIMESTAMP()");$s->execute([':s'=>$symbol,':p'=>json_encode(array_slice($prices,-480),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),':c'=>count($prices),':m'=>$minute]);}
    private function cachedPrices(array $row):array{$d=json_decode((string)($row['prices_json']??'[]'),true);return is_array($d)?$this->cleanPrices($d):[];}
    private function cleanPrices(array $prices):array{$o=[];foreach($prices as$p){$n=$this->number($p);if($n>0)$o[]=$n;}return array_slice($o,-480);}
    private function mergeLivePrice(array $prices,float $price,string $lastMinute,string $minute):array{$prices=$this->cleanPrices($prices);if($price<=0)return$prices;if($lastMinute===$minute&&$prices!==[])$prices[count($prices)-1]=$price;else$prices[]=$price;return array_slice($prices,-480);}

    private function orderBookMetrics(array $book,float $fallbackPrice):array
    {
        $bids=$book['bids']??$book['buy']??$book['orders']['bids']??[];$asks=$book['asks']??$book['sell']??$book['orders']['asks']??[];$bids=is_array($bids)?$bids:[];$asks=is_array($asks)?$asks:[];
        $bestBid=$this->levelPrice($bids[0]??null);$bestAsk=$this->levelPrice($asks[0]??null);$ref=$fallbackPrice>0?$fallbackPrice:(($bestBid>0&&$bestAsk>0)?(($bestBid+$bestAsk)/2):max($bestBid,$bestAsk));
        $spread=($ref>0&&$bestAsk>0&&$bestBid>0)?max(0.0,(($bestAsk-$bestBid)/$ref)*100):99.0;$bidVol=$this->bookVolume($bids);$askVol=$this->bookVolume($asks);$total=$bidVol+$askVol;$imb=$total>0?max(-1.0,min(1.0,($bidVol-$askVol)/$total)):0.0;
        return['best_bid'=>$bestBid,'best_ask'=>$bestAsk,'spread_percent'=>round($spread,6),'orderbook_imbalance'=>$imb];
    }
    private function levelPrice(mixed $level):float{if(!is_array($level))return 0.0;return$this->number(array_is_list($level)?($level[0]??0):($level['price']??0));}
    private function bookVolume(array $levels):float{$sum=0.0;foreach(array_slice($levels,0,20)as$l){if(is_array($l)&&array_is_list($l))$sum+=$this->number($l[1]??0);elseif(is_array($l))$sum+=$this->number($l['amount']??$l['volume']??$l['size']??0);}return$sum;}

    private function tickerMap(array $response):array{$out=[];foreach($this->records($response)as$row){$s=strtoupper((string)($row['symbol']??$row['code']??$row['market']['code']??''));$k=$this->symbolKey($s);if($k!=='')$out[$k]=$row;}return$out;}
    private function priceSeries(array $trades):array{$series=[];foreach($trades as$row){$p=$this->number($row['price']??$row['average_price']??0);if($p<=0)continue;$t=strtotime((string)($row['created_at']??$row['time']??''))?:0;$series[]=['time'=>$t,'price'=>$p];}if($series===[])return[];$has=count(array_filter($series,static fn(array$x):bool=>$x['time']>0))>=2;if($has)usort($series,static fn(array$a,array$b):int=>$a['time']<=>$b['time']);else$series=array_reverse($series);return array_values(array_map(static fn(array$x):float=>(float)$x['price'],array_slice($series,-120)));}
    private function records(array $response):array{if(array_is_list($response))return array_values(array_filter($response,'is_array'));foreach(['results','data','items','markets']as$key){if(!isset($response[$key])||!is_array($response[$key]))continue;$v=$response[$key];if(array_is_list($v))return array_values(array_filter($v,'is_array'));foreach(['results','data','items']as$n){if(isset($v[$n])&&is_array($v[$n])&&array_is_list($v[$n]))return array_values(array_filter($v[$n],'is_array'));}}return[];}
    private function parseSymbol(string $symbol):array{if($symbol==='')return['',''];$n=str_replace(['-','/'],'_',$symbol);$p=array_values(array_filter(explode('_',$n),static fn(string$x):bool=>$x!==''));if(count($p)>=2)return[$p[0],$p[count($p)-1]];foreach(self::QUOTES as$q){if(str_ends_with($symbol,$q)&&strlen($symbol)>strlen($q))return[substr($symbol,0,-strlen($q)),$q];}return['',''];}
    private function quote(string $quote):string{$q=strtoupper(trim($quote));return in_array($q,self::QUOTES,true)?$q:'IRT';}
    private function symbolKey(string $symbol):string{return strtoupper(preg_replace('/[^A-Z0-9]/','',$symbol)??'');}
    private function stableMarketId(string $symbol):int{$id=(int)sprintf('%u',crc32($this->symbolKey($symbol)));return$id>0?$id:1;}
    private function number(mixed $value):float{if(!is_numeric($value))return 0.0;$n=(float)$value;return is_finite($n)?$n:0.0;}
}
