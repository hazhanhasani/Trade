<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

final class NobitexMarketScanner
{
    private const CANONICAL_ASSET = 'GRAM';
    private const ASSET_ALIASES = ['GRAM', 'TON', 'TONCOIN'];

    public function snapshot(NobitexClient $client, string $preferredQuote = 'IRT'): array
    {
        $preferredQuote = strtoupper(trim($preferredQuote));
        if (!in_array($preferredQuote, ['USDT', 'IRT'], true)) $preferredQuote = 'IRT';

        $all = $client->allOrderBooks();
        $market = $this->chooseMarket($all, $preferredQuote);
        if ($market === null) throw new \RuntimeException('No GRAM/TON IRT or USDT market was found in Nobitex order books.');

        $prices = [];
        try {
            $history = $client->ohlc($market['symbol'], '15', 120);
            $closes = $history['c'] ?? [];
            if (is_array($closes)) foreach ($closes as $close) { $n=$this->number($close); if($n>0)$prices[]=$n; }
        } catch (\Throwable) {}
        if ($prices === [] || abs((float)end($prices)-$market['price']) > 0.00000001) $prices[]=$market['price'];

        $change=0.0;
        try {
            $stats=$client->stats(['srcCurrency'=>strtolower($market['exchange_asset']),'dstCurrency'=>strtolower($market['quote_asset']==='IRT'?'rls':$market['quote_asset'])]);
            $change=$this->extractDayChange($stats,$market['symbol']);
        } catch (\Throwable) {}

        $options=[];
        try {$options=$client->options();} catch (\Throwable) {}
        $basePrecision=$this->amountPrecision($options,$market['symbol'],8);
        $minOrder=$this->minimumOrder($options,$market['quote_asset']);

        return [
            'exchange'=>'nobitex',
            'asset'=>self::CANONICAL_ASSET,
            'exchange_asset'=>$market['exchange_asset'],
            'quote_asset'=>$market['quote_asset'],
            'quote_priority'=>['IRT','USDT'],
            'market_id'=>$this->compatibilityMarketId($market['symbol']),
            'symbol'=>$market['symbol'],
            'price'=>$market['price'],
            'best_ask'=>$market['best_ask'],
            'best_bid'=>$market['best_bid'],
            'change_percent'=>$change,
            'base_precision'=>$basePrecision,
            'min_order_quote'=>$minOrder,
            'prices'=>array_slice($prices,-120),
            'orderbook_imbalance'=>$market['imbalance'],
            'observed_at'=>gmdate(DATE_ATOM),
        ];
    }

    private function chooseMarket(array $response,string $preferredQuote):?array
    {
        $markets=[];
        foreach($response as $rawSymbol=>$book){
            if(!is_string($rawSymbol)||!is_array($book)||in_array(strtolower($rawSymbol),['status','lastupdate'],true))continue;
            $symbol=strtoupper(preg_replace('/[^A-Z0-9]/','',$rawSymbol)??'');
            [$base,$quote]=$this->parseSymbol($symbol);
            if(!$this->isTarget($base)||!in_array($quote,['USDT','IRT'],true))continue;
            $last=$this->number($book['lastTradePrice']??$book['last_trade_price']??0);
            $bestAsk=$this->levelPrice($book['asks'][0]??null);
            $bestBid=$this->levelPrice($book['bids'][0]??null);
            $price=$last>0?$last:(($bestAsk>0&&$bestBid>0)?($bestAsk+$bestBid)/2:max($bestAsk,$bestBid));
            if($price<=0)continue;
            $markets[]=['symbol'=>$symbol,'exchange_asset'=>$base,'quote_asset'=>$quote,'price'=>$price,'best_ask'=>$bestAsk,'best_bid'=>$bestBid,'imbalance'=>$this->imbalance($book)];
        }
        if($markets===[])return null;
        usort($markets,function(array $a,array $b)use($preferredQuote):int{
            $rank=static function(array $m)use($preferredQuote):int{
                $asset=match($m['exchange_asset']){'GRAM'=>0,'TON'=>1,'TONCOIN'=>2,default=>3};
                if($m['quote_asset']===$preferredQuote)return$asset;
                // Project policy: IRT is primary, USDT is automatic fallback.
                $quote=$m['quote_asset']==='IRT'?10:20;
                return$quote+$asset;
            };
            return$rank($a)<=>$rank($b);
        });
        return$markets[0];
    }

