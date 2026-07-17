<?php

namespace Tests\Feature\Regression;

use App\Support\Regression\BaselineComparator;
use App\Support\Regression\CanonicalJson;
use App\Support\Regression\MarketDataBaseline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\MySqlTestCase;
use Tests\Support\MarketDataScenario;

class MarketDataBaselineTest extends MySqlTestCase
{
    use RefreshDatabase;

    private MarketDataBaseline $baselineService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York'));
        MarketDataScenario::seed();
        $this->baselineService = app(MarketDataBaseline::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_capture_is_deterministic_complete_and_free_of_user_pii(): void
    {
        $apiPayloads = [
            'contract' => ['status' => 'ok', 'fields' => ['symbol', 'items']],
            'sensitive' => [
                'id' => 98765,
                'user_id' => 12345,
                'email' => 'regression@example.test',
                'apiKey' => 'provider-key-must-not-survive',
                'authorization' => 'Bearer provider-token-must-not-survive',
                'note' => 'sk_live_value-must-not-survive',
            ],
        ];
        $first = $this->baselineService->capture(
            MarketDataScenario::SYMBOLS,
            MarketDataScenario::DATE,
            $apiPayloads
        );
        $second = $this->baselineService->capture(
            array_reverse(MarketDataScenario::SYMBOLS),
            MarketDataScenario::DATE,
            array_reverse($apiPayloads, true)
        );

        $this->assertSame($first, $second);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(30, data_get($first, 'tables.option_expirations.row_count'));
        $this->assertCount(6, data_get($first, 'tables.option_expirations.expiration_set'));
        $this->assertGreaterThan(0, data_get($first, 'tables.option_chain_data.sums.open_interest'));
        $this->assertGreaterThan(0, data_get($first, 'tables.option_live_counters.sums.volume'));

        $firstExpiry = MarketDataScenario::expirationDates()[0];
        $this->assertFalse(data_get($first, "calculator.SPY.expirations.{$firstExpiry}.latest_is_partial"));
        $this->assertTrue(data_get($first, "calculator.QQQ.expirations.{$firstExpiry}.latest_is_partial"));
        $this->assertTrue(data_get($first, "calculator.QQQ.expirations.{$firstExpiry}.latest_is_thinner_than_fullest"));
        $this->assertSame([], data_get($first, 'calculator.COLD.all_expirations'));

        $encoded = CanonicalJson::encode($first);
        $this->assertStringNotContainsString('regression@example.test', $encoded);
        $this->assertStringNotContainsString('provider-key-must-not-survive', $encoded);
        $this->assertStringNotContainsString('provider-token-must-not-survive', $encoded);
        $this->assertStringNotContainsString('sk_live_value-must-not-survive', $encoded);
        $this->assertStringNotContainsString('DB_PASSWORD', $encoded);
        $this->assertSame(0, data_get($first, 'api.sensitive.id'));
        $this->assertSame(0, data_get($first, 'api.sensitive.user_id'));
        $this->assertSame('<redacted-pii>', data_get($first, 'api.sensitive.email'));
        $this->assertSame('<redacted-secret>', data_get($first, 'api.sensitive.apiKey'));
    }

    public function test_comparator_detects_missing_expiration_changed_aggregate_and_response_contract(): void
    {
        $baseline = $this->baselineService->capture(
            MarketDataScenario::SYMBOLS,
            MarketDataScenario::DATE,
            ['gex_spy_30d' => ['symbol' => 'SPY', 'status' => 'ok', 'items' => [['strike' => 100]]]]
        );
        $comparator = new BaselineComparator();

        $missingExpiration = $baseline;
        array_pop($missingExpiration['tables']['option_expirations']['expiration_set']);
        $result = $comparator->compare($baseline, $missingExpiration);
        $this->assertFalse($result['matches']);
        $this->assertTrue($this->hasDifferenceAt($result, 'expiration_set'));

        $changedAggregate = $baseline;
        $changedAggregate['tables']['option_chain_data']['sums']['open_interest']++;
        $result = $comparator->compare($baseline, $changedAggregate);
        $this->assertFalse($result['matches']);
        $this->assertTrue($this->hasDifferenceAt($result, 'sums.open_interest'));

        $changedContract = $baseline;
        unset($changedContract['api']['gex_spy_30d']['status']);
        $result = $comparator->compare($baseline, $changedContract);
        $this->assertFalse($result['matches']);
        $this->assertTrue($this->hasDifferenceType($result, 'missing_field'));
    }

    public function test_capture_and_comparator_detect_a_newer_partial_calculator_publication(): void
    {
        $baseline = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);
        $expiry = MarketDataScenario::expirationDates()[0];

        DB::table('option_snapshots')->insert([
            $this->snapshotRow('call', 99, $expiry),
            $this->snapshotRow('put', 99, $expiry),
            $this->snapshotRow('call', 100, $expiry),
            $this->snapshotRow('put', 100, $expiry),
        ]);

        $candidate = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);
        $state = data_get($candidate, "calculator.SPY.expirations.{$expiry}");

