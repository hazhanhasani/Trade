<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Repairs only strong x10 / ÷10 unit shifts between cached OHLC history and
 * the current live price. It does not smooth ordinary market jumps.
 */
final class NobitexPriceSeriesSanitizer
{
    /** @return list<float> */
    public static function normalizeToLiveUnit(array $prices, float $livePrice): array
    {
        $clean=[];
        foreach($prices as $price){
            if(!is_numeric($price)) continue;
            $n=(float)$price;
            if(is_finite($n)&&$n>0.0) $clean[]=$n;
        }
        if($livePrice<=0.0||count($clean)<6) return $clean;

        $recent=array_slice($clean,-12);
        sort($recent,SORT_NUMERIC);
        $n=count($recent);
        $median=$n%2===1
            ? (float)$recent[intdiv($n,2)]
            : (((float)$recent[$n/2-1]+(float)$recent[$n/2])/2.0);
        if($median<=0.0) return $clean;

        $ratio=$livePrice/$median;
        $scale=1.0;
        // Nobitex history/live endpoints can disagree on Rial vs Toman by x10.
        // Keep the tolerance deliberately narrow so real market moves are not
        // silently rewritten.
        if($ratio>=9.2&&$ratio<=10.8) $scale=10.0;
        elseif($ratio>=0.092&&$ratio<=0.108) $scale=0.1;
        else return $clean;

        foreach($clean as &$price) $price*=$scale;
        unset($price);
        return $clean;
    }
}
