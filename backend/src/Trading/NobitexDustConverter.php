<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Observability\ErrorReporter;
use Trade\Support\IranClock;

/**
 * Converts small idle crypto balances to IRT/Toman through real Nobitex sells.
 * Disabled by default and deliberately conservative.
 */
final class NobitexDustConverter
{
    public const MODEL = 'nobitex_idle_dust_to_toman_v1';
    private const ENABLED_KEY = 'nobitex_dust_conversion_enabled';
    private const MAX_TOMAN_KEY = 'nobitex_dust_max_toman';
    private const MIN_TOMAN_KEY = 'nobitex_dust_min_toman';
    private const COOLDOWN_KEY = 'nobitex_dust_cooldown_hours';
    private const LAST_RUN_KEY = 'nobitex_dust_last_run_utc';

    public function status(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        return [
            'model'=>self::MODEL,
            'enabled'=>$this->boolSetting($pdo,self::ENABLED_KEY,false),
            'min_toman'=>$this->numberSetting($pdo,self::MIN_TOMAN_KEY,1000.0,100.0,500000.0),
            'max_toman'=>$this->numberSetting($pdo,self::MAX_TOMAN_KEY,100000.0,1000.0,5000000.0),
            'cooldown_hours'=>(int)$this->numberSetting($pdo,self::COOLDOWN_KEY,6,1,168),
            'last_run_utc'=>$this->setting($pdo,self::LAST_RUN_KEY),
            'safety'=>'idle_assets_only_no_open_position_no_pending_order_max_3_per_run',
        ];
    }

    public function configure(bool $enabled, float $minToman, float $maxToman, int $cooldownHours = 6, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $minToman=max(100.0,min(500000.0,$minToman));
        $maxToman=max(1000.0,min(5000000.0,$maxToman));
        if($minToman>=$maxToman)throw new \InvalidArgumentException('حداقل دارایی خرد باید از حداکثر کمتر باشد.');
        $cooldownHours=max(1,min(168,$cooldownHours));
        $this->write($pdo,self::ENABLED_KEY,$enabled?'1':'0');
        $this->write($pdo,self::MIN_TOMAN_KEY,(string)$minToman);
        $this->write($pdo,self::MAX_TOMAN_KEY,(string)$maxToman);
        $this->write($pdo,self::COOLDOWN_KEY,(string)$cooldownHours);
        return $this->status($pdo);
    }

    public function runIfDue(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $status=$this->status($pdo);
        if(!$status['enabled'])return['status'=>'disabled']+$status;
        $last=(string)($status['last_run_utc']??'');
        $lastTs=$last!==''?strtotime($last.' UTC'):false;
        if($lastTs!==false && time()-$lastTs<((int)$status['cooldown_hours']*3600))return['status'=>'cooldown']+$status;
        return $this->run($pdo);
    }

