<?php

namespace App\Support\WallIntelligence;

use Carbon\CarbonImmutable;
use DomainException;

/** Compact same-observation context; never substitutes a later price or input basis. */
final class IntradayGammaContext
{
    public function build(array $basis, array $observation): array
    {
        $identity = ['model_version' => GammaProfileModel::INTRADAY_VERSION,
            'wall_model_version' => $observation['model_version'],
            'basis_key' => $observation['basis_key'], 'scope_key' => $observation['scope_key'],
            'observed_at' => $observation['observed_at'], 'reference_price' => $observation['spot'],
            'historical_outcome_eligible' => false];
        $notReady = fn (string $reason) => $identity + ['status' => 'not_ready', 'reason' => $reason,
            'regime' => null, 'nearest_crossing' => null, 'gamma_flip' => null,
            'distance_to_nearest_flip_pct' => null, 'crossings' => []];
        if ($basis['basis_key'] !== $observation['basis_key'] || $basis['scope_key'] !== $observation['scope_key']) {
            return $notReady('observation_basis_mismatch');
        }
        try {
            $profile = (new GammaProfileModel)->calculateIntraday($basis['contracts'], $observation['spot'], CarbonImmutable::parse($observation['observed_at']));
        } catch (DomainException) {
            return $notReady('profile_scope_limit');
        }
        if ($profile['status'] !== 'ready') {
            return $notReady($profile['reason']);
        }
        // Independently calculated spot exposure must reconcile with this exact wall reading.
        if (abs($profile['net_gex_at_spot'] - $observation['net_gex']) > max(.01, abs($observation['net_gex']) * 1e-8)) {
            return $notReady('observation_exposure_mismatch');
        }
        $nearest = $profile['nearest_crossing'];

        return $identity + ['status' => 'ready', 'regime' => $profile['regime'],
            'current_sign' => $profile['current_sign'], 'net_gex_at_spot' => $profile['net_gex_at_spot'],
            'flip_status' => $profile['flip_status'], 'gamma_flip' => $profile['gamma_flip'],
            'nearest_crossing' => $nearest,
            'distance_to_nearest_flip_pct' => $nearest ? 100 * abs($nearest['price'] - $observation['spot']) / $observation['spot'] : null,
            'crossings' => $profile['crossings'],
            'grid' => array_intersect_key($profile['grid'], array_flip(['lower_price', 'upper_price', 'range_pct'])),
            'audit' => $profile['audit']];
    }
}
