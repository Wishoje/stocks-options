<?php

namespace Tests\Unit;

use App\Support\GexExpirationUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GexExpirationUniverseTest extends TestCase
{
    public static function weekendClocks(): array
    {
        return [
            ['2026-09-04 21:00:00'],
            ['2026-09-05 15:00:00'],
            ['2026-09-06 15:00:00'],
        ];
    }

    #[DataProvider('weekendClocks')]
    public function test_one_catalog_read_derives_every_ui_timeframe_without_changing_weekday_boundaries(string $clock): void
    {
        $catalog = [
            '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08',
            '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21',
            '2026-10-05', '2026-10-06', '2026-12-03', '2026-12-04',
        ];
        $resolver = $this->resolver($catalog);
        $actual = $resolver->resolve('SPY', '30d', at: CarbonImmutable::parse($clock, 'UTC'));
        $expected = [
            '0d' => ['2026-09-04'],
            '1d' => ['2026-09-04', '2026-09-07'],
            '7d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11'],
            '14d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18'],
            '30d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21', '2026-10-05'],
            '90d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21', '2026-10-05', '2026-10-06', '2026-12-03'],
        ];
        $this->assertSame($expected, $actual['timeframe_expirations']);
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9], $actual['expiration_ids']);
        $this->assertSame([['SPY', '2026-09-04', '2026-12-03']], $resolver->queries);
    }

    public static function additionalTimeframes(): array
    {
        return [
            ['21d', '2026-09-25'],
            ['45d', '2026-10-20'],
            ['60d', '2026-11-04'],
            ['unrecognized', '2026-09-24'],
            ['1M', '2026-09-24'],
            ['0', '2026-09-24'],
            ['42', '2026-09-24'],
            ['', '2026-09-24'],
        ];
    }

    #[DataProvider('additionalTimeframes')]
    public function test_non_ui_timeframes_retain_exact_keys_and_inclusive_lookaheads(string $timeframe, string $end): void
    {
        $after = CarbonImmutable::parse($end)->addDay()->toDateString();
        $resolver = $this->resolver(['2026-09-03', '2026-09-04', $end, $after]);
        $result = $resolver->resolve('SPY', $timeframe, [], CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));

        $this->assertSame([$timeframe => ['2026-09-04', $end]], $result['timeframe_expirations']);
        $this->assertSame([2, 3], $result['expiration_ids']);
        $this->assertSame([['SPY', '2026-09-04', $end]], $resolver->queries);
    }

    public static function monthlyClocks(): array
    {
        return [
            'normal first week' => ['2026-01-01 15:00:00', 'UTC', '2026-01-16'],
            'month starts Friday' => ['2026-05-01 15:00:00', 'UTC', '2026-05-15'],
            'on third Friday' => ['2026-09-18 21:00:00', 'UTC', '2026-09-18'],
            'after third Friday' => ['2026-09-19 15:00:00', 'UTC', '2026-10-16'],
            'legacy month overflow' => ['2026-01-31 15:00:00', 'UTC', '2026-03-20'],
            'app already Saturday' => ['2026-09-19 01:00:00', 'UTC', '2026-10-16'],
            'app still Friday' => ['2026-09-19 01:00:00', 'America/New_York', '2026-09-18'],
        ];
    }

    #[DataProvider('monthlyClocks')]
    public function test_monthly_preserves_application_timezone_third_friday_and_month_overflow(string $clock, string $timezone, string $expected): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $resolver = $this->resolver([$expected]);
            $result = $resolver->resolve('SPY', 'monthly', [], CarbonImmutable::parse($clock, 'UTC'));

            $this->assertSame(['monthly' => [$expected]], $result['timeframe_expirations']);
            $this->assertSame([1], $result['expiration_ids']);
            $this->assertSame([['SPY', $expected, $expected]], $resolver->queries);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public static function midnightClocks(): array
    {
        return [
            ['2026-09-08 03:59:59', '2026-09-07', '2026-09-08'],
            ['2026-09-08 04:00:00', '2026-09-08', '2026-09-09'],
            ['2026-11-02 04:59:59', '2026-10-30', '2026-11-02'],
            ['2026-11-02 05:00:00', '2026-11-02', '2026-11-03'],
            ['2026-03-09 03:59:59', '2026-03-06', '2026-03-09'],
            ['2026-03-09 04:00:00', '2026-03-09', '2026-03-10'],
        ];
    }

    #[DataProvider('midnightClocks')]
    public function test_zero_and_one_day_use_new_york_midnight_including_dst(string $clock, string $zero, string $one): void
    {
        $resolver = $this->resolver([$zero, $one]);
        $result = $resolver->resolve('SPY', '1d', ['0d', '1d'], CarbonImmutable::parse($clock, 'UTC'));

        $this->assertSame(['0d' => [$zero], '1d' => [$zero, $one]], $result['timeframe_expirations']);
        $this->assertSame([1, 2], $result['expiration_ids']);
    }

    public function test_catalog_only_entries_remain_available_and_missing_timeframes_are_omitted(): void
    {
        $resolver = $this->resolver(['2026-09-18']);
        $result = $resolver->resolve('SPY', '0d', at: CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));

        $this->assertSame(['14d', '30d', '90d'], array_keys($result['timeframe_expirations']));
        $this->assertSame([], $result['expiration_ids']);
        $this->assertCount(1, $resolver->queries);
    }

    public function test_empty_catalog_and_other_symbols_do_not_create_availability(): void
    {
        $resolver = $this->resolver([]);
        $resolver->rows = [(object) ['id' => 9, 'symbol' => 'QQQ', 'expiration_date' => '2026-09-04']];
        $result = $resolver->resolve('SPY', '90d', at: CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));

        $this->assertSame(['timeframe_expirations' => [], 'expiration_ids' => []], $result);
        $this->assertCount(1, $resolver->queries);
    }

    public function test_shadow_comparison_preserves_date_and_timeframe_order_but_normalizes_internal_id_order(): void
    {
        $resolver = new GexExpirationUniverse;
        $legacy = ['timeframe_expirations' => ['0d' => ['2026-09-04'], '1d' => ['2026-09-04', '2026-09-07']], 'expiration_ids' => [2, 1]];
        $candidate = $legacy;
        $candidate['expiration_ids'] = [1, 2];
        $this->assertTrue($resolver->compareSelections($legacy, $candidate)['matches']);

        $candidate['timeframe_expirations']['1d'] = ['2026-09-07', '2026-09-04'];
        $this->assertFalse($resolver->compareSelections($legacy, $candidate)['matches']);
        $candidate = $legacy;
        $candidate['timeframe_expirations'] = array_reverse($legacy['timeframe_expirations'], true);
        $this->assertFalse($resolver->compareSelections($legacy, $candidate)['matches']);
        $candidate = $legacy;
        $candidate['expiration_ids'] = [1, 3];
        $this->assertFalse($resolver->compareSelections($legacy, $candidate)['matches']);
    }

    public static function catalogTimeframes(): array
    {
        return array_map(static fn (string $timeframe): array => [$timeframe], [
            '0d', '1d', '7d', '14d', '30d', '90d', '21d', '45d', '60d', 'monthly', 'unrecognized', '0', '',
        ]);
    }

    #[DataProvider('catalogTimeframes')]
    public function test_unsorted_manifest_catalog_matches_database_projection_without_any_database_access(string $timeframe): void
    {
        $resolver = $this->resolver([
            '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-11', '2026-09-18',
            '2026-09-24', '2026-09-25', '2026-10-05', '2026-10-20', '2026-11-04',
            '2026-12-03', '2026-12-04', '2027-01-15',
        ]);
        $clock = CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC');
        $expected = $resolver->resolve('SPY', $timeframe, at: $clock);
        $this->assertCount(1, $resolver->queries);
        $resolver->queries = [];
        $catalog = array_reverse(array_map(static fn ($row): array => [
            'expiration_id' => $row->id, 'expiration_date' => $row->expiration_date,
        ], $resolver->rows));
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();

        $actual = $resolver->resolveFromCatalog($catalog, $timeframe, at: $clock);

        $this->assertSame($expected, $actual);
        $this->assertSame([], $resolver->queries, 'A supplied catalog must never invoke the database loader.');
        $this->assertSame('2026-09-04 21:00:00', $clock->format('Y-m-d H:i:s'), 'Projection must not mutate the caller clock.');
    }

    public function test_manifest_projection_uses_the_supplied_instant_when_wall_clock_is_on_the_next_new_york_date(): void
    {
        $resolver = $this->resolver([]);
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 04:00:01', 'UTC'));
        try {
            $actual = $resolver->resolveFromCatalog([
                ['expiration_id' => 8, 'expiration_date' => '2026-09-08'],
                ['expiration_id' => 7, 'expiration_date' => '2026-09-07'],
            ], '0d', ['0d', '1d'], CarbonImmutable::parse('2026-09-08 03:59:59', 'UTC'));

            $this->assertSame([
                'timeframe_expirations' => ['0d' => ['2026-09-07'], '1d' => ['2026-09-07', '2026-09-08']],
                'expiration_ids' => [7],
            ], $actual);
            $this->assertSame([], $resolver->queries);
        } finally {
            $this->travelBack();
        }
    }

    public function test_empty_manifest_catalog_returns_empty_sets_without_loading_a_replacement(): void
    {
        $resolver = $this->resolver(['2026-09-04']);
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();

        $actual = $resolver->resolveFromCatalog([], '30d', at: CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));

        $this->assertSame(['timeframe_expirations' => [], 'expiration_ids' => []], $actual);
        $this->assertSame([], $resolver->queries);
    }

    private function resolver(array $dates): InMemoryGexExpirationUniverse
    {
        $resolver = new InMemoryGexExpirationUniverse;
        foreach ($dates as $index => $date) {
            $resolver->rows[] = (object) ['id' => $index + 1, 'symbol' => 'SPY', 'expiration_date' => $date];
        }

        return $resolver;
    }
}

class InMemoryGexExpirationUniverse extends GexExpirationUniverse
{
    public array $rows = [];

    public array $queries = [];

    protected function loadUniverse(string $symbol, string $start, string $end): Collection
    {
        $this->queries[] = [$symbol, $start, $end];

        return collect($this->rows)
            ->filter(static fn ($row): bool => $row->symbol === $symbol && $row->expiration_date >= $start && $row->expiration_date <= $end)
            ->sortBy('expiration_date')->values();
    }
}
