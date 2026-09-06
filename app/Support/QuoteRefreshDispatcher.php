<?php

namespace App\Support;

use App\Jobs\FetchUnderlyingQuotesJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class QuoteRefreshDispatcher
{
    public function __construct(
        private readonly WorkRunCoordinator $runs,
        private readonly QuoteRefreshPolicy $policy,
    ) {}

    /**
     * Persist one stable intent per symbol before reserving bounded batches.
     * A transport failure leaves each intent recoverable as a singleton.
     *
     * @param  iterable<string>  $symbols
     */
    public function dispatch(iterable $symbols, ?CarbonInterface $at = null): array
    {
        if (! $this->policy->enabled()) {
            throw new LogicException('Durable scheduled quote refresh is disabled.');
        }

        $window = $this->policy->window($at);
        $result = [
            'eligible' => $window['eligible'],
            'reason' => $window['reason'],
            'session_date' => $window['session_date'],
            'phase' => $window['phase'],
            'requested' => 0, 'due' => 0, 'fresh' => 0, 'created' => 0,
            'reused' => 0, 'deferred' => 0, 'dispatched' => 0, 'batches' => 0, 'failed' => 0,
        ];
        if (! $window['eligible']) {
            return $result;
        }

        $canonical = [];
        foreach ($symbols as $rawSymbol) {
            $symbol = Symbols::canon($rawSymbol);
            if ($symbol === '') {
                continue;
            }
            if (! Symbols::isValid($symbol) || strlen($symbol) > 16) {
                throw new InvalidArgumentException('Quote dispatch contains an invalid symbol.');
            }
            $canonical[$symbol] = true;
        }
        $result['requested'] = count($canonical);
        if ($canonical === []) {
            return $result;
        }

        $states = DB::table('quote_refresh_states')
            ->where('session_date', $window['session_date'])
            ->whereIn('symbol', array_keys($canonical))
            ->get()->keyBy('symbol');
        $batches = [];

        foreach (array_keys($canonical) as $symbol) {
            if (! $this->policy->isDue($states->get($symbol), $window)) {
                $result['fresh']++;

                continue;
            }
            $result['due']++;
            $run = null;
            try {
                $claim = $this->runs->claim(
                    'quote_refresh',
                    $symbol,
                    ['session_date' => $window['session_date'], 'phase' => $window['phase']],
                    QueueLanes::quotes(),
                    at: $at,
                    deferWhenRateLimited: true,
                    reuseCompleted: false,
                );
                $run = $claim['run'];
                $result[$claim['created'] ? 'created' : 'reused']++;
                if ($claim['deferred']) {
                    $result['deferred']++;
                }
                $reservation = $this->runs->reserveDispatch($run->id, $at);
                if (! $reservation) {
                    continue;
                }
                $route = json_encode([$run->queue_connection, $run->queue], JSON_THROW_ON_ERROR);
                $batches[$route][] = $reservation;
                if (count($batches[$route]) === 4) {
                    $this->enqueue($batches[$route], $window, $result);
                    unset($batches[$route]);
                }
            } catch (Throwable $exception) {
                $result['failed']++;
                Log::channel('scheduler')->warning('quotes.intent_failed', [
                    'symbol' => $symbol, 'run_id' => $run?->id, 'exception' => $exception::class,
                ]);
            }
        }

        foreach ($batches as $batch) {
            $this->enqueue($batch, $window, $result);
        }

        return $result;
    }

    private function enqueue(array $reservations, array $window, array &$result): void
    {
        $deliveries = [];
        foreach ($reservations as $reservation) {
            $run = $reservation['run'];
            $deliveries[$run->symbol] = [
                'run_id' => $run->id,
                'delivery_token' => $reservation['delivery_token'],
            ];
        }
        $first = $reservations[0]['run'];

        try {
            Bus::dispatch((new FetchUnderlyingQuotesJob(
                array_keys($deliveries),
                scheduled: true,
                sessionDate: $window['session_date'],
                workRunDeliveries: $deliveries,
                phase: $window['phase'],
            ))->onConnection($first->queue_connection)->onQueue($first->queue));
            foreach ($deliveries as $delivery) {
                $this->runs->markDispatched($delivery['run_id'], $delivery['delivery_token']);
            }
            $result['dispatched'] += count($deliveries);
            $result['batches']++;
        } catch (Throwable $exception) {
            $result['failed'] += count($deliveries);
            foreach ($deliveries as $symbol => $delivery) {
                try {
                    $this->runs->markDispatchFailed($delivery['run_id'], $delivery['delivery_token'], $exception);
                } catch (Throwable $metadataException) {
                    // The bounded reservation lease still permits recovery if
                    // the database is unavailable while recording a push error.
                    Log::channel('scheduler')->warning('quotes.dispatch_failure_metadata_failed', [
                        'run_id' => $delivery['run_id'], 'exception' => $metadataException::class,
                    ]);
                }
                Log::channel('scheduler')->warning('quotes.dispatch_failed', [
                    'symbol' => $symbol, 'run_id' => $delivery['run_id'], 'exception' => $exception::class,
                ]);
            }
        }
    }
}
