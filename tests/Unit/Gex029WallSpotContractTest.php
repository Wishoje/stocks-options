<?php

namespace Tests\Unit;

use App\Http\Controllers\GexController;
use App\Http\Controllers\IntradayController;
use App\Services\WallService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Gex029LegacyWallService;
use Tests\TestCase;

class Gex029WallSpotContractTest extends TestCase
{
    public function test_spot_delegates_to_the_existing_policy_without_building_or_caching_a_composite(): void
    {
        $service = new class extends WallService
        {
            public array $lookups = [];

            public ?float $price = 101.25;

            public function latestSpot(string $symbol, ?int $maxAgeMinutes = null): ?float
            {
                $this->lookups[] = [$symbol, $maxAgeMinutes];

                return $this->price;
            }

            protected function intradayComposite(string $symbol): ?array
            {
                throw new \LogicException('A spot read must never build an option composite.');
            }
        };

        $this->assertSame(101.25, $service->currentPrice(' spy ', 30));
        $service->price = 102.5;
        $this->assertSame(102.5, $service->currentPrice('SPY'));
        $service->price = null;
        $this->assertNull($service->currentPrice('QQQ', 0));
        $this->assertSame([[' spy ', 30], ['SPY', null], ['QQQ', 0]], $service->lookups);
    }

    public static function wallResponses(): array
    {
        $items = [
            ['strike' => 99.5, 'net_gex_live' => -800],
            ['strike' => '101.25', 'net_gex_live' => '900'],
            ['strike' => 102, 'net_gex_live' => 900],
            ['strike' => 98, 'net_gex_live' => -700],
            ['strike' => 100, 'net_gex_live' => 0],
            ['strike' => 'invalid', 'net_gex_live' => 9999],
            ['strike' => 103, 'net_gex_live' => null],
        ];
        $live = ['asof' => '2026-09-04T14:59:00Z', 'items' => $items];

        return [
            'live, ties, fractions and invalid rows' => [200, $live, 30, ['call_wall' => 101.25, 'put_wall' => 99.5]],
            'stale age guard' => [200, array_replace($live, ['asof' => '2026-09-04T14:00:00Z']), 30, []],
            'stale permitted without age guard' => [200, array_replace($live, ['asof' => '2026-09-03T14:00:00Z']), null, ['call_wall' => 101.25, 'put_wall' => 99.5]],
            'missing timestamp' => [200, ['items' => $items], 30, []],
            'invalid timestamp' => [200, array_replace($live, ['asof' => 'not-a-date']), 30, []],
            'empty rows' => [200, ['asof' => $live['asof'], 'items' => []], 30, []],
            'not ready' => [202, ['status' => 'pending'], 30, []],
            'server error' => [503, ['error' => 'unavailable'], 30, []],
            'put only' => [200, ['asof' => $live['asof'], 'items' => [$items[0]]], 30, ['put_wall' => 99.5]],
        ];
    }

    #[DataProvider('wallResponses')]
    public function test_all_intraday_wall_fields_and_statuses_match_the_frozen_service(int $status, array $data, ?int $age, array $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T15:00:00Z'));
        $this->mock(IntradayController::class)->shouldReceive('strikesComposite')->twice()->andReturn(response()->json($data, $status));
        $legacy = new Gex029LegacyWallService;
        $candidate = new WallService;

        $this->assertSame($expected, $legacy->intradayWalls('SPY', $age));
        $this->assertSame($expected, $candidate->intradayWalls('SPY', $age));
        $this->assertSame($legacy->intradayCallWall('spy', $age), $candidate->intradayCallWall('spy', $age));
        $this->assertSame($legacy->distancePct(100, 101.25), $candidate->distancePct(100, 101.25));
        $this->assertSame(INF, $candidate->distancePct(0, 101.25));
        $this->travelBack();
    }

    public static function eodResponses(): array
    {
        return [[200, ['put_support' => 99.5, 'call_resistance' => 101.25]], [200, ['put_support' => null]], [202, ['status' => 'pending']], [504, ['error' => 'timeout']]];
    }

    #[DataProvider('eodResponses')]
    public function test_eod_wall_payload_and_non_success_states_remain_unchanged(int $status, array $data): void
    {
        $this->mock(GexController::class)->shouldReceive('getGexLevels')->twice()->andReturn(response()->json($data, $status));
        $this->assertSame((new Gex029LegacyWallService)->eodWalls('SPY'), (new WallService)->eodWalls('SPY'));
    }
}
