<?php

declare(strict_types=1);

namespace Trade\Trading\Strategy;

use Trade\Trading\NobitexMarketRegimeDetector;

final class TrendMomentumStrategy implements NobitexStrategyInterface
{
    public function key(): string { return 'trend_momentum_v1'; }

    public function evaluate(array $context, array $regime): array
    {
        $i1=$context['indicators']['1m']??[];$i5=$context['indicators']['5m']??[];$i15=$context['indicators']['15m']??[];
        $m1=$this->clamp((float)($i1['momentum_5_percent']??0),-2,2);
        $m5=$this->clamp((float)($i5['momentum_5_percent']??0),-4,4);
        $m15=$this->clamp((float)($i15['momentum_5_percent']??0),-6,6);
        $e1=$this->clamp((float)($i1['ema_gap_percent']??0),-1.5,1.5);
        $e5=$this->clamp((float)($i5['ema_gap_percent']??0),-2.5,2.5);
        $e15=$this->clamp((float)($i15['ema_gap_percent']??0),-4,4);
        $h1=$this->clamp((float)($i1['macd_histogram_percent']??0),-0.6,0.6);
        $h5=$this->clamp((float)($i5['macd_histogram_percent']??0),-0.8,0.8);
        $h15=$this->clamp((float)($i15['macd_histogram_percent']??0),-1,1);
        $t1=$this->clamp((float)($i1['trend_consistency']??0.5),0,1);
        $t5=$this->clamp((float)($i5['trend_consistency']??0.5),0,1);
        $t15=$this->clamp((float)($i15['trend_consistency']??0.5),0,1);
        $rsi1=(float)($i1['rsi14']??50);$rsi5=(float)($i5['rsi14']??50);
        $imbalance=$this->clamp((float)($context['market']['orderbook_imbalance']??0),-1,1);

        $momentum=($m1*0.45)+($m5*0.38)+($m15*0.17);
        $trend=($e1*0.20)+($e5*0.45)+($e15*0.35);
        $macd=($h1*0.20)+($h5*0.45)+($h15*0.35);
        $consistency=($t1*0.20)+($t5*0.45)+($t15*0.35);
        $exhaustion=0.0;
        if($rsi1>=78)$exhaustion+=0.45; elseif($rsi1>=72)$exhaustion+=0.18;
        if($rsi5>=76)$exhaustion+=0.28; elseif($rsi5>=70)$exhaustion+=0.12;
        $gross=($momentum*0.52)+($trend*0.36)+($macd*0.78)+(($consistency-0.5)*0.72)+($imbalance*0.30)-$exhaustion;

        $name=(string)($regime['regime']??'');
        $eligible=in_array($name,[NobitexMarketRegimeDetector::TRENDING_UP,NobitexMarketRegimeDetector::TRENDING_DOWN],true);
        $entry=$eligible&&$name===NobitexMarketRegimeDetector::TRENDING_UP&&$gross>0.0;
        $exit=$eligible&&($name===NobitexMarketRegimeDetector::TRENDING_DOWN||$gross<0.0);
        $confidence=(int)round($this->clamp(((float)($regime['confidence']??0)*0.65)+(abs($gross)*15)+($consistency*20),0,100));

        return ['key'=>$this->key(),'eligible'=>$eligible,'entry_allowed'=>$entry,'exit_bias'=>$exit,'gross_edge_percent'=>round($gross,4),'confidence'=>$confidence,'reason'=>$eligible?($entry?'trend_alignment_positive':'trend_not_entry_ready'):'regime_not_trend','holding_horizon_minutes'=>180,'diagnostics'=>['momentum_blend_percent'=>round($momentum,4),'trend_blend_percent'=>round($trend,4),'macd_pressure_percent'=>round($macd,4),'trend_consistency'=>round($consistency,4),'exhaustion_penalty_percent'=>round($exhaustion,4)]];
    }

    private function clamp(float $v,float $min,float $max):float{return max($min,min($max,is_finite($v)?$v:$min));}
}
