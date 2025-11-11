<?php

namespace App\Support\Greeks;

final class ImpliedVol
{
    /**
     * Solve for sigma given observed option price.
     * Method: bounded bisection with 60 iters max.
     */
    public static function fromPrice(
        string $type, float $S, float $K, float $T,
        float $r, float $q, float $price,
        float $lo = 0.01, float $hi = 5.0, int $iters = 60
    ): ?float {
        // Guard rails
        $type = strtolower($type) === 'put' ? 'put' : 'call';
        if ($price <= 0) return null;

        $f = function(float $sigma) use($type,$S,$K,$T,$r,$q) {
            return BlackScholes::greeks($type, $S, $K, $T, $sigma, $r, $q)['price'];
        };

        $plo = $f($lo); $phi = $f($hi);
        if (!is_finite($plo) || !is_finite($phi)) return null;

        // If target outside bounds, expand once
        if ($price < $plo) return $lo;
        if ($price > $phi) return $hi;

        for ($i=0; $i<$iters; $i++) {
            $mid = 0.5*($lo+$hi);
            $pm  = $f($mid);
            if (abs($pm - $price) < 1e-6) return $mid;
            if ($pm > $price) $hi = $mid; else $lo = $mid;
        }
        return 0.5*($lo+$hi);
    }
}
