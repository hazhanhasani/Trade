<?php

declare(strict_types=1);

namespace Trade\Trading\Strategy;

use Trade\Trading\NobitexMarketRegimeDetector;

final class MeanReversionStrategy implements NobitexStrategyInterface
{
    public function key(): string { return 'mean_reversion_v1'; }

    public function evaluate(array $context, array $regime): array
    {
        $prices=array_values((array)($context['prices']['1m']??[]));
        $slice=array_slice($prices,-60);
        $name=(string)($regime['regime']??'');
        $eligible=$name===NobitexMarketRegimeDetector::RANGING && count($slice)>=30;
        if(!$eligible){
            return ['key'=>$this->key(),'eligible'=>false,'entry_allowed'=>false,'exit_bias'=>false,'gross_edge_percent'=>0.0,'confidence'=>0,'reason'=>'regime_not_ranging','holding_horizon_minutes'=>120,'diagnostics'=>[]];
        }

        $price=(float)end($slice);
        $mean=array_sum($slice)/count($slice);
        $variance=0.0;
        foreach($slice as $p){$variance+=((float)$p-$mean)**2;}
        $std=sqrt($variance/max(1,count($slice)-1));
        $z=$std>0.0?($price-$mean)/$std:0.0;
        $distanceToMean=$price>0.0?(($mean-$price)/$price)*100.0:0.0;
        $i1=$context['indicators']['1m']??[];$i5=$context['indicators']['5m']??[];
        $rsi1=(float)($i1['rsi14']??50.0);$rsi5=(float)($i5['rsi14']??50.0);
        $m1=$this->clamp((float)($i1['momentum_5_percent']??0),-3,3);
        $m5=$this->clamp((float)($i5['momentum_5_percent']??0),-5,5);
        $imbalance=$this->clamp((float)($context['market']['orderbook_imbalance']??0),-1,1);

        $oversoldBoost=max(0.0,(45.0-$rsi1)/45.0)*0.35 + max(0.0,(48.0-$rsi5)/48.0)*0.20;
        $fallingKnifePenalty=($m1<-0.8?0.30:0.0)+($m5<-1.5?0.35:0.0)+($imbalance<-0.45?0.20:0.0);
        $gross=($distanceToMean*0.78)+$oversoldBoost+(max(0.0,$imbalance)*0.18)-$fallingKnifePenalty;
        if($z>0.0){
            $overextension=$price>0.0?(($price-$mean)/$price)*100.0:0.0;
            $gross=-(($overextension*0.70)+max(0.0,($rsi1-55.0)/45.0)*0.25);
        }

        $entry=$z<=-1.15&&$rsi1<=44.0&&$m5>-2.0&&$gross>0.0;
        $exit=$z>=0.70||$rsi1>=64.0||$gross<0.0;
        $confidence=(int)round($this->clamp(((float)($regime['confidence']??0)*0.58)+min(30.0,abs($z)*14.0)+(max(0.0,-$z)*8.0),0,100));

        return ['key'=>$this->key(),'eligible'=>true,'entry_allowed'=>$entry,'exit_bias'=>$exit,'gross_edge_percent'=>round($gross,4),'confidence'=>$confidence,'reason'=>$entry?'oversold_range_reversion':'range_reversion_not_entry_ready','holding_horizon_minutes'=>120,'diagnostics'=>['rolling_mean'=>$mean,'rolling_stddev'=>$std,'z_score'=>round($z,4),'distance_to_mean_percent'=>round($distanceToMean,4),'rsi_1m'=>round($rsi1,2),'rsi_5m'=>round($rsi5,2),'falling_knife_penalty_percent'=>round($fallingKnifePenalty,4),'orderbook_imbalance'=>round($imbalance,4)]];
    }

    private function clamp(float $v,float $min,float $max):float{return max($min,min($max,is_finite($v)?$v:$min));}
}
