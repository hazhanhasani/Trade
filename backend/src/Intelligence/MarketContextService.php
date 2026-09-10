<?php

declare(strict_types=1);

namespace Trade\Intelligence;

use Trade\Support\IranClock;

/**
 * Read-only external market context. This service never gates or submits trades.
 * It exists for operator awareness and comparison with Nobitex prices.
 */
final class MarketContextService
{
    public const MODEL = 'cross_exchange_market_context_v1';
    private const CACHE_SECONDS = 90;

    public function snapshot(bool $force = false): array
    {
        $path = (defined('TRADE_ROOT') ? TRADE_ROOT : dirname(__DIR__,2)) . '/storage/market-context.json';
        if (!$force && is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            $ts = is_array($cached) ? (int)($cached['generated_unix'] ?? 0) : 0;
            if (is_array($cached) && $ts > 0 && time() - $ts <= self::CACHE_SECONDS) return $cached + ['cache'=>'hit'];
        }

        $markets = [
            'binance'=>$this->binance(),
            'coinbase'=>$this->coinbase(),
            'kraken'=>$this->kraken(),
        ];
        $news = $this->news();
        $healthy = 0;
        foreach ($markets as $row) if (($row['status'] ?? '') === 'ok') $healthy++;

        $out = [
            'model'=>self::MODEL,
            'purpose'=>'operator_context_only_not_a_trading_gate',
            'generated_unix'=>time(),
            'generated_at_utc'=>gmdate(DATE_ATOM),
            'generated_at_iran'=>IranClock::nowPayload(),
            'source_health'=>['healthy_exchanges'=>$healthy,'total_exchanges'=>count($markets),'news_status'=>$news['status']??'unknown'],
            'markets'=>$markets,
            'news'=>$news,
            'cache'=>'miss',
        ];
        if (is_dir(dirname($path)) && is_writable(dirname($path))) {
            @file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
        }
        return $out;
    }

    private function binance(): array
    {
        $symbols=['BTCUSDT','ETHUSDT','TONUSDT'];
        $out=[];$errors=[];
        foreach($symbols as $symbol){
            try{
                $j=$this->json('https://data-api.binance.vision/api/v3/ticker/24hr?symbol='.rawurlencode($symbol));
                $out[$symbol]=[
                    'symbol'=>$symbol,
                    'last'=>$this->num($j['lastPrice']??null),
                    'bid'=>$this->num($j['bidPrice']??null),
                    'ask'=>$this->num($j['askPrice']??null),
                    'change_24h_percent'=>$this->num($j['priceChangePercent']??null),
                    'quote_volume_24h'=>$this->num($j['quoteVolume']??null),
                ];
            }catch(\Throwable $e){$errors[$symbol]=mb_substr($e->getMessage(),0,180);}
        }
        return ['status'=>$out!==[]?'ok':'error','source'=>'Binance Spot public REST','quote'=>'USDT','items'=>$out,'errors'=>$errors];
    }

    private function coinbase(): array
    {
        $symbols=['BTC-USD','ETH-USD'];$out=[];$errors=[];
        foreach($symbols as $symbol){
            try{
                $j=$this->json('https://api.exchange.coinbase.com/products/'.rawurlencode($symbol).'/ticker');
                $out[$symbol]=[
                    'symbol'=>$symbol,
                    'last'=>$this->num($j['price']??null),
                    'bid'=>$this->num($j['bid']??null),
                    'ask'=>$this->num($j['ask']??null),
                    'volume_24h'=>$this->num($j['volume']??null),
                ];
            }catch(\Throwable $e){$errors[$symbol]=mb_substr($e->getMessage(),0,180);}
        }
        return ['status'=>$out!==[]?'ok':'error','source'=>'Coinbase Exchange public ticker','quote'=>'USD','items'=>$out,'errors'=>$errors];
    }

