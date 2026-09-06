<?php

namespace App\Console\Commands;

use App\Http\Controllers\IntradayController;
use App\Support\IntradayFreshness;
use App\Support\MarketSession;
use App\Support\OptionLiveTotalsRepository;
use App\Support\ScheduledFillBackpressure;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/** Bounded diagnostics: no provider calls, dispatches, or market-data writes. */
class MarketDataRefreshStatus extends Command
{
    protected $signature = 'market-data:refresh-status {--symbols=SPY,QQQ,IWM,TSLA}';

    protected $description = 'Inspect intraday freshness, provider coordination and fill backpressure without fetching data.';

    public function handle(): int
    {
        $symbols = array_values(array_unique(array_filter(array_map(Symbols::canon(...), explode(',', (string) $this->option('symbols'))))));
        if (count($symbols) > 20 || collect($symbols)->contains(static fn (string $symbol): bool => ! Symbols::isValid($symbol))) {
            $this->error('Use no more than 20 valid symbols.');

            return self::FAILURE;
        }
        $limit = (int) config('services.massive.concurrency.limit');
        $cooldown = null;
        if (config('provider_backpressure.enabled', false)) {
            $until = Redis::connection((string) config('services.massive.concurrency.connection', 'default'))
                ->get((string) config('services.massive.concurrency.key', 'provider-concurrency:massive').':cooldown-until');
            if (is_scalar($until) && ctype_digit((string) $until) && (int) $until > time()) {
                $cooldown = CarbonImmutable::createFromTimestampUTC((int) $until)->toIso8601String();
            }
        }
        $intraday = [];
        foreach ($symbols as $symbol) {
            $started = microtime(true);
            $response = app(IntradayController::class)->summary(
                Request::create('/api/intraday/summary', 'GET', ['symbol' => $symbol]), app(OptionLiveTotalsRepository::class)
            )->getData(true);
            $intraday[] = ['symbol' => $symbol, 'read_ms' => round((microtime(true) - $started) * 1000, 2)] + $response;
        }
        $this->line(json_encode([
            'checked_at' => now('UTC')->toIso8601String(),
            'intraday_freshness_enabled' => IntradayFreshness::enabled(),
            'provider_backpressure_enabled' => (bool) config('provider_backpressure.enabled', false),
            'session' => MarketSession::describe(),
            'provider' => [
                'concurrency_limit' => $limit,
                'interactive_reserved' => intdiv($limit + 1, 2),
                'background_reserved' => intdiv($limit, 2),
                'cooldown_until' => $cooldown,
                'configured_request_window' => config('provider_backpressure.rate.requests'),
            ],
            'fill_pressure' => app(ScheduledFillBackpressure::class)->inspect(),
            'intraday' => $intraday,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
