<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use LogicException;

final class SymbolBootstrapPolicy
{
    public const PURPOSE = 'initial_symbol_data';

    public function enabled(): bool
    {
        $enabled = (bool) config('symbol_bootstrap.enabled', false);
        if ($enabled && ! QueueLanes::isolated()) {
            // The phased graph depends on reserved fast and interactive
            // consumers plus the provider gate. QueueLanes validates the
            // provider gate and this policy also requires isolation itself.
            throw new LogicException(
                'SYMBOL_BOOTSTRAP_ENABLED requires QUEUE_LANES_ISOLATED=true.'
            );
        }

        return $enabled;
    }

    /** @return array<string, int|string> */
    public function claimParameters(?CarbonInterface $at = null): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            'purpose' => self::PURPOSE,
            'session_date' => $this->sessionDate($at),
        ];
    }

    public function sessionDate(?CarbonInterface $at = null): string
    {
        $instant = $at
            ? Carbon::instance($at)->setTimezone('America/New_York')
            : Carbon::now('America/New_York');

        return app(EodSnapshotSelector::class)->completedSessionDate($instant);
    }

    public function fastHorizonDays(): int
    {
        return max(0, min(
            $this->fillHorizonDays(),
            (int) config('symbol_bootstrap.fast_horizon_days', 14)
        ));
    }

    public function fillHorizonDays(): int
    {
        return max(1, min(365, (int) config('symbol_bootstrap.fill_horizon_days', 90)));
    }
}
