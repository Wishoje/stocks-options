<?php

namespace App\Support;

class EodHealth
{
    /**
     * Resolve thresholds by profile and optional overrides.
     *
     * @return array{profile:string,min_expirations:int,min_strikes:int,min_strike_ratio:float}
     */
    public static function resolveThresholds(
        ?string $profile,
        mixed $minExpirationsOpt = null,
        mixed $minStrikesOpt = null,
        mixed $minStrikeRatioOpt = null
    ): array {
        $profile = strtolower(trim((string) $profile));
        if (!in_array($profile, ['broad', 'core'], true)) {
            $profile = 'broad';
        }

        $preset = $profile === 'core'
            ? ['min_expirations' => 8, 'min_strikes' => 35, 'min_strike_ratio' => 0.65]
            : ['min_expirations' => 2, 'min_strikes' => 12, 'min_strike_ratio' => 0.45];

        $minExpirations = $minExpirationsOpt === null || $minExpirationsOpt === ''
            ? $preset['min_expirations']
            : max(1, (int) $minExpirationsOpt);

        $minStrikes = $minStrikesOpt === null || $minStrikesOpt === ''
            ? $preset['min_strikes']
            : max(1, (int) $minStrikesOpt);

        $minStrikeRatio = $minStrikeRatioOpt === null || $minStrikeRatioOpt === ''
            ? (float) $preset['min_strike_ratio']
            : (float) $minStrikeRatioOpt;
        $minStrikeRatio = max(0.01, min(1.0, $minStrikeRatio));

        return [
            'profile' => $profile,
            'min_expirations' => $minExpirations,
            'min_strikes' => $minStrikes,
            'min_strike_ratio' => $minStrikeRatio,
        ];
    }

    /**
     * @param array{option_types_n?:int,expirations_n?:int,strikes_n?:int} $stats
     * @return string[]
     */
    public static function incompleteReasons(array $stats, int $prevStrikes, array $thresholds): array
    {
        $reasons = [];

        if (($stats['option_types_n'] ?? 0) < 2) {
            $reasons[] = 'missing_call_or_put';
        }
        if (($stats['expirations_n'] ?? 0) < (int) $thresholds['min_expirations']) {
            $reasons[] = 'low_expirations';
        }
        if (($stats['strikes_n'] ?? 0) < (int) $thresholds['min_strikes']) {
            $reasons[] = 'low_strike_count';
        }

        if ($prevStrikes > 0) {
            $ratio = ($stats['strikes_n'] ?? 0) / $prevStrikes;
            if ($ratio < (float) $thresholds['min_strike_ratio']) {
                $reasons[] = sprintf(
                    'strike_ratio_below_threshold(%.2f<%.2f)',
                    $ratio,
                    (float) $thresholds['min_strike_ratio']
                );
            }
        }

        return $reasons;
    }

    public static function statusFromReasons(bool $missing, array $reasons): string
    {
        if ($missing) {
            return 'missing';
        }
        if (empty($reasons)) {
            return 'ok';
        }

        foreach ($reasons as $reason) {
            if (
                str_starts_with($reason, 'missing_call_or_put') ||
                str_starts_with($reason, 'low_expirations') ||
                str_starts_with($reason, 'low_strike_count')
            ) {
                return 'alert';
            }
        }

        return 'warn';
    }
}
