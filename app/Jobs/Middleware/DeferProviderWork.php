<?php

namespace App\Jobs\Middleware;

use App\Exceptions\ProviderDeferred;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\RunSymbolBootstrapPhaseJob;
use App\Support\CoordinationCache;
use App\Support\ProviderRequestReplay;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\WorkRunCoordinator;
use Illuminate\Support\Facades\Log;

/** Queue waits preserve intent and never complete an unfinished chained job. */
final class DeferProviderWork
{
    public function handle(object $job, callable $next): mixed
    {
        if (! config('provider_backpressure.enabled', false)) {
            return $next($job);
        }
        $scope = $this->scope($job);
        $replay = app(ProviderRequestReplay::class);

        return $replay->withExecution($scope, function () use ($job, $next, $scope, $replay): mixed {
            try {
                $result = $next($job);
                if (! ($job->job?->isReleased() ?? false) && $this->completed($job)) {
                    $replay->forgetExecution($scope);
                    CoordinationCache::store()->forget($this->failureKey($scope));
                }

                return $result;
            } catch (ProviderDeferred $exception) {
                $physical = $replay->executedRequestCount();
                if (! isset($job->job) || $job->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
                    // Direct CLI/synchronous callers have no queue payload to release.
                    throw $exception;
                }

                $attempt = max(1, $job->attempts());
                if ($job instanceof RunSymbolBootstrapPhaseJob) {
                    app(SymbolBootstrapCoordinator::class)->deferProvider(
                        $job->workRunId, $job->phase, $job->phaseToken, $attempt, $exception, $physical,
                        expectedParentFence: [
                            'delivery_token' => $job->workRunDeliveryToken,
                            'attempt' => $job->workRunAttempt,
                            'orchestration_token' => $job->workRunOrchestrationToken,
                        ]
                    );
                } elseif ($job instanceof FetchUnderlyingQuotesJob && $job->workRunDeliveries !== []) {
                    // Quote batches normally persist per-symbol outcomes inside
                    // handle(). This protects a deferral escaping that adapter;
                    // never release the batch as an unrelated legacy payload.
                    foreach ($job->workRunDeliveries as $delivery) {
                        app(WorkRunCoordinator::class)->deferProvider(
                            $delivery['run_id'], $delivery['delivery_token'], $attempt, $exception, $physical
                        );
                    }
                } elseif (($job instanceof FetchCalculatorChainJob || $job instanceof FetchPolygonIntradayOptionsJob)
                    && $job->workRunId !== null) {
                    app(WorkRunCoordinator::class)->deferProvider(
                        $job->workRunId, $job->workRunDeliveryToken, $attempt, $exception, $physical
                    );
                } else {
                    // The original UUID/serialized chain remains reserved until release succeeds.
                    // Count attempted-provider failures, not zero-HTTP admission waits.
                    $failures = 0;
                    if (! $exception->isAdmissionDeferral() || $physical > 0) {
                        CoordinationCache::store()->add($this->failureKey($scope), 0, now()->addDay());
                        $failures = (int) CoordinationCache::store()->increment($this->failureKey($scope));
                    }
                    if ($failures >= max(1, (int) ($job->tries ?? 3))) {
                        $job->fail($exception);
                    } else {
                        $job->release(max(1, $exception->retryAfterSeconds(now('UTC'))));
                    }
                }

                Log::channel('queue_monitor')->info('provider.job.deferred', [
                    'job' => $job::class,
                    'scope_fingerprint' => substr(hash('sha256', $scope), 0, 16),
                    'reason' => $exception->reason,
                    'not_before' => $exception->notBefore->toIso8601String(),
                    'physical_http_requests' => $physical,
                ]);

                return null;
            }
        });
    }

    private function scope(object $job): string
    {
        if ($job instanceof RunSymbolBootstrapPhaseJob) {
            return 'bootstrap:'.$job->workRunId.':'.$job->phase;
        }
        if (($job->workRunId ?? null) !== null) {
            return 'work-run:'.$job->workRunId.':'.$job::class;
        }
        if ($job instanceof FetchUnderlyingQuotesJob && $job->workRunDeliveries !== []) {
            $ids = array_column($job->workRunDeliveries, 'run_id');
            sort($ids, SORT_STRING);

            return 'quote-runs:'.hash('sha256', implode('|', $ids));
        }
        if (($job->schedulerGeneration ?? null) !== null) {
            return 'scheduled-calculator:'.$job->symbol.':'.$job->schedulerGeneration;
        }

        return 'queue:'.($job->job?->uuid() ?? $job->idempotencyKey());
    }

    private function failureKey(string $scope): string
    {
        return 'provider-backpressure:failures:'.hash('sha256', $scope);
    }

    private function completed(object $job): bool
    {
        if ($job instanceof FetchUnderlyingQuotesJob && $job->workRunDeliveries !== []) {
            return ! \App\Models\WorkRun::query()
                ->whereIn('id', array_column($job->workRunDeliveries, 'run_id'))
                ->whereIn('status', \App\Models\WorkRun::ACTIVE_STATUSES)->exists();
        }
        if ($job instanceof RunSymbolBootstrapPhaseJob) {
            return \App\Models\SymbolBootstrapPhase::query()->where('work_run_id', $job->workRunId)
                ->where('phase', $job->phase)->where('status', 'completed')->exists();
        }
        if (($job instanceof FetchCalculatorChainJob || $job instanceof FetchPolygonIntradayOptionsJob)
            && $job->workRunId !== null) {
            return \App\Models\WorkRun::query()->whereKey($job->workRunId)->where('status', 'completed')->exists();
        }

        return true;
    }
}
