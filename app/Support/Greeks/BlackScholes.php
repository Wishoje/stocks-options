<?php

namespace App\Support\Greeks;

final class BlackScholes
{
    // Φ(x) CDF
    private static function normCdf(float $x): float {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x)/sqrt(2.0);
        $a1=0.254829592; $a2=-0.284496736; $a3=1.421413741; $a4=-1.453152027; $a5=1.061405429; $p=0.3275911;
        $t = 1.0/(1.0+$p*$x);
        $y = 1.0 - ((((( $a5*$t + $a4)*$t + $a3)*$t + $a2)*$t + $a1)*$t)*exp(-$x*$x);
        return 0.5*(1.0 + $sign*$y);
    }
    // φ(x) PDF
    private static function normPdf(float $x): float {
        return exp(-0.5*$x*$x)/sqrt(2.0*M_PI);
    }

    private static function ensure(float $v, float $min): float { return max($min, $v); }

    /**
     * $type: 'call'|'put'
     * Returns [price, delta, gamma, theta, vega, rho]
     * Units: theta per day, vega per 1 vol pt (0.01), rho per 1% rate.
     */
    public static function greeks(string $type, float $S, float $K, float $T, float $sigma, float $r=0.0, float $q=0.0): array
    {
        $S = self::ensure($S, 1e-9);
        $K = self::ensure($K, 1e-9);
        $T = self::ensure($T, 1e-9);
        $sigma = self::ensure($sigma, 1e-9);

        $sqrtT = sqrt($T);
        $d1 = (log($S/$K) + ($r - $q + 0.5*$sigma*$sigma)*$T)/($sigma*$sqrtT);
        $d2 = $d1 - $sigma*$sqrtT;

        $Nd1  = self::normCdf($d1);
        $Nd2  = self::normCdf($d2);
        $Nmd1 = self::normCdf(-$d1);
        $Nmd2 = self::normCdf(-$d2);
        $pdf1 = self::normPdf($d1);

        $df_r = exp(-$r*$T);
        $df_q = exp(-$q*$T);

        if ($type === 'call') {
            $price = $S*$df_q*$Nd1 - $K*$df_r*$Nd2;
            $delta = $df_q*$Nd1;
            $rho   =  $K*$T*$df_r*$Nd2 / 100.0;            // per 1% rate
        } else {
            $price = $K*$df_r*$Nmd2 - $S*$df_q*$Nmd1;
            $delta = $df_q*($Nd1 - 1.0);
            $rho   = -$K*$T*$df_r*$Nmd2 / 100.0;
        }

        $gamma = ($df_q * $pdf1)/($S*$sigma*$sqrtT);
        $vega  = ($S*$df_q*$pdf1*$sqrtT)/100.0;           // per 1 vol point
        $theta = (
            - ($S*$df_q*$pdf1*$sigma)/(2.0*$sqrtT)
            + ($type==='call'
                ?  $q*$S*$df_q*$Nd1 - $r*$K*$df_r*$Nd2
                :  -$q*$S*$df_q*$Nmd1 + $r*$K*$df_r*$Nmd2)
        )/365.0; // per day

        return [
            'price' => $price,
            'delta' => $delta,
            'gamma' => $gamma,
            'theta' => $theta,
            'vega'  => $vega,
            'rho'   => $rho,
        ];
    }
}
