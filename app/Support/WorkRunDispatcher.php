<?php

namespace App\Support;

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\RebuildEodSnapshotManifestJob;
use App\Models\WorkRun;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

final class WorkRunDispatcher
{
    public function __construct(private readonly WorkRunCoordinator $runs) {}

    /**
     * Reserve and enqueue one durable run. Returning false means another
     * process owns dispatch or the run is no longer pending.
     */
    public function dispatch(WorkRun|string $workRun): bool
    {
        $runId = $workRun instanceof WorkRun ? $workRun->id : $workRun;
        if (config('provider_backpressure.enabled', false)) {
            $candidate = $workRun instanceof WorkRun ? $workRun : WorkRun::query()->find($runId);
            if ($candidate?->kind === 'calculator_refresh'
                && QueueLanes::providerPriority($candidate->queue) === QueueLanes::PRIORITY_BACKGROUND
                && ($deferred = app(ScheduledFillBackpressure::class)->deferral())) {
                $this->runs->deferPendingProvider($runId, $deferred);

                return false;
            }
        }
        $reservation = $this->runs->reserveDispatch($runId);
        if (! $reservation) {
            return false;
        }

        $run = $reservation['run'];
        $deliveryToken = $reservation['delivery_token'];

        try {
            Bus::dispatch($this->job($run, $deliveryToken));
            $this->runs->markDispatched($run->id, $deliveryToken);
        } catch (Throwable $exception) {
            $this->runs->markDispatchFailed($run->id, $deliveryToken, $exception);

            throw $exception;
        }

        return true;
    }

    private function job(WorkRun $run, string $deliveryToken): object
    {
        $parameters = $run->parameters ?? [];

        return match ($run->kind) {
            'eod_manifest_rebuild' => (new RebuildEodSnapshotManifestJob(
                $run->id,
                $deliveryToken,
                $run->symbol,
                (int) $parameters['revision'],
                (string) $parameters['cache_version'],
                (array) $parameters['policy'],
            ))->onConnection($run->queue_connection)->onQueue($run->queue),
            'calculator_refresh' => (new FetchCalculatorChainJob(
                $run->symbol,
                $parameters['expiry'] ?? null,
                workRunId: $run->id,
                workRunDeliveryToken: $deliveryToken
            ))->onConnection($run->queue_connection)->onQueue($run->queue),
            'intraday_refresh' => (new FetchPolygonIntradayOptionsJob(
                [$run->symbol],
                tradeDate: $parameters['trade_date'] ?? null,
                workRunId: $run->id,
                workRunDeliveryToken: $deliveryToken
            ))->onConnection($run->queue_connection)->onQueue($run->queue),
            'quote_refresh' => (new FetchUnderlyingQuotesJob(
                [$run->symbol],
                scheduled: true,
                sessionDate: $parameters['session_date'] ?? null,
                workRunDeliveries: [$run->symbol => [
                    'run_id' => $run->id,
                    'delivery_token' => $deliveryToken,
                ]],
                phase: $parameters['phase'] ?? 'regular',
            ))->onConnection($run->queue_connection)->onQueue($run->queue),
            'symbol_bootstrap' => (new BootstrapUserSymbolJob(
                $run->symbol,
                $parameters['source'] ?? 'api_prime',
                $run->id,
                $deliveryToken
            ))->onConnection($run->queue_connection)->onQueue($run->queue),
            default => throw new RuntimeException("Unsupported work-run kind [{$run->kind}]."),
        };
    }
}
