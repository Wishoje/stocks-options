<?php

namespace App\Support;

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
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