    public function run(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $status=$this->status($pdo);
        if(!$status['enabled'])return['status'=>'disabled']+$status;
        $kill=(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0');
        if($kill==='1')return['status'=>'blocked','reason'=>'kill_switch'];
        if(!NobitexSchema::botEnabled('nobitex'))return['status'=>'blocked','reason'=>'nobitex_bot_disabled'];

        $service=new NobitexOrderService();
        if(!$service->liveEnabled())return['status'=>'blocked','reason'=>'nobitex_live_disabled'];
        $client=$service->client();
        $walletResponse=$client->wallets();
        $decorated=NobitexDisplayMoney::walletResponse($walletResponse);
        $wallets=$this->walletRows($decorated);
        $books=$client->allOrderBooks();
        $converted=[];$skipped=[];$failed=[];

        foreach($wallets as $wallet){
            if(count($converted)>=3)break;
            $currency=strtoupper((string)($wallet['display_currency']??$wallet['currency']??$wallet['asset']??''));
            if($currency===''||in_array($currency,['IRT','RLS','USDT'],true))continue;
            $amount=(float)($wallet['display_balance']??$wallet['activeBalance']??$wallet['available']??$wallet['free']??$wallet['balance']??0);
            $valueToman=(float)($wallet['display_value_toman']??0);
            if($amount<=0||$valueToman<(float)$status['min_toman']||$valueToman>(float)$status['max_toman'])continue;
            $symbol=$currency.'IRT';
            if($this->assetBusy($pdo,$currency,$symbol)){$skipped[]=['asset'=>$currency,'reason'=>'asset_in_trade_or_pending_order'];continue;}
            $book=$this->book($books,$symbol);
            $bestBid=$this->bestBid($book);
            if($bestBid<=0){$skipped[]=['asset'=>$currency,'reason'=>'irt_market_or_bid_unavailable'];continue;}
            $hardLimit=$bestBid*0.985;
            try{
                $order=$service->create([
                    'symbol'=>$symbol,
                    'side'=>'sell',
                    'mode'=>'market',
                    'amount'=>$amount,
                    'price'=>$hardLimit,
                ],'nobitex_dust_converter');
                $converted[]=['asset'=>$currency,'symbol'=>$symbol,'amount'=>$amount,'estimated_toman'=>$valueToman,'best_bid_rls'=>$bestBid,'order_local_id'=>$order['local_id']??null];
            }catch(\Throwable $e){
                $failed[]=['asset'=>$currency,'symbol'=>$symbol,'error'=>mb_substr($e->getMessage(),0,300)];
                ErrorReporter::captureThrowable($e,'error','dust_converter',['exchange'=>'nobitex','symbol'=>$symbol]);
            }
        }
        $this->write($pdo,self::LAST_RUN_KEY,gmdate('Y-m-d H:i:s'));
        $result=['status'=>'completed','model'=>self::MODEL,'converted'=>$converted,'skipped'=>$skipped,'failed'=>$failed,'time_iran'=>IranClock::nowPayload()];
        $this->audit($pdo,'nobitex.dust_conversion_run',$result);
        return$result;
    }

    private function walletRows(array $response): array
    {
        if(isset($response['wallets'])&&is_array($response['wallets']))return$response['wallets'];
        if(isset($response['data'])&&is_array($response['data'])){
            if(array_is_list($response['data']))return$response['data'];
            if(isset($response['data']['wallets'])&&is_array($response['data']['wallets']))return$response['data']['wallets'];
        }
        return[];
    }

    private function assetBusy(PDO $pdo,string $asset,string $symbol): bool
    {
        $s=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM nobitex_autotrade_positions WHERE asset=:asset AND status IN ('pending_open','open','pending_close'))");
        $s->execute([':asset'=>$asset]);if((bool)$s->fetchColumn())return true;
        $s=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM orders WHERE exchange_name='nobitex' AND market_code=:symbol AND status IN ('submitting','submitted') AND side IN ('buy','sell'))");
        $s->execute([':symbol'=>$symbol]);return(bool)$s->fetchColumn();
    }

    private function book(array $books,string $symbol): array
    {
        foreach([$symbol,strtolower($symbol)]as$key)if(isset($books[$key])&&is_array($books[$key]))return$books[$key];
        if(isset($books['orderbooks'])&&is_array($books['orderbooks']))foreach([$symbol,strtolower($symbol)]as$key)if(isset($books['orderbooks'][$key])&&is_array($books['orderbooks'][$key]))return$books['orderbooks'][$key];
        return[];
    }

    private function bestBid(array $book): float
    {
        $bids=$book['bids']??[];if(!is_array($bids)||$bids===[])return 0.0;
        $first=$bids[0]??null;
        if(is_array($first)){
            $p=$first[0]??$first['price']??null;
            return is_numeric($p)?(float)$p:0.0;
        }
        return 0.0;
    }

    private function setting(PDO $pdo,string $key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function boolSetting(PDO $pdo,string $key,bool $default):bool{$v=$this->setting($pdo,$key);if($v===null)return$default;return in_array(strtolower(trim($v)),['1','true','yes','on'],true);}
    private function numberSetting(PDO $pdo,string $key,float $default,float $min,float $max):float{$v=$this->setting($pdo,$key);$n=is_numeric($v)?(float)$v:$default;return max($min,min($max,$n));}
    private function write(PDO $pdo,string $key,string $value):void{$s=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function audit(PDO $pdo,string $event,array $context):void{$s=$pdo->prepare('INSERT INTO audit_logs(event_name,context_json,created_at) VALUES(:e,:c,UTC_TIMESTAMP())');$s->execute([':e'=>$event,':c'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
