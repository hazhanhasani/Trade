<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

final class NobitexGlobalRiskRuntime
{
    private const RLS_PER_TOMAN = 10.0;

    private static float $multiplier=1.0;
    private static array $snapshot=[];

    public function activate(?PDO $pdo=null,?NobitexClient $client=null):array
    {
        $pdo??=Database::connection();
        $client??=(new NobitexOrderService())->client();
        $wallets=$client->wallets();
        $positions=$pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,mark_price,status FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();
        $valuation=(new NobitexPortfolioValuation())->snapshot($client,$wallets,$positions);
        $limit=$this->settingFloat($pdo,'nobitex_portfolio_exposure_percent',60.0,10.0,90.0);
        $maxPositions=$this->settingInt($pdo,'nobitex_max_positions',5,1,20);
        $base=$pdo->query('SELECT position_percent,max_position_percent FROM autotrade_settings WHERE id=1')->fetch() ?: [];
        $configured=min(max(0.1,(float)($base['position_percent']??5.0)),max(0.1,(float)($base['max_position_percent']??10.0)));
        $effective=min($configured,$limit/max(1,$maxPositions));

        // Risk math must stay in exchange-native RLS. Display fields are Toman.
        $totalRls=(float)($valuation['portfolio_value_rls']??0.0);
        $exposureRls=(float)($valuation['exposure_rls']??0.0);
        $capacityRls=max(0.0,($totalRls*($limit/100.0))-$exposureRls);
        $desiredRls=$totalRls*($effective/100.0);
        $multiplier=$desiredRls>0.0?min(1.0,$capacityRls/$desiredRls):1.0;
        if(!($valuation['conversion_ready']??false)&&$this->isMixedPortfolio($valuation))$multiplier=0.0;
        self::$multiplier=max(0.0,min(1.0,$multiplier));
        self::$snapshot=$valuation+[
            'global_exposure_limit_percent'=>$limit,
            'global_exposure_capacity_irt'=>round($this->rlsToToman($capacityRls),8),
            'global_exposure_capacity_rls'=>round($capacityRls,8),
            'base_effective_position_percent'=>round($effective,4),
            'global_position_size_multiplier'=>round(self::$multiplier,4),
            'entry_blocked'=>self::$multiplier<=0.000001,
            'entry_block_reason'=>(!($valuation['conversion_ready']??false)&&$this->isMixedPortfolio($valuation))?'global_portfolio_valuation_unavailable':($capacityRls<=0.0?'global_portfolio_exposure_limit_reached':null),
        ];
        return self::$snapshot;
    }

    public static function runtimePositionMultiplier():float{return max(0.0,min(1.0,self::$multiplier));}
    public static function runtimeSnapshot():array{return self::$snapshot;}
    public static function clear():void{self::$multiplier=1.0;self::$snapshot=[];}

    public function assertFreshAutomatedBuy(PDO $pdo,NobitexClient $client,string $symbol,float $amount,float $price,?int $excludePositionId=null):array
    {
        $wallets=$client->wallets();
        $positions=$pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,mark_price,status FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();
        if($excludePositionId!==null&&$excludePositionId>0){
            $positions=array_values(array_filter($positions,static fn(array $row):bool=>(int)($row['id']??0)!==$excludePositionId));
        }
        $valuation=(new NobitexPortfolioValuation())->snapshot($client,$wallets,$positions);
        $limit=$this->settingFloat($pdo,'nobitex_portfolio_exposure_percent',60.0,10.0,90.0);
        if(!($valuation['conversion_ready']??false)&&$this->isMixedPortfolio($valuation)){
            throw new NobitexCandidateRejectedException($symbol,'global_portfolio_valuation_unavailable',['valuation'=>$valuation,'limit_percent'=>$limit,'excluded_position_id'=>$excludePositionId]);
        }

        $quote=str_ends_with(strtoupper($symbol),'USDT')?'USDT':'IRT';
        $orderQuote=max(0.0,$amount*$price);
        $usdtToRls=(float)($valuation['usdt_to_rls_rate']??0);
        $orderRls=$quote==='IRT'?$orderQuote:($usdtToRls>0?$orderQuote*$usdtToRls:0.0);
        if($orderRls<=0.0)throw new NobitexCandidateRejectedException($symbol,'global_portfolio_order_valuation_unavailable',['valuation'=>$valuation,'excluded_position_id'=>$excludePositionId]);

        $totalRls=max(0.0,(float)($valuation['portfolio_value_rls']??0));
        $currentRls=max(0.0,(float)($valuation['exposure_rls']??0));
        $projectedRls=$currentRls+$orderRls;
        $projectedPct=$totalRls>0?($projectedRls/$totalRls)*100.0:100.0;
        if($totalRls<=0.0||$projectedPct>$limit+0.0001){
            throw new NobitexCandidateRejectedException($symbol,'global_portfolio_exposure_limit_reached',[
                'valuation'=>$valuation,
                'order_value_irt'=>round($this->rlsToToman($orderRls),8),
                'order_value_rls'=>round($orderRls,8),
                'projected_exposure_irt'=>round($this->rlsToToman($projectedRls),8),
                'projected_exposure_rls'=>round($projectedRls,8),
                'projected_exposure_percent'=>round($projectedPct,4),
                'limit_percent'=>$limit,
                'excluded_position_id'=>$excludePositionId,
            ]);
        }
        return [
            'allowed'=>true,
            'order_value_irt'=>round($this->rlsToToman($orderRls),8),
            'order_value_rls'=>round($orderRls,8),
            'projected_exposure_percent'=>round($projectedPct,4),
            'limit_percent'=>$limit,
            'valuation'=>$valuation,
            'excluded_position_id'=>$excludePositionId,
        ];
    }

    private function isMixedPortfolio(array $v):bool
    {
        $cash=$v['cash_by_quote']??[];$notional=$v['active_notional_by_quote']??[];
        $hasIrt=(float)($cash['IRT']??0)+(float)($notional['IRT']??0)>0;
        $hasUsdt=(float)($cash['USDT']??0)+(float)($notional['USDT']??0)>0;
        return $hasIrt&&$hasUsdt;
    }

    private function rlsToToman(float $value):float{return $value/self::RLS_PER_TOMAN;}
    private function settingFloat(PDO $pdo,string $key,float $default,float $min,float $max):float{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return is_numeric($v)?max($min,min($max,(float)$v)):$default;}
    private function settingInt(PDO $pdo,string $key,int $default,int $min,int $max):int{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return is_numeric($v)?max($min,min($max,(int)$v)):$default;}
}
