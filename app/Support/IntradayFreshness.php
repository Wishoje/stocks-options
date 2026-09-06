<?php

namespace App\Support;

use App\Models\WorkRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

/** Source age describes the market; completed ingestion controls refresh admission. */
final class IntradayFreshness
{
    public static function enabled(): bool
    {
        return (bool) config('intraday_freshness.enabled', false);
    }

    public function decision(string $symbol, string $tradeDate, bool $force = false, ?string $ignoreRunId = null): array
    {
        $session = MarketSession::describe();
        if (! $session['refresh_allowed'] || $tradeDate !== $session['session_date']) {
            return ['eligible' => false, 'reason' => 'market_closed', 'session' => $session];
        }

        $run = $this->latestRun($symbol, $tradeDate);
        if ($run && $run->id !== $ignoreRunId) {
            if ($run->isActive() && $run->lease_expires_at?->isAfter(now('UTC'))) {
                return ['eligible' => false, 'reason' => 'pending', 'session' => $session];
            }
            if ($run->status === WorkRun::STATUS_FAILED && $run->retry_not_before?->isAfter(now('UTC'))) {
                return ['eligible' => false, 'reason' => 'failure_backoff', 'session' => $session];
            }
        }

        $state = $this->state($symbol, $tradeDate);
        $completed = $state?->ingestion_completed_at;
        if (! $force && $completed && CarbonImmutable::parse($completed, 'UTC')->addSeconds(
            max(1, (int) config('intraday_freshness.completed_ttl_seconds', 90))
        )->isAfter(now('UTC'))) {
            return ['eligible' => false, 'reason' => 'recently_completed', 'session' => $session];
        }

        return ['eligible' => true, 'reason' => 'refresh_due', 'session' => $session];
    }

    public function metadata(string $symbol, string $tradeDate, bool $snapshotAvailable): array
    {
        $state = $this->state($symbol, $tradeDate);
        $decision = $this->decision($symbol, MarketSession::describe()['trade_date']);
        $run = $this->latestRun($symbol, MarketSession::describe()['trade_date']);
        $source = $this->iso($state?->source_asof);

        return [
            'trade_date' => $tradeDate,
            'asof' => $source,
            'source_asof' => $source,
            'source_timestamp_status' => $source !== null ? 'provider' : 'unknown',
            'captured_at' => $this->iso($state?->captured_at),
            'received_at' => $this->iso($state?->received_at),
            'ingestion_completed_at' => $this->iso($state?->ingestion_completed_at),
            'snapshot_available' => $snapshotAvailable,
            'stale_seconds' => $source === null ? null : max(0, (int) CarbonImmutable::parse($source)->diffInSeconds(now('UTC'))),
            'refresh_eligible' => $decision['eligible'],
            'refresh_reason' => $decision['reason'],
            'market_session' => $decision['session'],
            'refresh_run' => $run ? app(WorkRunCoordinator::class)->payload($run) : null,
        ];
    }

    /** Atomically publish complete values and their freshness, fencing a late older generation. */
    public function publish(
        string $symbol,
        string $tradeDate,
        CarbonInterface $capturedAt,
        CarbonInterface $receivedAt,
        ?CarbonInterface $sourceAsOf,
        ?string $workRunId,
        int $expiryCount,
        Closure $publish
    ): bool {
        return DB::transaction(function () use ($symbol, $tradeDate, $capturedAt, $receivedAt, $sourceAsOf, $workRunId, $expiryCount, $publish): bool {
            DB::table('intraday_refresh_states')->insertOrIgnore(['symbol' => $symbol, 'trade_date' => $tradeDate]);
            $query = DB::table('intraday_refresh_states')->where('symbol', $symbol)->where('trade_date', $tradeDate);
            $current = (clone $query)->lockForUpdate()->first();
            if (($current->captured_at && CarbonImmutable::parse($current->captured_at, 'UTC')->greaterThan($capturedAt))
                || ($current->source_asof && $sourceAsOf && CarbonImmutable::parse($current->source_asof, 'UTC')->greaterThan($sourceAsOf))) {
                return false;
            }

            // A failed publication rolls back both the counters and metadata.
            $publish();
            $query->update([
                'source_asof' => $sourceAsOf?->copy()->utc()->format('Y-m-d H:i:s.u'),
                'captured_at' => $capturedAt->copy()->utc()->format('Y-m-d H:i:s.u'),
                'received_at' => $receivedAt->copy()->utc()->format('Y-m-d H:i:s.u'),
                'ingestion_completed_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                'work_run_id' => $workRunId,
                'expiry_count' => $expiryCount,
            ]);

            return true;
        }, 3);
    }

    private function state(string $symbol, string $tradeDate): ?object
    {
        return DB::table('intraday_refresh_states')->where('symbol', $symbol)->where('trade_date', $tradeDate)->first();
    }

    private function latestRun(string $symbol, string $tradeDate): ?WorkRun
    {
        return WorkRun::query()->where('kind', 'intraday_refresh')->where('symbol', $symbol)
            ->where('parameters->trade_date', $tradeDate)->orderByDesc('requested_at')->first();
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }
}