    private function kraken(): array
    {
        try{
            $j=$this->json('https://api.kraken.com/0/public/Ticker?pair=XBTUSD,ETHUSD');
            if (($j['error']??[])!==[]) throw new \RuntimeException('Kraken: '.implode(',',(array)$j['error']));
            $result=is_array($j['result']??null)?$j['result']:[];$out=[];
            foreach($result as $key=>$row){
                if(!is_array($row))continue;
                $out[$key]=[
                    'symbol'=>$key,
                    'last'=>$this->num($row['c'][0]??null),
                    'bid'=>$this->num($row['b'][0]??null),
                    'ask'=>$this->num($row['a'][0]??null),
                    'volume_24h'=>$this->num($row['v'][1]??null),
                ];
            }
            return ['status'=>$out!==[]?'ok':'error','source'=>'Kraken public ticker','quote'=>'USD','items'=>$out];
        }catch(\Throwable $e){return ['status'=>'error','source'=>'Kraken public ticker','items'=>[],'error'=>mb_substr($e->getMessage(),0,240)];}
    }

    private function news(): array
    {
        $sources=[
            ['name'=>'CoinDesk','url'=>'https://www.coindesk.com/arc/outboundfeeds/rss/?outputType=xml'],
            ['name'=>'Decrypt','url'=>'https://decrypt.co/feed'],
        ];
        $items=[];$errors=[];
        foreach($sources as $src){
            try{
                $xml=$this->text($src['url']);
                $feed=@simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NOCDATA|LIBXML_NONET);
                if($feed===false)throw new \RuntimeException('invalid RSS/XML');
                $nodes=[];
                if(isset($feed->channel->item))$nodes=$feed->channel->item;
                elseif(isset($feed->entry))$nodes=$feed->entry;
                $count=0;
                foreach($nodes as $node){
                    if($count>=8)break;
                    $title=trim((string)($node->title??''));
                    if($title==='')continue;
                    $link=trim((string)($node->link??''));
                    if($link===''&&isset($node->link['href']))$link=trim((string)$node->link['href']);
                    $published=trim((string)($node->pubDate??$node->published??$node->updated??''));
                    $ts=$published!==''?strtotime($published):false;
                    $utc=$ts?gmdate(DATE_ATOM,$ts):null;
                    $items[]=[
                        'source'=>$src['name'],
                        'title'=>mb_substr(strip_tags($title),0,300),
                        'link'=>$link,
                        'published_at_utc'=>$utc,
                        'time_iran'=>$utc?IranClock::fromUtc($utc):null,
                        'attention'=>$this->attention($title),
                    ];
                    $count++;
                }
            }catch(\Throwable $e){$errors[$src['name']]=mb_substr($e->getMessage(),0,180);}
        }
        usort($items,static function(array $a,array $b):int{
            $ta=strtotime((string)($a['published_at_utc']??''))?:0;$tb=strtotime((string)($b['published_at_utc']??''))?:0;return$tb<=>$ta;
        });
        return ['status'=>$items!==[]?'ok':'error','items'=>array_slice($items,0,12),'errors'=>$errors,'note'=>'Headlines are informational context only and never directly trigger a trade.'];
    }

    private function attention(string $title): array
    {
        $t=mb_strtolower($title);
        $high=['hack','exploit','breach','liquidat','sec ','etf','ban ','lawsuit','regulat','outage','delist','war ','attack'];
        $hits=[];foreach($high as $k)if(str_contains($t,$k))$hits[]=$k;
        return ['high'=>count($hits)>0,'keywords'=>$hits];
    }

    private function json(string $url): array
    {
        $raw=$this->text($url);
        $j=json_decode($raw,true);
        if(!is_array($j))throw new \RuntimeException('Invalid JSON response');
        return$j;
    }

    private function text(string $url): string
    {
        if(!extension_loaded('curl'))throw new \RuntimeException('cURL unavailable');
        $ch=curl_init($url);if($ch===false)throw new \RuntimeException('Unable to initialize request');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json, application/xml, text/xml;q=0.9, */*;q=0.7','User-Agent: Trade-MarketContext/1.0'],CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*']);
        try{$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally{curl_close($ch);}
        if($raw===false)throw new \RuntimeException('Network error: '.$err);
        if($code<200||$code>=300)throw new \RuntimeException('HTTP '.$code);
        return(string)$raw;
    }

    private function num(mixed $v): ?float{return is_numeric($v)?(float)$v:null;}
}
