<?php

namespace Tests\Unit;

use App\Support\MarketSession;
use App\Support\PolygonClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolygonIntradayTimestampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-04 16:00:00.500000', 'UTC'));
        config()->set('intraday_freshness.enabled', true);
        // Isolate timestamp parsing from calendar policy. MarketSession's
        // holiday/refresh rules have separate tests; its session_date is the
        // current New York date, not a previous completed trading session.
        $this->app->instance(MarketSession::class, new class
        {
            public function describe(CarbonImmutable $receivedAt): array
            {
                return ['session_date' => $receivedAt->setTimezone('America/New_York')->toDateString()];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function sourceUnits(): array
    {
        $seconds = CarbonImmutable::parse('2026-09-04 15:59:30', 'UTC')->getTimestamp();

        return [
            'integer seconds' => [$seconds, '2026-09-04T15:59:30.000000Z'],
            'string seconds' => [(string) $seconds, '2026-09-04T15:59:30.000000Z'],
            'integer milliseconds' => [$seconds * 1000 + 123, '2026-09-04T15:59:30.123000Z'],
            'string milliseconds' => [$seconds.'123', '2026-09-04T15:59:30.123000Z'],
            'integer microseconds' => [$seconds * 1000000 + 123456, '2026-09-04T15:59:30.123456Z'],
            'string microseconds' => [$seconds.'123456', '2026-09-04T15:59:30.123456Z'],
            'integer nanoseconds' => [$seconds * 1000000000 + 123456789, '2026-09-04T15:59:30.123456Z'],
            'string nanoseconds' => [$seconds.'123456789', '2026-09-04T15:59:30.123456Z'],
        ];
    }

    #[DataProvider('sourceUnits')]
    public function test_option_day_timestamp_units_preserve_real_source_and_receipt_instants(mixed $timestamp, string $expected): void
    {
        $payload = (new PolygonTimestampProbe)->aggregate([$this->contract($timestamp)]);

        $this->assertSame($expected, $payload['source_asof']);
        $this->assertTrue($payload['source_timestamp_complete']);
        $this->assertSame('2026-09-04T16:00:00.500000Z', $payload['received_at']);
        $this->assertSame('2026-09-04T16:00:00+00:00', $payload['asof']);
        $this->assertSame(['call_vol' => 3, 'put_vol' => 0, 'premium' => 600.0], $payload['totals']);
    }

    public static function invalidSources(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'zero' => [0],
            'negative' => [-1],
            'floating point' => [1788537570.123],
            'scientific notation' => ['1.78853757e18'],
            'ISO text is not an epoch' => ['2026-09-04T15:59:30Z'],
            'too large' => ['99999999999999999999'],
            'unsupported precision' => ['178853757012'],
            'array' => [[]],
            'future' => [CarbonImmutable::parse('2026-09-04 16:00:01', 'UTC')->getTimestamp()],
            'future within same second' => [CarbonImmutable::parse('2026-09-04 16:00:00', 'UTC')->format('U').'500001'],
            'previous session' => [CarbonImmutable::parse('2026-09-03 15:59:30', 'UTC')->getTimestamp()],
            'same UTC date but previous NY session' => [CarbonImmutable::parse('2026-09-04 01:00:00', 'UTC')->getTimestamp()],
            'NY midnight marker' => [CarbonImmutable::parse('2026-09-04 00:00:00', 'America/New_York')->getTimestamp()],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_untrusted_timestamps_stay_unknown_without_changing_volume(mixed $timestamp): void
    {
        $contract = $this->contract($timestamp);
        $freshOtherField = CarbonImmutable::parse('2026-09-04 15:59:59', 'UTC')->format('U').'000000000';
        $contract['last_quote'] = ['last_updated' => $freshOtherField, 'midpoint' => 100];
        $contract['last_trade'] = ['sip_timestamp' => $freshOtherField, 'price' => 100];
        $contract['underlying_asset'] = ['last_updated' => $freshOtherField, 'price' => 500];
        $contract['last_updated'] = $freshOtherField;

        $payload = (new PolygonTimestampProbe)->aggregate([$contract]);

        $this->assertNull($payload['source_asof']);
        $this->assertFalse($payload['source_timestamp_complete']);
        $this->assertSame('2026-09-04T16:00:00.500000Z', $payload['received_at']);
        $this->assertSame(['call_vol' => 3, 'put_vol' => 0, 'premium' => 600.0], $payload['totals']);
    }

    public function test_utc_midnight_marker_is_not_treated_as_a_same_session_volume_update(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-05 01:00:00', 'UTC'));
        $marker = CarbonImmutable::parse('2026-09-05 00:00:00', 'UTC')->getTimestamp();
        $payload = (new PolygonTimestampProbe)->aggregate([$this->contract($marker)]);

        $this->assertNull($payload['source_asof']);
        $this->assertFalse($payload['source_timestamp_complete']);
    }

    public function test_a_mixed_chain_exposes_no_global_source_time_unless_every_used_contract_is_trusted(): void
    {
        $first = $this->contract(CarbonImmutable::parse('2026-09-04 15:59:00', 'UTC')->getTimestamp());
        $last = $this->contract(CarbonImmutable::parse('2026-09-04 15:59:30', 'UTC')->format('U').'987654321');
        $last['details']['contract_type'] = 'put';
        $probe = new PolygonTimestampProbe;

        $complete = $probe->aggregate([$first, $last]);
        $this->assertSame('2026-09-04T15:59:30.987654Z', $complete['source_asof']);
        $this->assertTrue($complete['source_timestamp_complete']);

        $last['day']['last_updated'] = null;
        $incomplete = $probe->aggregate([$first, $last]);
        $this->assertNull($incomplete['source_asof']);
        $this->assertFalse($incomplete['source_timestamp_complete']);
        $this->assertSame($complete['totals'], $incomplete['totals']);
        $this->assertSame($complete['by_strike'], $incomplete['by_strike']);
    }

    public function test_noncontributing_invalid_contracts_do_not_poison_source_completeness(): void
    {
        $good = $this->contract(CarbonImmutable::parse('2026-09-04 15:59:30', 'UTC')->getTimestamp());
        $badSide = $this->contract(null);
        $badSide['details']['contract_type'] = 'unknown';
        $badStrike = $this->contract(null);
        $badStrike['details']['strike_price'] = 0;
        $noActivity = $this->contract(null);
        $noActivity['day'] = ['volume' => 0];
        $payload = (new PolygonTimestampProbe)->aggregate([$good, $badSide, $badStrike, $noActivity]);

        $this->assertTrue($payload['source_timestamp_complete']);
        $this->assertSame('2026-09-04T15:59:30.000000Z', $payload['source_asof']);
        $this->assertSame(['call_vol' => 3, 'put_vol' => 0, 'premium' => 600.0], $payload['totals']);
    }

    public function test_no_contributors_or_failed_payload_never_claim_source_completeness(): void
    {
        $probe = new PolygonTimestampProbe;
        foreach ([$probe->aggregate([]), $probe->emptyPayload()] as $payload) {
            $this->assertNull($payload['source_asof']);
            $this->assertFalse($payload['source_timestamp_complete']);
            $this->assertSame('2026-09-04T16:00:00.500000Z', $payload['received_at']);
            $this->assertSame(['call_vol' => 0, 'put_vol' => 0, 'premium' => 0.0], $payload['totals']);
            $this->assertSame([], $payload['by_strike']);
        }
        $this->assertNull($probe->emptyPayload()['asof']);
    }

    public function test_rollout_flag_changes_only_legacy_clock_and_preserves_every_numeric_bucket(): void
    {
        $source = CarbonImmutable::parse('2026-09-04 15:59:30', 'UTC')->getTimestamp();
        $specs = [
            ['call', 500, '2026-09-11', ['volume' => 3, 'vwap' => 1.2345, 'close' => 99], []],
            ['put', 500, '2026-09-11', ['volume' => 2, 'close' => 2.5], []],
            ['call', 505, '2026-09-11', ['volume' => 4], ['last_trade' => ['price' => 3]]],
            ['put', 505, '2026-09-11', ['volume' => 5], ['quote' => ['midpoint' => 1.25]]],
            ['call', 505, '2026-09-18', ['volume' => 6], ['last_quote' => ['bid' => 1, 'ask' => 3]]],
            ['put', 505, '2026-09-18', ['volume' => 7], ['quote' => ['bid' => 0, 'ask' => 2]]],
            ['call', 510, '2026-09-18', ['volume' => 8], []],
            ['put', 510, '2026-09-18', ['volume' => -5, 'close' => 4], []],
            ['call', 515, '2026-09-18', ['volume' => 0, 'vwap' => 0], []],
        ];
        $contracts = [];
        foreach ($specs as [$side, $strike, $expiry, $day, $other]) {
            $contract = $this->contract($source);
            $contract['details'] = ['contract_type' => $side, 'strike_price' => $strike, 'expiration_date' => $expiry];
            $contract['day'] = $day + ['last_updated' => $source];
            $contracts[] = $contract + $other;
        }
        $probe = new PolygonTimestampProbe;
        config()->set('intraday_freshness.enabled', false);
        $legacy = $probe->aggregate($contracts);
        config()->set('intraday_freshness.enabled', true);
        $enabled = $probe->aggregate($contracts);

        $this->assertSame('2026-09-04T11:59:00-04:00', $legacy['asof']);
        $this->assertSame('2026-09-04T16:00:00+00:00', $enabled['asof']);
        unset($legacy['asof'], $enabled['asof']);
        $this->assertSame($legacy, $enabled);
        $this->assertSame(['call_vol' => 21, 'put_vol' => 14, 'premium' => 5295.35], $enabled['totals']);
        $this->assertSame([
            ['strike' => 500.0, 'exp_date' => '2026-09-11', 'call_vol' => 3, 'put_vol' => 2, 'call_prem' => 370.35, 'put_prem' => 500.0],
            ['strike' => 505.0, 'exp_date' => '2026-09-11', 'call_vol' => 4, 'put_vol' => 5, 'call_prem' => 1200.0, 'put_prem' => 625.0],
            ['strike' => 505.0, 'exp_date' => '2026-09-18', 'call_vol' => 6, 'put_vol' => 7, 'call_prem' => 1200.0, 'put_prem' => 1400.0],
            ['strike' => 510.0, 'exp_date' => '2026-09-18', 'call_vol' => 8, 'put_vol' => 0, 'call_prem' => 0.0, 'put_prem' => 0.0],
            ['strike' => 515.0, 'exp_date' => '2026-09-18', 'call_vol' => 0, 'put_vol' => 0, 'call_prem' => 0.0, 'put_prem' => 0.0],
        ], $enabled['by_strike']);
    }

    private function contract(mixed $timestamp): array
    {
        return [
            'details' => ['contract_type' => 'call', 'strike_price' => 500, 'expiration_date' => '2026-09-11'],
            'day' => ['volume' => 3, 'vwap' => 2, 'last_updated' => $timestamp],
        ];
    }
}

class PolygonTimestampProbe extends PolygonClient
{
    public function aggregate(array $contracts): array
    {
        return $this->fromRawContracts($contracts, 'timestamp-proof');
    }

    public function emptyPayload(): array
    {
        return $this->blankPayload();
    }
}
