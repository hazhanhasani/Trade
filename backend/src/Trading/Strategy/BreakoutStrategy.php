<?php

declare(strict_types=1);

namespace Trade\Trading\Strategy;

use Trade\Trading\NobitexMarketRegimeDetector;

final class BreakoutStrategy implements NobitexStrategyInterface
{
    public function key(): string { return 'breakout_v1'; }

    public function evaluate(array $context, array $regime): array
    {
        $metrics=is_array($regime['metrics']??null)?$regime['metrics']:[];
        $i1=$context['indicators']['1m']??[];$i5=$context['indicators']['5m']??[];$i15=$context['indicators']['15m']??[];
        $name=(string)($regime['regime']??'');
        $up=(float)($metrics['breakout_up_percent']??0.0);
        $down=(float)($metrics['breakout_down_percent']??0.0);
        $threshold=max(0.01,(float)($metrics['breakout_threshold_percent']??0.08));
        $m1=$this->clamp((float)($i1['momentum_5_percent']??0),-3,3);
        $m5=$this->clamp((float)($i5['momentum_5_percent']??0),-5,5);
        $m15=$this->clamp((float)($i15['momentum_5_percent']??0),-7,7);
        $rsi1=(float)($i1['rsi14']??50.0);
        $imbalance=$this->clamp((float)($context['market']['orderbook_imbalance']??0),-1,1);

        $eligible=in_array($name,[NobitexMarketRegimeDetector::BREAKOUT_UP,NobitexMarketRegimeDetector::BREAKOUT_DOWN],true);
        $gross=0.0;
        if($name===NobitexMarketRegimeDetector::BREAKOUT_UP){
            $extension=max(0.0,$up-$threshold);
            $exhaustion=$rsi1>=84.0?0.55:($rsi1>=79.0?0.25:0.0);
            $gross=($up*0.75)+($extension*0.60)+($m1*0.30)+($m5*0.24)+($m15*0.10)+(max(0.0,$imbalance)*0.28)-$exhaustion;
        }elseif($name===NobitexMarketRegimeDetector::BREAKOUT_DOWN){
            $extension=max(0.0,$down-$threshold);
            $gross=-(($down*0.70)+($extension*0.55)+(abs(min(0.0,$m1))*0.28)+(abs(min(0.0,$m5))*0.22)+(abs(min(0.0,$m15))*0.08)+(max(0.0,-$imbalance)*0.24));
        }

        $entry=$eligible&&$name===NobitexMarketRegimeDetector::BREAKOUT_UP&&$gross>0.0;
        $exit=$eligible&&($name===NobitexMarketRegimeDetector::BREAKOUT_DOWN||$gross<0.0);
        $strength=max($up,$down)/$threshold;
        $confidence=(int)round($this->clamp(((float)($regime['confidence']??0)*0.70)+min(25.0,$strength*10.0)+max(0.0,$imbalance)*5.0,0,100));

        return ['key'=>$this->key(),'eligible'=>$eligible,'entry_allowed'=>$entry,'exit_bias'=>$exit,'gross_edge_percent'=>round($gross,4),'confidence'=>$confidence,'reason'=>$eligible?($entry?'confirmed_upside_breakout':'breakout_not_entry_ready'):'regime_not_breakout','holding_horizon_minutes'=>90,'diagnostics'=>['breakout_up_percent'=>round($up,4),'breakout_down_percent'=>round($down,4),'breakout_threshold_percent'=>round($threshold,4),'momentum_1m_percent'=>round($m1,4),'momentum_5m_percent'=>round($m5,4),'orderbook_imbalance'=>round($imbalance,4)]];
    }

    private function clamp(float $v,float $min,float $max):float{return max($min,min($max,is_finite($v)?$v:$min));}
}
