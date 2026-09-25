<?php

namespace App\Support\WallIntelligence;

use InvalidArgumentException;

/** Additive audit adapter. Never changes legacy dashboard or export values. */
final class WallSnapshotContract
{
    public const SCHEMA = 'wall-foundation.v1';

    public const MODEL = 'legacy-eod-adapter.v1';

    public const GEX_FACTOR = 0.01;

    public static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    public static function distancePct(mixed $strike, mixed $spot): ?float
    {
        $strike = self::number($strike);
        $spot = self::number($spot);

        return $strike !== null && $spot !== null && $spot > 0
            ? 100 * abs($strike - $spot) / $spot : null;
    }

    public static function magnitudeChangePct(mixed $current, mixed $previous, bool $comparable): ?float
    {
        $current = self::number($current);
        $previous = self::number($previous);

        return $comparable && $current !== null && $previous !== null && $previous != 0
            ? 100 * (abs($current) - abs($previous)) / abs($previous) : null;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => self::canonical($item), $value);
    }

    public static function scopeMatches(array $left, array $right): bool
    {
        return ! empty($left['scope_key']) && ($left['scope_key'] === ($right['scope_key'] ?? null));
    }

    public function fromLegacyGex(array $payload, array $context): array
    {
        foreach (['symbol', 'timeframe', 'view', 'dataset', 'captured_at', 'http_status'] as $required) {
            if (! isset($context[$required])) {
                throw new InvalidArgumentException('Missing context: '.$required);
            }
        }
        $expiries = array_values(array_unique($payload['expiration_dates'] ?? []));
        sort($expiries, SORT_STRING);
        $rows = (int) $context['http_status'] === 200 ? ($payload['strike_data'] ?? []) : [];
        $sourceQuality = $payload['social_quality'] ?? null;
        $sourceDates = $sourceQuality['source_dates'] ?? [];
        sort($sourceDates, SORT_STRING);
        $scope = [
            'symbol' => $context['symbol'], 'horizon' => $context['timeframe'],
            'view' => $context['view'], 'expiry_set' => $expiries,
            'market_timezone' => 'America/New_York',
            'inventory_convention' => 'call_minus_put_proxy_not_observed_dealer_inventory',
            'contract_multiplier_assumption' => 100,
            'exposure_basis' => 'recorded_eod_per_contract_spot_and_gamma',
        ];
        $reasons = [];
        if ($rows === []) {
            $reasons[] = 'snapshot_unavailable';
        }
        if ($sourceQuality === null) {
            $reasons[] = 'source_coverage_unknown';
        }
        if (($sourceQuality['missing_input_rows'] ?? 0) > 0) {
            $reasons[] = 'missing_contract_inputs';
        }
        if (($sourceQuality['missing_expiration_count'] ?? 0) > 0) {
            $reasons[] = 'missing_expirations';
        }
        if (count($sourceDates) > 1) {
            $reasons[] = 'mixed_source_dates';
        }
        if ($expiries === []) {
            $reasons[] = 'expiry_scope_unavailable';
        }
        if (($payload['symbol'] ?? $context['symbol']) !== $context['symbol']
            || ($payload['timeframe'] ?? $context['timeframe']) !== $context['timeframe']
            || ($payload['view_context']['view'] ?? null) !== $context['view']) {
            $reasons[] = 'scope_mismatch';
        }

        $normalized = [];
        $total = 0.0;
        $valid = $rows !== [];
        $identityMatches = $rows !== [];
        foreach ($rows as $row) {
            $net = self::number($row['net_gex'] ?? null);
            $call = self::number($row['call_gex'] ?? null);
            $put = self::number($row['put_gex'] ?? null);
            $strike = self::number($row['strike'] ?? null);
            if ($strike === null || $net === null || $call === null || $put === null) {
                $valid = false;
            }
            if ($net === null || $call === null || $put === null
                || abs(($call - $put) - $net) > max(1e-6, abs($net) * 1e-9)) {
                $identityMatches = false;
            }
            $total += $net ?? 0;
            $normalized[] = [
                'strike' => $strike,
                'net_gex' => $net === null ? null : $net * self::GEX_FACTOR,
                'call_gex' => $call === null ? null : $call * self::GEX_FACTOR,
                'put_gex' => $put === null ? null : $put * self::GEX_FACTOR,
            ];
        }
        if ($rows !== [] && ! $valid) {
            $reasons[] = 'invalid_strike_values';
        }
        if ($rows !== [] && ! $identityMatches) {
            $reasons[] = 'leg_reconciliation_failed';
        }
        $complete = $valid && $identityMatches && ($sourceQuality['publishable'] ?? false)
            && count($sourceDates) === 1 && $reasons === [];

        $unavailable = [
            'reference_spot' => 'Legacy response aggregates per-contract spots; no single aligned spot is exposed.',
            'spot_timestamp' => 'Provider spot observation timestamp is not exposed by this response.',
            'oi_date' => 'Chain source dates are available separately; the provider OI observation date is not certified.',
            'greeks_timestamp' => 'Provider Greeks observation timestamp is not exposed by this response.',
            'observed_at' => 'A source date cannot be promoted to an exact observation time.',
            'gamma_flip' => 'Requires a spot-scenario exposure curve; legacy HVL is not substituted.',
            'interaction_status' => 'Completed price observations and a reviewed interaction rule are required.',
            'evidence_grade' => 'RET-25/26 setup predicates and evidence rubric are not implemented.',
            'strength_score' => 'A versioned, validated numeric rubric is required.',
            'hold_score' => 'A versioned, validated numeric rubric is required.',
            'break_risk_score' => 'A versioned, validated numeric rubric is required.',
            'break_acceleration_score' => 'A versioned, validated numeric rubric is required.',
        ];

        if (! $complete) {
            $unavailable['complete_net_gex_per_1pct'] = 'Complete, reconciled source coverage is required.';
        }
        if (! $valid) {
            $unavailable['raw_net_gex'] = 'A non-empty set of valid strike inputs is required.';
            $unavailable['available_input_net_gex_per_1pct'] = $unavailable['raw_net_gex'];
        }
        foreach (['put_wall' => 'put_support', 'call_wall' => 'call_resistance', 'legacy_hvl' => 'hvl'] as $field => $sourceField) {
            if (self::number($payload[$sourceField] ?? null) === null) {
                $unavailable[$field] = 'The source response does not supply a valid numeric value.';
            }
        }

        return [
            'schema_version' => self::SCHEMA, 'model_version' => self::MODEL,
            'rule_version' => null, 'rule_status' => 'not_implemented',
            'scope' => $scope,
            'scope_key' => self::hash([...$scope, 'schema' => self::SCHEMA, 'model' => self::MODEL]),
            'analysis_session' => $payload['view_context']['session_date'] ?? null,
            'source_anchor' => $payload['view_context']['source_anchor'] ?? null,
            'provenance' => [
                'dataset' => $context['dataset'], 'source_kind' => 'legacy_published_eod_response',
                'captured_at' => $context['captured_at'], 'generated_at' => $context['generated_at'] ?? $context['captured_at'],
                'observed_at' => null, 'source_date' => $payload['data_date'] ?? null,
                'chain_source_dates' => $sourceDates, 'spot_timestamp' => null,
                'greeks_timestamp' => null, 'oi_date' => null, 'reference_spot' => null,
                'http_status' => (int) $context['http_status'],
            ],
            'units' => [
                'raw_gex' => 'gamma_times_oi_times_100_times_spot_squared',
                'normalized_gex' => 'USD_per_1pct_underlying_move',
                'gex_normalization_factor' => self::GEX_FACTOR,
                'dex' => 'share_equivalents', 'pct_fields' => 'percentage_points',
                'ratios' => '0_to_1', 'numeric_scores' => '0_to_100_when_validated',
            ],
            'quality' => [
                'state' => $rows === [] ? 'unavailable' : ($complete ? 'review_only' : 'partial'),
                'source_inputs_complete' => $complete, 'reasons' => $reasons,
                'actionable' => false, 'historical_outcome_eligible' => false,
                'action_blockers' => ['provider_observation_provenance_incomplete', 'interaction_rules_not_implemented', 'setup_rubric_not_implemented'],
                'source_coverage' => $sourceQuality,
            ],
            'measurements' => [
                'raw_net_gex' => $valid ? $total : null,
                'available_input_net_gex_per_1pct' => $valid ? $total * self::GEX_FACTOR : null,
                'complete_net_gex_per_1pct' => $complete ? $total * self::GEX_FACTOR : null,
                'put_wall' => self::number($payload['put_support'] ?? null),
                'call_wall' => self::number($payload['call_resistance'] ?? null),
                'legacy_hvl' => self::number($payload['hvl'] ?? null),
                'gamma_flip' => null, 'interaction_status' => 'unknown', 'evidence_grade' => null,
                'strength_score' => null, 'hold_score' => null, 'break_risk_score' => null,
                'break_acceleration_score' => null,
            ],
            'reconciliation' => [
                'strike_count' => count($rows), 'call_minus_put_matches_net' => $rows === [] ? null : $identityMatches,
                'normalization_factor' => self::GEX_FACTOR, 'legacy_payload_preserved' => true,
            ],
            'unavailable_reasons' => $unavailable,
            'normalized_by_strike' => $normalized,
            'raw' => ['gex_response' => $payload],
        ];
    }
}
