<?php

namespace Tests\Feature;

use App\Jobs\PricesBackfillJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class PricesBackfillPreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2025_09_26_045222_create_prices_daily.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    public function test_routine_backfill_preserves_complete_ohlc_and_both_timestamps(): void
    {
        DB::table('prices_daily')->insert($this->row('2026-10-30'));
        $original = (array) DB::table('prices_daily')->first();
        $this->finnhub(['2026-10-30' => [200., 210., 190., 205.]]);

        (new PricesBackfillJob(['SPY'], 7, '2026-10-30'))->handle();

        $this->assertSame($original, (array) DB::table('prices_daily')->first());
        Http::assertSentCount(1);
    }

    public function test_missing_dates_are_inserted_and_repeated_backfills_leave_them_unchanged(): void
    {
        $this->finnhub(['2026-10-30' => [200., 210., 190., 205.]]);
        (new PricesBackfillJob(['SPY'], 7, '2026-10-30'))->handle();
        $original = (array) DB::table('prices_daily')->first();
        $this->assertEquals(200., $original['open']);
        $this->assertEquals(205., $original['close']);
        $this->assertSame('2026-10-31 22:00:00', $original['created_at']);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 22:00:00', 'UTC'));
        (new PricesBackfillJob(['SPY'], 7, '2026-10-30'))->handle();

        $this->assertSame($original, (array) DB::table('prices_daily')->first());
        $this->assertDatabaseCount('prices_daily', 1);
    }

    public function test_incomplete_nonpositive_and_inconsistent_bars_are_repaired_as_whole_provider_bars(): void
    {
        $invalid = [
            ['open' => null],
            ['high' => 99.],
            ['low' => 106.],
            ['open' => -1.],
            ['close' => 0.],
        ];
        $provider = [];
        foreach ($invalid as $index => $changes) {
            $date = CarbonImmutable::parse('2026-10-26')->addDays($index)->toDateString();
            DB::table('prices_daily')->insert([...$this->row($date), ...$changes]);
            $provider[$date] = [200. + $index, 210. + $index, 190. + $index, 205. + $index];
        }
        $this->finnhub($provider);

        (new PricesBackfillJob(['SPY'], 7, '2026-10-30'))->handle();

        foreach ($provider as $date => [$open, $high, $low, $close]) {
            $this->assertDatabaseHas('prices_daily', [
                'symbol' => 'SPY', 'trade_date' => $date,
                'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
                'created_at' => '2026-10-01 20:00:00', 'updated_at' => '2026-10-31 22:00:00',
            ]);
        }
    }

    public function test_only_explicit_replacement_overwrites_a_complete_bar_and_preserves_its_creation_time(): void
    {
        DB::table('prices_daily')->insert($this->row('2026-10-30'));
        $this->finnhub(['2026-10-30' => [200., 210., 190., 205.]]);

        (new PricesBackfillJob(['SPY'], 7, '2026-10-30', true))->handle();

        $this->assertDatabaseHas('prices_daily', [
            'symbol' => 'SPY', 'trade_date' => '2026-10-30',
            'open' => 200., 'high' => 210., 'low' => 190., 'close' => 205.,
            'created_at' => '2026-10-01 20:00:00', 'updated_at' => '2026-10-31 22:00:00',
        ]);
    }

    public function test_later_yahoo_range_fills_missing_dates_without_replacing_complete_finnhub_bars(): void
    {
        $finnhub = $this->finnhubPayload(['2026-10-29' => [200., 210., 190., 205.]]);
        Http::fake([
            'finnhub.io/*' => Http::response($finnhub),
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [[
                'timestamp' => [$this->timestamp('2026-10-29'), $this->timestamp('2026-10-30')],
                'indicators' => ['quote' => [[
                    'open' => [400., 500.], 'high' => [410., 510.],
                    'low' => [390., 490.], 'close' => [405., 505.],
                ]]],
            ]]]]),
        ]);

        (new PricesBackfillJob(['SPY'], 190, '2026-10-30'))->handle();

        $this->assertDatabaseHas('prices_daily', ['trade_date' => '2026-10-29', 'open' => 200., 'close' => 205.]);
        $this->assertDatabaseHas('prices_daily', ['trade_date' => '2026-10-30', 'open' => 500., 'close' => 505.]);
        Http::assertSentCount(2);
    }

    public function test_cli_replacement_is_explicit_and_default_dispatch_preserves_history(): void
    {
        Bus::fake();
        $this->artisan('prices:backfill', ['symbols' => ['SPY'], '--days' => 7])->assertSuccessful();
        Bus::assertDispatchedSync(PricesBackfillJob::class,
            fn (PricesBackfillJob $job) => $job->symbols === ['SPY'] && $job->days === 7 && ! $job->replaceExisting);

        $this->artisan('prices:backfill', ['symbols' => ['QQQ'], '--days' => 30, '--replace-existing' => true])->assertSuccessful();
        Bus::assertDispatchedSync(PricesBackfillJob::class,
            fn (PricesBackfillJob $job) => $job->symbols === ['QQQ'] && $job->days === 30 && $job->replaceExisting);
    }

    public function test_preexisting_serialized_jobs_default_to_preserving_history(): void
    {
        $legacy = (new ReflectionClass(PricesBackfillJob::class))->newInstanceWithoutConstructor();
        $legacy->__unserialize(['symbols' => ['SPY'], 'days' => 7, 'endDate' => '2026-10-30']);
        $this->assertFalse($legacy->replaceExisting);
        $this->assertSame((new PricesBackfillJob(['SPY'], 7, '2026-10-30'))->idempotencyKey(), $legacy->idempotencyKey());
        $explicit = unserialize(serialize(new PricesBackfillJob(['SPY'], 7, '2026-10-30', true)));
        $this->assertTrue($explicit->replaceExisting);

        DB::table('prices_daily')->insert($this->row('2026-10-30'));
        $original = (array) DB::table('prices_daily')->first();
        $this->finnhub(['2026-10-30' => [200., 210., 190., 205.]]);
        $legacy->handle();
        $this->assertSame($original, (array) DB::table('prices_daily')->first());
    }

    public function test_complete_history_uses_bounded_batches_without_per_row_queries(): void
    {
        $rows = [];
        foreach (range(0, 500) as $index) {
            $date = CarbonImmutable::parse('2024-01-01')->addDays($index)->toDateString();
            $rows[] = $this->row($date);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('prices_daily')->insert($chunk);
        }
        $replacement = array_map(fn (array $row) => [...$row, 'open' => 200., 'high' => 210., 'low' => 190., 'close' => 205.], $rows);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->persist($replacement);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, count($queries));
        $inserts = array_filter($queries, fn (array $query) => str_starts_with(strtolower($query['query']), 'insert'));
        $this->assertCount(2, $inserts);
        foreach ($inserts as $insert) {
            $this->assertLessThanOrEqual(500 * 8, count($insert['bindings']));
        }
        $this->assertSame(501, DB::table('prices_daily')->where('open', 100.)->count());
    }

    public function test_a_concurrent_complete_repair_cannot_be_overwritten_after_candidate_selection(): void
    {
        DB::table('prices_daily')->insert([...$this->row('2026-10-30'), 'open' => null]);
        $repaired = false;
        DB::listen(function (QueryExecuted $event) use (&$repaired) {
            if (! $repaired && str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'prices_daily')) {
                $repaired = true;
                DB::table('prices_daily')->where('symbol', 'SPY')->where('trade_date', '2026-10-30')->update([
                    'open' => 400., 'high' => 410., 'low' => 390., 'close' => 405., 'updated_at' => '2026-10-31 21:00:00',
                ]);
            }
        });

        $this->persist([[...$this->row('2026-10-30'), 'open' => 200., 'high' => 210., 'low' => 190., 'close' => 205.]]);

        $this->assertTrue($repaired);
        $this->assertDatabaseHas('prices_daily', [
            'symbol' => 'SPY', 'trade_date' => '2026-10-30',
            'open' => 400., 'high' => 410., 'low' => 390., 'close' => 405.,
            'created_at' => '2026-10-01 20:00:00', 'updated_at' => '2026-10-31 21:00:00',
        ]);
    }

    public function test_an_incomplete_provider_bar_does_not_replace_existing_partial_history(): void
    {
        DB::table('prices_daily')->insert([...$this->row('2026-10-30'), 'open' => null]);
        $original = (array) DB::table('prices_daily')->first();

        $this->persist([[...$this->row('2026-10-30'), 'open' => 200., 'high' => null, 'low' => 190., 'close' => 205.]]);

        $this->assertSame($original, (array) DB::table('prices_daily')->first());
    }

    private function row(string $date): array
    {
        return ['symbol' => 'SPY', 'trade_date' => $date,
            'open' => 100., 'high' => 110., 'low' => 90., 'close' => 105.,
            'created_at' => '2026-10-01 20:00:00', 'updated_at' => '2026-10-01 20:30:00'];
    }

    private function finnhub(array $bars): void
    {
        Http::fake(['finnhub.io/*' => Http::response($this->finnhubPayload($bars))]);
    }

    private function finnhubPayload(array $bars): array
    {
        $payload = ['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => []];
        foreach ($bars as $date => [$open, $high, $low, $close]) {
            $payload['t'][] = $this->timestamp($date);
            $payload['o'][] = $open;
            $payload['h'][] = $high;
            $payload['l'][] = $low;
            $payload['c'][] = $close;
        }

        return $payload;
    }

    private function timestamp(string $date): int
    {
        return CarbonImmutable::parse($date.' 16:00:00', 'America/New_York')->timestamp;
    }

    private function persist(array $rows): void
    {
        $job = new class(['SPY']) extends PricesBackfillJob
        {
            public function persist(array $rows): void
            {
                $this->upsertDailyRows($rows);
            }
        };
        $job->persist($rows);
    }
}