        $this->assertTrue($state['latest_is_partial']);
        $this->assertTrue($state['latest_is_thinner_than_fullest']);
        $this->assertSame(4, $state['latest_row_count']);
        $this->assertFalse((new BaselineComparator())->compare($baseline, $candidate)['matches']);
    }

    public function test_comparator_rejects_an_older_intraday_asof_overwrite(): void
    {
        $baseline = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);

        DB::table('option_live_counters')
            ->where('symbol', 'SPY')
            ->whereDate('trade_date', MarketDataScenario::DATE)
            ->update([
                'volume' => 1,
                'asof' => '2026-03-18 19:55:00',
                'updated_at' => '2026-03-18 19:55:00',
            ]);

        $candidate = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);
        $result = (new BaselineComparator())->compare($baseline, $candidate);

        $this->assertFalse($result['matches']);
        $this->assertTrue($this->hasDifferenceType($result, 'stale_timestamp'));
        $this->assertTrue($this->hasDifferenceAt($result, 'latest_timestamps_by_symbol.asof.SPY'));
    }

    public function test_comparator_detects_one_stale_row_even_when_the_symbol_maximum_is_unchanged(): void
    {
        $baseline = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);
        $row = DB::table('option_live_counters')
            ->where('symbol', 'SPY')
            ->whereNotNull('strike')
            ->orderBy('id')
            ->first(['id']);

        DB::table('option_live_counters')
            ->where('id', $row->id)
            ->update(['asof' => '2026-03-18 19:50:00']);

        $candidate = $this->baselineService->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);
        $this->assertSame(
            data_get($baseline, 'tables.option_live_counters.latest_timestamps_by_symbol.asof.SPY'),
            data_get($candidate, 'tables.option_live_counters.latest_timestamps_by_symbol.asof.SPY')
        );

        $result = (new BaselineComparator())->compare($baseline, $candidate);
        $this->assertFalse($result['matches']);
        $this->assertTrue($this->hasDifferenceType($result, 'stale_timestamp'));
        $this->assertTrue($this->hasDifferenceAt($result, 'timestamps_by_natural_key'));
    }

    public function test_artisan_command_captures_and_compares_artifacts(): void
    {
        $directory = storage_path('framework/testing/regression-baseline');
        $baselinePath = $directory.DIRECTORY_SEPARATOR.'baseline.json';
        $apiDirectory = $directory.DIRECTORY_SEPARATOR.'api';

        File::deleteDirectory($directory);

        try {
            File::ensureDirectoryExists($apiDirectory);
            File::put(
                $apiDirectory.DIRECTORY_SEPARATOR.'gex.json',
                json_encode([
                    'symbol' => 'SPY',
                    'status' => 'ok',
                    'api_key' => 'command-secret-must-not-survive',
                ], JSON_THROW_ON_ERROR)
            );

            $this->artisan('market-data:baseline', [
                'action' => 'capture',
                '--symbols' => implode(',', MarketDataScenario::SYMBOLS),
                '--date' => MarketDataScenario::DATE,
                '--output' => $baselinePath,
                '--api-dir' => $apiDirectory,
            ])->assertSuccessful();

            $this->assertFileExists($baselinePath);
            $artifact = json_decode(File::get($baselinePath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(MarketDataBaseline::SCHEMA_VERSION, $artifact['schema_version']);
            $this->assertSame('SPY', data_get($artifact, 'api.gex.symbol'));
            $this->assertSame('<redacted-secret>', data_get($artifact, 'api.gex.api_key'));
            $this->assertStringNotContainsString('command-secret-must-not-survive', File::get($baselinePath));

            $this->artisan('market-data:baseline', [
                'action' => 'compare',
                '--baseline' => $baselinePath,
                '--api-dir' => $apiDirectory,
            ])->assertSuccessful();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_canonical_json_sorts_only_explicitly_unordered_lists(): void
    {
        $left = ['items' => [['symbol' => 'QQQ'], ['symbol' => 'SPY']]];
        $right = ['items' => [['symbol' => 'SPY'], ['symbol' => 'QQQ']]];

        $this->assertNotSame(CanonicalJson::normalize($left), CanonicalJson::normalize($right));
        $this->assertSame(
            CanonicalJson::normalize($left, ['$.items']),
            CanonicalJson::normalize($right, ['$.items'])
        );
    }

    public function test_comparator_preserves_numeric_api_types_while_tolerating_float_noise(): void
    {
        $comparator = new BaselineComparator();

        $typeChange = $comparator->compare(
            ['api' => ['volume' => 100]],
            ['api' => ['volume' => '100']]
        );
        $floatNoise = $comparator->compare(
            ['api' => ['gamma' => 0.1234561]],
            ['api' => ['gamma' => 0.1234562]]
        );

        $this->assertFalse($typeChange['matches']);
        $this->assertTrue($this->hasDifferenceType($typeChange, 'type_changed'));
        $this->assertTrue($floatNoise['matches']);
    }

    /** @return array<string,mixed> */
    private function snapshotRow(string $type, float $strike, string $expiry): array
    {
        return [
            'symbol' => 'SPY',
            'ticker' => sprintf('O:SPY%s%s%08d', str_replace('-', '', $expiry), $type === 'call' ? 'C' : 'P', (int) ($strike * 1000)),
            'type' => $type,
            'strike' => $strike,
            'expiry' => $expiry,
            'bid' => 2.9,
            'ask' => 3.1,
            'mid' => 3.0,
            'underlying_price' => 100,
            'fetched_at' => '2026-03-18 20:59:00',
        ];
    }

    /** @param array{differences:array<int,array<string,mixed>>} $result */
    private function hasDifferenceType(array $result, string $type): bool
    {
        return collect($result['differences'])->contains(
            fn (array $difference): bool => $difference['type'] === $type
        );
    }

    /** @param array{differences:array<int,array<string,mixed>>} $result */
    private function hasDifferenceAt(array $result, string $pathFragment): bool
    {
        return collect($result['differences'])->contains(
            fn (array $difference): bool => str_contains($difference['path'], $pathFragment)
        );
    }
}
