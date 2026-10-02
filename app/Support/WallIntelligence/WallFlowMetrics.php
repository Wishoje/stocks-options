<?php

namespace App\Support\WallIntelligence;

/** Descriptive EOD open-interest changes. No dealer direction or price prediction. */
final class WallFlowMetrics
{
    public const VERSION = 'wall-flow-oi.v1';

    public const STABLE_BAND_PCT = 2.0;

    public function calculate(float $strike, string $side, array $current, array $history, array $sessions, array $scopeComparable): array
    {
        $date = $sessions[0];
        $rows = $this->atStrike($current, $strike);
        $currentValid = ($scopeComparable[$date] ?? false) && $this->valid($rows, $side);
        $oi = $currentValid ? array_sum(array_column($rows, $side.'_oi')) : null;
        $changes = [];
        $priorRows = [];
        foreach (['daily' => 1, 'five_session' => 5] as $period => $offset) {
            $baselineDate = $sessions[$offset] ?? null;
            $prior = $this->atStrike($history[$baselineDate] ?? [], $strike);
            $matched = $currentValid && ($scopeComparable[$baselineDate] ?? false)
                && $this->valid($prior, $side) && array_keys($rows) === array_keys($prior);
            // A changed contract set is not an opening/closing-position observation.
            foreach ($rows as $expiry => $row) {
                $matched = $matched && ($row[$side.'_rows'] ?? null) === ($prior[$expiry][$side.'_rows'] ?? null);
            }
            $baseline = $matched ? array_sum(array_column($prior, $side.'_oi')) : null;
            $delta = $matched ? $oi - $baseline : null;
            $changes[$period] = [
                'sessions_back' => $offset, 'baseline_date' => $baselineDate,
                'baseline_oi' => $baseline, 'change' => $delta,
                'change_pct' => $baseline > 0 ? 100 * $delta / $baseline : null,
                'comparable' => $matched,
            ];
            $priorRows[$period] = $matched ? $prior : [];
        }
        $daily = $changes['daily'];
        $state = match (true) {
            ! $daily['comparable'] => null,
            $daily['baseline_oi'] == 0 => $oi > 0 ? 'building' : 'stable',
            $daily['change_pct'] > self::STABLE_BAND_PCT => 'building',
            $daily['change_pct'] < -self::STABLE_BAND_PCT => 'unwinding',
            default => 'stable',
        };
        $contributions = [];
        foreach ($rows as $expiry => $row) {
            $contributions[] = [
                'expiry' => $expiry, 'open_interest' => $currentValid ? $row[$side.'_oi'] : null,
                'change_1d' => $daily['comparable'] ? $row[$side.'_oi'] - $priorRows['daily'][$expiry][$side.'_oi'] : null,
                'change_5d' => $changes['five_session']['comparable'] ? $row[$side.'_oi'] - $priorRows['five_session'][$expiry][$side.'_oi'] : null,
            ];
        }
        usort($contributions, fn ($a, $b) => abs($b['change_1d'] ?? 0) <=> abs($a['change_1d'] ?? 0) ?: strcmp($a['expiry'], $b['expiry']));

        return [
            'schema_version' => self::VERSION, 'basis' => 'eod_open_interest', 'unit' => 'contracts',
            'side' => $side, 'strike' => $strike, 'data_date' => $date,
            'wall_build_state' => $state, 'state_basis' => 'daily', 'stable_band_pct' => self::STABLE_BAND_PCT,
            'open_interest' => $oi, ...$changes,
            'expiry_contributions' => $contributions,
            'activity' => [
                'date' => $date, 'basis' => 'eod_contract_volume',
                'call_volume' => ($scopeComparable[$date] ?? false) ? $this->volume($rows, 'call', $date) : null,
                'put_volume' => ($scopeComparable[$date] ?? false) ? $this->volume($rows, 'put', $date) : null,
                'direction_inferred' => false,
            ],
            'rules' => [
                'classification' => 'Daily side-specific OI change above +2% is building, below -2% is unwinding; inclusive [-2%, +2%] is stable. Positive OI from an observed zero baseline is building with no percentage. Zero to zero is stable. No comparable pair returns null, never stable.',
                'comparison' => 'Same strike, option side and fixed expiration basket at both endpoints, with same-session scope and matching contract counts. Five-session change uses five trading sessions earlier (six session dates including today). Only the endpoints are required; it is not the sum of volume or a rolling-expiry comparison.',
                'interpretation' => 'Descriptive OI classification, not a calibrated strength score. OI does not identify buyers, sellers or dealer inventory. Volume does not establish opening or closing trades. This is EOD structure, not intraday OI or a hold/break prediction.',
            ],
        ];
    }

    private function atStrike(array $rows, float $strike): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ((float) $row['strike'] === $strike) {
                $result[$row['expiry']] = $row;
            }
        }
        ksort($result);

        return $result;
    }

    private function valid(array $rows, string $side): bool
    {
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if (! ($row[$side.'_oi_valid'] ?? false) || ($row[$side.'_rows'] ?? 0) < 1
                || WallSnapshotContract::number($row[$side.'_oi'] ?? null) === null || $row[$side.'_oi'] < 0) {
                return false;
            }
        }

        return true;
    }

    private function volume(array $rows, string $side, string $date): ?float
    {
        if ($rows === []) {
            return null;
        }
        foreach ($rows as $row) {
            if ($row['data_date'] !== $date || ! ($row[$side.'_volume_valid'] ?? false) || ($row[$side.'_rows'] ?? 0) < 1) {
                return null;
            }
        }

        return (float) array_sum(array_column($rows, $side.'_volume'));
    }
}
