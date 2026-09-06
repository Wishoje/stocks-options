<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class GexTimeframeParityTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('symbol_bootstrap.enabled', false);
        config()->set('cache.default', 'array');
        config()->set('services.massive.eod_min_side_strike_ratio', 0.5);
        config()->set('services.massive.eod_force_data_date', '');
        $this->travelTo(CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_selected_snapshots_preserve_full_strike_arithmetic_walls_and_totals(): void
    {
        $first = $this->expiration('SPY', '2026-09-11');
        $second = $this->expiration('SPY', '2026-09-18');

        // Each tuple is strike, side, OI, volume, gamma, spot. The second
        // expiration intentionally selects an older balanced snapshot.
        $this->rows($first, '2026-09-04', [
            ['95.25', 'call', 2, 1, 0.01, 10], ['95.25', 'put', 5, 3, 0.02, 10],
            ['97.50', 'call', 4, 0, 0.01, 10], ['97.50', 'put', 14, 8, 0.01, 10],
            ['100.25', 'call', 10, 7, 0.02, 10], ['100.25', 'put', 4, 2, 0.01, 10],
            ['102.50', 'call', 6, 3, 0.01, 10], ['102.50', 'put', 0, 0, 0.01, 10],
            ['105.75', 'call', 3, 2, 0.01, 0], ['105.75', 'put', 4, null, 0.01, null],
            ['110.00', 'call', 8, 4, 0.02, null], ['110.00', 'put', 1, 1, 0.01, 0],
            ['112.50', 'call', null, null, null, null], ['112.50', 'put', 0, 0, 0, 100],
        ]);
        $this->rows($second, '2026-09-03', [
            ['100.25', 'call', 5, 2, 0.02, 20], ['100.25', 'put', 1, 1, 0.01, 20],
            ['102.50', 'call', 2, 1, 0.01, 10], ['102.50', 'put', 1, 0, 0, 10],
        ]);
        $this->rows($second, '2026-09-04', [
            ['999.00', 'call', 999999, 999999, 1, 100],
        ]);

        $this->rows($first, '2026-09-03', [
            ['95.25', 'call', 1, 1], ['95.25', 'put', 4, 2],
            ['97.50', 'call', 2, 0], ['97.50', 'put', 10, 4],
            ['100.25', 'call', 8, 5], ['100.25', 'put', 3, 1],
            ['102.50', 'call', 4, 2], ['102.50', 'put', 2, 1],
            ['105.75', 'call', 0, 0], ['105.75', 'put', 0, 0],
            ['110.00', 'call', 6, 2], ['110.00', 'put', 1, 1],
            ['112.50', 'call', null, 0], ['112.50', 'put', 0, null],
            // Prior-only strikes are not added to today's strike list or
            // subtracted from its total deltas by the existing API contract.
            ['90.00', 'call', 10, 7], ['90.00', 'put', 20, 9],
        ]);
        foreach (['95.25', '97.50', '100.25', '102.50', '105.75', '110.00', '112.50'] as $strike) {
            $this->rows($first, '2026-08-28', [[$strike, 'call', 1, 1], [$strike, 'put', 2, 1]]);
        }
        $this->rows($second, '2026-08-28', [
            ['100.25', 'call', 2, 2], ['100.25', 'put', 1, 1],
            ['102.50', 'call', 1, 1], ['102.50', 'put', 1, 1],
        ]);
        Cache::put('gamma_strength:SPY:2026-09-04', [
            'strength' => 0.75,
            'sign' => 'positive',
            'source_meta' => ['fixture' => 'selected-snapshots'],
        ], 3600);

        $payload = $this->payload('SPY', '14d');
        foreach ([
            'symbol' => 'SPY', 'timeframe' => '14d', 'data_date' => '2026-09-04',
            'data_age_days' => 0,
            'expiration_dates' => ['2026-09-11', '2026-09-18'],
            'available_timeframes' => ['7d', '14d', '30d', '90d'],
            'hvl' => '100.25',
            'call_resistance' => '100.25', 'call_wall_2' => '102.50', 'call_wall_3' => '110.00',
            'put_support' => '97.50', 'put_wall_2' => '95.25', 'put_wall_3' => '105.75',
            'call_open_interest_total' => 40, 'put_open_interest_total' => 30,
            'call_interest_percentage' => 57.14, 'put_interest_percentage' => 42.86,
            'call_volume_total' => 20, 'put_volume_total' => 15, 'pcr_volume' => 0.75,
            'total_oi_delta' => 20, 'total_volume_delta' => 12,
            'date_prev' => '2026-09-03', 'date_prev_gap_trading_days' => 1, 'date_prev_is_stale' => false,
            'date_prev_week' => '2026-08-28', 'date_prev_week_gap_trading_days' => 5,
            'regime_strength' => 0.75, 'gamma_sign' => 'positive',
            'regime_source_meta' => ['fixture' => 'selected-snapshots'],
        ] as $key => $value) {
            $this->assertSame($value, $payload[$key], $key);
        }

        // day = call OI, put OI, call volume, put volume deltas;
        // percentages and week deltas use the same field order.
        $expected = [
            $this->expectedStrike('95.25', 200, 1000, [1, 1, 0, 1], [100, 25, 0, 50], [1, 3, 0, 2]),
            $this->expectedStrike('97.50', 400, 1400, [2, 4, 0, 4], [100, 40, 0, 100], [3, 12, -1, 7]),
            $this->expectedStrike('100.25', 6000, 800, [2, 1, 2, 1], [15.38, 25, 28.57, 50], [12, 2, 6, 1]),
            $this->expectedStrike('102.50', 800, 0, [2, -2, 1, -1], [33.33, -66.67, 33.33, -100], [6, -2, 2, -2]),
            $this->expectedStrike('105.75', 3, 4, [3, 4, 2, 0], [0, 0, 0, 0], [2, 2, 1, -1]),
            $this->expectedStrike('110.00', 16, 1, [2, 0, 2, 0], [33.33, 0, 100, 0], [7, -1, 3, 0]),
            $this->expectedStrike('112.50', 0, 0, [0, 0, 0, 0], [0, 0, 0, 0], [-1, -2, -1, -1]),
        ];
        $this->assertCount(count($expected), $payload['strike_data']);
        foreach ($expected as $index => $row) {
            $this->assertSame(array_keys($row), array_keys($payload['strike_data'][$index]));
            foreach ($row as $key => $value) {
                if ($key === 'strike') {
                    $this->assertSame($value, $payload['strike_data'][$index][$key]);
                } else {
                    $this->assertEqualsWithDelta($value, $payload['strike_data'][$index][$key], 0.00000001, $row['strike'].' '.$key);
                }
            }
        }
        $this->assertSame($payload, $this->payload('SPY', '14d'), 'A warm cache must return the same published payload.');
    }

    public static function weekdayAndWeekendClocks(): array
    {
        return [
            'Friday after close' => ['2026-09-04 21:00:00'],
            'Saturday' => ['2026-09-05 15:00:00'],
            'Sunday' => ['2026-09-06 15:00:00'],
        ];
    }

    #[DataProvider('weekdayAndWeekendClocks')]
    public function test_ui_timeframes_include_their_weekday_boundaries_and_no_later_expirations(string $clock): void
    {
        $this->travelTo(CarbonImmutable::parse($clock, 'UTC'));
        $catalog = [
            '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08',
            '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21',
            '2026-10-05', '2026-10-06', '2026-12-03', '2026-12-04',
        ];
        foreach ($catalog as $index => $date) {
            $id = $this->expiration('QQQ', $date);
            $strike = (100 + $index).'.25';
            $this->rows($id, '2026-09-04', [
                [$strike, 'call', $index + 1, $index + 2],
                [$strike, 'put', 2 * $index + 1, $index + 3],
            ]);
        }
        $otherSymbol = $this->expiration('SPY', '2026-09-04');
        $this->rows($otherSymbol, '2026-09-04', [['999.00', 'call', 99999, 99999], ['999.00', 'put', 99999, 99999]]);

        // These are the existing weekday-based lookaheads, including Monday
        // Sep 7. Holiday-calendar semantics are not changed by this hotfix.
        $expected = [
            '0d' => ['2026-09-04'],
            '1d' => ['2026-09-04', '2026-09-07'],
            '7d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11'],
            '14d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18'],
            '30d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21', '2026-10-05'],
            '90d' => ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-14', '2026-09-18', '2026-09-21', '2026-10-05', '2026-10-06', '2026-12-03'],
        ];
        foreach ($expected as $timeframe => $dates) {
            $payload = $this->payload('QQQ', $timeframe);
            $this->assertSame($dates, $payload['expiration_dates'], $timeframe);
            $this->assertSame($expected, $payload['timeframe_expirations']);
            $this->assertSame(array_keys($expected), $payload['available_timeframes']);
            $indexes = array_map(static fn (string $date): int => array_search($date, $catalog, true), $dates);
            $this->assertSame(array_map(static fn (int $index): string => (100 + $index).'.25', $indexes), array_column($payload['strike_data'], 'strike'));
            $this->assertSame(array_sum(array_map(static fn (int $index): int => $index + 1, $indexes)), $payload['call_open_interest_total']);
            $this->assertSame(array_sum(array_map(static fn (int $index): int => 2 * $index + 1, $indexes)), $payload['put_open_interest_total']);
            $this->assertSame(array_sum(array_map(static fn (int $index): int => $index + 2, $indexes)), $payload['call_volume_total']);
            $this->assertSame(array_sum(array_map(static fn (int $index): int => $index + 3, $indexes)), $payload['put_volume_total']);
            $this->assertNull($payload['date_prev']);
            $this->assertNull($payload['date_prev_week']);
            $this->assertSame($payload['call_open_interest_total'] + $payload['put_open_interest_total'], $payload['total_oi_delta']);
            $this->assertSame($payload['call_volume_total'] + $payload['put_volume_total'], $payload['total_volume_delta']);
        }
    }

    public function test_zero_and_null_inputs_keep_zero_denominators_and_missing_prior_metadata(): void
    {
        $id = $this->expiration('TSLA', '2026-09-04');
        $this->rows($id, '2026-09-04', [
            ['100.25', 'call', null, null, null, null],
            ['100.25', 'put', 0, 0, 0.01, 0],
        ]);

        $payload = $this->payload('TSLA', '0d');
        foreach (['call_open_interest_total', 'put_open_interest_total', 'call_interest_percentage', 'put_interest_percentage', 'call_volume_total', 'put_volume_total', 'total_oi_delta', 'total_volume_delta'] as $key) {
            $this->assertSame(0, $payload[$key], $key);
        }
        foreach (['pcr_volume', 'call_resistance', 'call_wall_2', 'call_wall_3', 'put_support', 'put_wall_2', 'put_wall_3', 'date_prev', 'date_prev_gap_trading_days', 'date_prev_week', 'date_prev_week_gap_trading_days', 'regime_strength', 'gamma_sign', 'regime_source_meta'] as $key) {
            $this->assertNull($payload[$key], $key);
        }
        $this->assertFalse($payload['date_prev_is_stale']);
        $this->assertSame('100.25', $payload['hvl']);
        $this->assertEquals([
            $this->expectedStrike('100.25', 0, 0, [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]),
        ], $payload['strike_data']);
    }

    public function test_actual_prior_snapshot_gap_is_reported_instead_of_assuming_yesterday(): void
    {
        $id = $this->expiration('TSLA', '2026-09-11');
        $this->rows($id, '2026-09-04', [['100.25', 'call', 10, 4], ['100.25', 'put', 6, 2]]);
        $this->rows($id, '2026-08-31', [['100.25', 'call', 7, 3], ['100.25', 'put', 5, 1]]);

        $payload = $this->payload('TSLA', '7d');
        $this->assertSame('2026-08-31', $payload['date_prev']);
        $this->assertSame(4, $payload['date_prev_gap_trading_days']);
        $this->assertTrue($payload['date_prev_is_stale']);
        $this->assertNull($payload['date_prev_week']);
        $this->assertSame(4, $payload['total_oi_delta']);
        $this->assertSame(2, $payload['total_volume_delta']);
    }

    private function payload(string $symbol, string $timeframe): array
    {
        $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
        ]));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function expiration(string $symbol, string $date): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rows(int $expirationId, string $date, array $tuples): void
    {
        foreach ($tuples as $tuple) {
            DB::table('option_chain_data')->insert([
                'expiration_id' => $expirationId,
                'data_date' => $date,
                'strike' => $tuple[0],
                'option_type' => $tuple[1],
                'open_interest' => $tuple[2],
                'volume' => $tuple[3],
                'gamma' => array_key_exists(4, $tuple) ? $tuple[4] : 0.01,
                'underlying_price' => array_key_exists(5, $tuple) ? $tuple[5] : 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function expectedStrike(string $strike, float $callGex, float $putGex, array $day, array $percent, array $week): array
    {
        return [
            'strike' => $strike,
            'net_gex' => $callGex - $putGex,
            'call_gex' => $callGex,
            'put_gex' => $putGex,
            'call_oi_delta' => $day[0],
            'put_oi_delta' => $day[1],
            'call_oi_delta_pct' => $percent[0],
            'put_oi_delta_pct' => $percent[1],
            'call_vol_delta' => $day[2],
            'put_vol_delta' => $day[3],
            'call_vol_delta_pct' => $percent[2],
            'put_vol_delta_pct' => $percent[3],
            'call_oi_wow' => $week[0],
            'put_oi_wow' => $week[1],
            'call_vol_wow' => $week[2],
            'put_vol_wow' => $week[3],
        ];
    }
}
