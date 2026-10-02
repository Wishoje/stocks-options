<?php

namespace App\Console\Commands;

use App\Models\WallObservation;
use App\Services\WallInteractionService;
use App\Support\MarketSession;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CaptureWallInteractions extends Command
{
    protected $signature = 'walls:capture-interactions {--symbol=*} {--session=} {--local-review : Local-only manual collection}
        {--recover-previous : Populate absent price evidence for the latest completed session; requires explicit symbols}
        {--dry-run : Preview recovery without provider requests or writes}';

    protected $description = 'Collect completed price bars once per symbol and record versioned wall interactions';

    public function handle(WallInteractionService $service): int
    {
        $review = $this->option('local-review') && app()->environment('local');
        $recover = (bool) $this->option('recover-previous');
        if (($this->option('local-review') && ! $review) || ($this->option('session') && ! $review && ! $recover)) {
            $this->error('Historical collection is available only for local review.');

            return self::FAILURE;
        }
        if (($recover && $this->option('local-review')) || ($this->option('dry-run') && ! $recover)) {
            $this->error('Recovery and local review are separate modes; dry-run requires recovery.');

            return self::FAILURE;
        }
        if (! $review && ! config('wall_tracking.interactions_enabled')) {
            $this->info('Wall interaction collection is disabled.');

            return self::SUCCESS;
        }
        $now = CarbonImmutable::now('UTC');
        $market = MarketSession::describe($now);
        if (! $review && ! $recover && (! $market['is_trading_day'] || $now->lessThan(CarbonImmutable::parse($market['opens_at'])->addMinutes(20))
            || $now->greaterThan(CarbonImmutable::parse($market['closes_at'])->addMinutes(30)))) {
            return self::SUCCESS;
        }
        $session = $this->option('session') ?: $market['session_date'];
        if ($recover) {
            if (! $this->option('symbol') || count($this->option('symbol')) > 5) {
                $this->error('Recovery requires one to five explicit symbols.');

                return self::FAILURE;
            }
            if ($market['refresh_allowed']) {
                $this->error('Run previous-session recovery outside the market collection window.');

                return self::FAILURE;
            }
            $date = CarbonImmutable::parse($market['trade_date'].' 12:00', 'America/New_York');
            $calendar = MarketSession::describe($date);
            $completed = $service->cutoff($now)->greaterThanOrEqualTo(CarbonImmutable::parse($calendar['closes_at']))
                ? $date->toDateString() : MarketSession::tradingDateOnOrBefore($date->subDay());
            if ($this->option('session') && $this->option('session') !== $completed) {
                $this->error('Recovery is limited to the latest completed session: '.$completed.'.');

                return self::FAILURE;
            }
            $session = $completed;
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $session, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $session > $market['session_date']) {
            $this->error('Choose a completed or current session date.');

            return self::FAILURE;
        }
        $explicit = $this->option('symbol');
        foreach ($explicit as $symbol) {
            if (! Symbols::isValid($symbol)) {
                $this->error('Invalid symbol.');

                return self::FAILURE;
            }
        }
        $symbols = $explicit ? array_unique(array_map([Symbols::class, 'canon'], $explicit))
            : WallObservation::where('dataset', 'intraday_capture')->where('analysis_session', $session)
                ->select('symbol')->distinct()->orderBy('symbol')->pluck('symbol')->all();
        $cursor = $explicit ? null : Cache::get('walls:interaction-cursor');
        if ($cursor) {
            $symbols = array_merge(array_values(array_filter($symbols, fn ($s) => strcmp($s, $cursor) > 0)),
                array_values(array_filter($symbols, fn ($s) => strcmp($s, $cursor) <= 0)));
        }
        $started = microtime(true);
        $last = null;
        foreach ($symbols as $symbol) {
            if ($last !== null && microtime(true) - $started >= 60) {
                if (! $explicit) {
                    Cache::put('walls:interaction-cursor', $last, $now->addDay());
                }
                break;
            }
            if ($recover) {
                $hasBars = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_price_bars')
                    ->where('schema_version', 'wall-price-bars.v1')->where('analysis_session', $session)->exists();
                if ($hasBars || $this->option('dry-run')) {
                    $readings = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_capture')
                        ->where('analysis_session', $session)->count();
                    $this->line($symbol.': '.json_encode(['session' => $session, 'wall_readings' => $readings,
                        'status' => $hasBars ? 'already_recorded' : ($readings ? 'would_collect' : 'waiting_for_walls')]));
                    $last = $symbol;

                    continue;
                }
            }
            $result = $service->collect($symbol, $session, CarbonImmutable::now('UTC'));
            $this->line($symbol.': '.json_encode($result));
            $last = $symbol;
            if (in_array($result['status'], ['provider_backoff', 'rate_limited', 'access_not_available'], true)) {
                break;
            }
        }
        if (! $explicit && $last === end($symbols)) {
            Cache::forget('walls:interaction-cursor');
        }

        return self::SUCCESS;
    }
}