    private function minimumOrder(array $options,string $quote):float
    {
        $n=$this->nobitexOptions($options);
        $mins=is_array($n['minOrders']??null)?$n['minOrders']:[];
        $keys=$quote==='IRT'?['rls','RLS','irt','IRT']:['usdt','USDT'];
        foreach($keys as $key){$v=$this->number($mins[$key]??0);if($v>0)return$v;}
        // Defensive fallbacks only; live values from /v2/options take precedence.
        return $quote==='IRT'?3000000.0:11.0;
    }

    private function amountPrecision(array $options,string $symbol,int $fallback):int
    {
        $n=$this->nobitexOptions($options);
        $rows=is_array($n['amountPrecisions']??null)?$n['amountPrecisions']:[];
        foreach([$symbol,strtolower($symbol),strtoupper($symbol)]as$key){if(array_key_exists($key,$rows))return$this->precisionFromStep($rows[$key],$fallback);}
        return$fallback;
    }

    private function nobitexOptions(array $options):array
    {
        if(is_array($options['nobitex']??null))return$options['nobitex'];
        if(is_array($options['data']['nobitex']??null))return$options['data']['nobitex'];
        return[];
    }

    private function precisionFromStep(mixed $value,int $fallback):int
    {
        if(!is_numeric($value))return$fallback;
        $s=strtolower(trim((string)$value));
        if(str_contains($s,'e-')){$parts=explode('e-',$s,2);return max(0,min(18,(int)($parts[1]??$fallback)));}
        if(!str_contains($s,'.'))return 0;
        $dec=rtrim(substr(strrchr($s,'.'),1),'0');
        return max(0,min(18,strlen($dec)));
    }

    private function parseSymbol(string $symbol):array
    {
        foreach(['USDT','IRT']as$quote)if(str_ends_with($symbol,$quote)&&strlen($symbol)>strlen($quote))return[substr($symbol,0,-strlen($quote)),$quote];
        return['',''];
    }
    private function isTarget(string $asset):bool{return in_array(strtoupper($asset),self::ASSET_ALIASES,true);}
    private function levelPrice(mixed $level):float{if(!is_array($level))return 0.0;return$this->number(array_is_list($level)?($level[0]??0):($level['price']??0));}
    private function imbalance(array $book):float{$bv=$this->volume(is_array($book['bids']??null)?$book['bids']:[]);$av=$this->volume(is_array($book['asks']??null)?$book['asks']:[]);$t=$bv+$av;return$t>0?max(-1.0,min(1.0,($bv-$av)/$t)):0.0;}
    private function volume(array $levels):float{$sum=0.0;foreach(array_slice($levels,0,20)as$level){if(!is_array($level))continue;$sum+=$this->number(array_is_list($level)?($level[1]??0):($level['amount']??$level['volume']??0));}return$sum;}
    private function extractDayChange(array $stats,string $symbol):float{$nodes=$stats['stats']??$stats['data']??[];if(!is_array($nodes))return 0.0;foreach($nodes as$key=>$row){if(!is_array($row))continue;$normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$key)??'');if($normalized!==''&&$normalized!==$symbol)continue;foreach(['dayChange','day_change','change']as$field){$v=$this->number($row[$field]??0);if($v!==0.0)return$v;}}return 0.0;}
    private function compatibilityMarketId(string $symbol):int{return(int)(sprintf('%u',crc32('nobitex:'.$symbol))?:1);}
    private function number(mixed $value):float{if(!is_numeric($value))return 0.0;$n=(float)$value;return is_finite($n)?$n:0.0;}
}
