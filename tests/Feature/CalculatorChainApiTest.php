<?php

namespace Tests\Feature;

use App\Support\CalculatorPublicationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalculatorChainApiTest extends TestCase
{
    private const CONNECTION = 'calculator-chain-api-test';

    private CalculatorPublicationRepository $publications;

    private string $originalDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseConnection = DB::getDefaultConnection();
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        $this->createTables();
        $this->withoutMiddleware();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 03:30:00', 'UTC'));
        config()->set('calculator_underlying.freshness_seconds.closed.live', 3600);
        config()->set('calculator_underlying.freshness_seconds.closed.usable', 86400);
        $this->publications = app(CalculatorPublicationRepository::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_legacy_fallback_keeps_old_expirations_and_never_uses_snapshot_spot_as_price(): void
    {
        $this->insertLegacyContract('SPY', '2026-08-21', '2026-08-16 03:00:00', 100.0);
        $this->insertLegacyContract('SPY', '2026-08-28', '2026-08-14 20:00:00', 100.0);
        DB::table('option_expirations')->insert([
            ['symbol' => 'SPY', 'expiration_date' => '2026-08-21'],
            ['symbol' => 'SPY', 'expiration_date' => '2026-08-28'],
        ]);

        $response = $this->getJson('/api/option-chain?symbol=spy&expiry=2026-08-28');

        $response->assertOk()
            ->assertJsonPath('underlying.symbol', 'SPY')
            ->assertJsonPath('underlying.price', null)
            ->assertJsonPath('underlying.status', 'unavailable')
            ->assertJsonPath('underlying.reason', 'missing_quote')
            ->assertJsonPath('catalog_state', 'stale')
            ->assertJsonPath('requested_expiry', '2026-08-28')
            ->assertJsonPath('resolved_expiry', '2026-08-28')
            ->assertJsonPath('as_of_exchange_date', '2026-08-15')
            ->assertJsonPath('chain.0.contract_symbol', 'O:SPY260828C00100000')
            ->assertJsonPath('chain.0.expiration_date', '2026-08-28')
            ->assertJsonPath('chain.0.dte', 13)
            ->assertJsonPath('chain.0.iv', 0.25)
            ->assertJsonCount(2, 'expirations');

        $this->assertSame(
            ['2026-08-21', '2026-08-28'],
            collect($response->json('expirations'))->pluck('value')->all()
        );

    }

    public function test_real_timestamped_one_hundred_dollar_quote_is_distinct_from_unavailable(): void
    {
        $this->insertLegacyContract('EXACT', '2026-08-21', '2026-08-16 03:00:00', null);
        DB::table('underlying_quotes')->insert([
            'symbol' => 'EXACT',
            'source' => 'massive-v3-snapshot',
            'last_price' => 100,
            'prev_close' => 99,
            'asof' => '2026-08-16 03:29:30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/option-chain?symbol=EXACT')
            ->assertOk()
            ->assertJsonPath('underlying.price', 100)
            ->assertJsonPath('underlying.status', 'live')
            ->assertJsonPath('underlying.usable_for_calculation', true)
            ->assertJsonPath('underlying.source', 'massive-v3-snapshot');
    }

    public function test_complete_catalog_is_authoritative_and_missing_request_resolves_on_the_server(): void
    {
        $run = $this->publishCatalog('SPY', 'catalog:complete', [
            '2026-08-21' => 4.25,
            '2026-08-28' => 5.25,
        ]);

        $response = $this->getJson('/api/option-chain?symbol=SPY&expiry=2026-09-18');

        $response->assertOk()
            ->assertJsonPath('catalog_state', 'complete')
            ->assertJsonPath('run_state', 'complete')
            ->assertJsonPath('selected_chain_state', 'ready')
            ->assertJsonPath('requested_expiry', '2026-09-18')
            ->assertJsonPath('resolved_expiry', '2026-08-21')
            ->assertJsonPath('catalog.run_id', $run['id'])
            ->assertJsonPath('run_id', $run['id'])
            ->assertJsonPath('expected_count', 2)
            ->assertJsonPath('completed_count', 2)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('publication_generation', 1)
            ->assertJsonPath('chain.0.mid', 4.25)
            ->assertJsonPath('expirations.0.readiness', 'ready')
            ->assertJsonPath('expirations.0.publication.publication_generation', 1)
            ->assertJsonCount(2, 'expirations');
    }

    public function test_complete_empty_catalog_is_a_terminal_no_options_read_and_never_requeues(): void
    {
        Bus::fake();
        $run = $this->publishCatalog('EMPTY', 'catalog:no-options', []);

        foreach (range(1, 2) as $request) {
            $this->getJson('/api/option-chain?symbol=EMPTY')
                ->assertOk()
                ->assertJsonPath('status', 'no_options')
                ->assertJsonPath('catalog_state', 'complete')
                ->assertJsonPath('run_state', 'complete')
                ->assertJsonPath('run_id', $run['id'])
                ->assertJsonPath('expected_count', 0)
                ->assertJsonPath('completed_count', 0)
                ->assertJsonPath('failed_count', 0)
                ->assertJsonPath('resolved_expiry', null)
                ->assertJsonPath('refresh_queued', false)
                ->assertJsonCount(0, 'expirations')
                ->assertJsonCount(0, 'chain');
        }

        Bus::assertNothingDispatched();
    }

    public function test_complete_catalog_with_only_expired_members_is_due_instead_of_no_options(): void
    {
        Bus::fake();
        $run = $this->publishCatalog('AGED', 'catalog:expired', [
            '2026-08-21' => 2.5,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 16:00:00', 'UTC'));

        $this->getJson('/api/option-chain?symbol=AGED')
            ->assertStatus(202)
            ->assertJsonPath('status', 'no_snapshot')
            ->assertJsonPath('catalog_state', 'complete')
            ->assertJsonPath('run_state', 'complete')
            ->assertJsonPath('run_id', $run['id'])
            ->assertJsonPath('expected_count', 1)
            ->assertJsonPath('resolved_expiry', null)
            ->assertJsonPath('refresh_queued', false)
            ->assertJsonCount(0, 'expirations')
            ->assertJsonCount(0, 'chain');

        Bus::assertNothingDispatched();
    }

    public function test_newer_partial_catalog_does_not_hide_the_last_complete_expiration_set(): void
    {
        $complete = $this->publishCatalog('QQQ', 'catalog:lkg', [
            '2026-08-21' => 3.25,
            '2026-08-28' => 4.25,
        ]);
        $at = CarbonImmutable::now('UTC')->addMinute();
        $partial = $this->publications->startCatalogRun('QQQ', ownerKey: 'catalog:partial', at: $at);
        $this->publications->freezeCatalog(
            (string) $partial['id'],
            ['2026-08-21', '2026-08-28', '2026-09-04'],
            'massive-options-snapshot',
            $at,
            true,
            '2026-09-04',
            at: $at
        );
        $this->publications->stageAndPublishExpiry(
            (string) $partial['id'],
            '2026-08-21',
            'massive-options-snapshot',
            $at,
            $at,
            $this->publicationRows(9.75),
            $at
        );

        $response = $this->getJson('/api/option-chain?symbol=QQQ&expiry=2026-08-28');

        $response->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('catalog_state', 'stale')
            ->assertJsonPath('catalog.is_last_known_good', true)
            ->assertJsonPath('catalog.candidate_state', 'partial')
            ->assertJsonPath('catalog.candidate_expected_count', 3)
            ->assertJsonPath('catalog.candidate_completed_count', 1)
            ->assertJsonPath('catalog.run_id', $complete['id'])
            ->assertJsonPath('run_id', $partial['id'])
            ->assertJsonPath('run_state', 'partial')
            ->assertJsonPath('expected_count', 3)
            ->assertJsonPath('completed_count', 1)
            ->assertJsonPath('resolved_expiry', '2026-08-28')
            ->assertJsonPath('chain.0.mid', 4.25)
            ->assertJsonCount(2, 'expirations');

        $this->assertSame(
            ['2026-08-21', '2026-08-28'],
            collect($response->json('expirations'))->pluck('value')->all()
        );

        $cappedAt = $at->addMinute();
        $capped = $this->publications->startCatalogRun('QQQ', ownerKey: 'catalog:capped', at: $cappedAt);
        $this->publications->markCapped(
            (string) $capped['id'],
            'Provider discovery reached its configured cursor ceiling.',
            $cappedAt
        );

        $this->getJson('/api/option-chain?symbol=QQQ&expiry=2026-08-28')
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('catalog_state', 'stale')
            ->assertJsonPath('catalog.run_id', $complete['id'])
            ->assertJsonPath('catalog.candidate_state', 'capped')
            ->assertJsonPath('run_id', $capped['id'])
            ->assertJsonPath('run_state', 'capped')
            ->assertJsonPath('chain.0.mid', 4.25)
            ->assertJsonCount(2, 'expirations');
    }

    public function test_selected_expiry_publication_advances_only_the_selected_chain_pointer(): void
    {
        $catalog = $this->publishCatalog('IWM', 'catalog:base', [
            '2026-08-21' => 2.25,
            '2026-08-28' => 3.25,
        ]);
        $at = CarbonImmutable::now('UTC')->addMinutes(2);
        $selected = $this->publications->startSelectedExpiryRun(
            'IWM',
            '2026-08-21',
            ownerKey: 'selected:newer',
            at: $at
        );
        $published = $this->publications->stageAndPublishExpiry(
            (string) $selected['id'],
            '2026-08-21',
            'massive-options-snapshot',
            $at,
            $at,
            $this->publicationRows(8.5),
            $at
        );

        $response = $this->getJson('/api/option-chain?symbol=IWM&expiry=2026-08-21');

        $response->assertOk()
            ->assertJsonPath('catalog_state', 'complete')
            ->assertJsonPath('catalog.run_id', $catalog['id'])
            ->assertJsonPath('run.scope', 'selected_expiry')
            ->assertJsonPath('run_state', 'complete')
            ->assertJsonPath('publication.id', $published['publication_id'])
            ->assertJsonPath('publication_generation', 2)
            ->assertJsonPath('chain.0.mid', 8.5)
            ->assertJsonCount(2, 'expirations');

        $this->getJson('/api/option-chain?symbol=IWM')
            ->assertOk()
            ->assertJsonPath('catalog.run_id', $catalog['id'])
            ->assertJsonPath('run_id', $catalog['id'])
            ->assertJsonPath('run.scope', 'catalog')
            ->assertJsonPath('expected_count', 2)
            ->assertJsonPath('completed_count', 2);
    }

    /** @param array<string, float> $expirations @return array<string, mixed> */
    private function publishCatalog(string $symbol, string $owner, array $expirations): array
    {
        $at = CarbonImmutable::now('UTC');
        $run = $this->publications->startCatalogRun($symbol, ownerKey: $owner, at: $at);
        $this->publications->freezeCatalog(
            (string) $run['id'],
            array_keys($expirations),
            'massive-options-snapshot',
            $at,
            true,
            array_key_last($expirations),
            at: $at
        );
        foreach ($expirations as $expiration => $mid) {
            $this->publications->stageAndPublishExpiry(
                (string) $run['id'],
                $expiration,
                'massive-options-snapshot',
                $at,
                $at,
                $this->publicationRows($mid),
                $at
            );
        }
        $this->publications->completeCatalog((string) $run['id'], $at);

        return $this->publications->run((string) $run['id']);
    }

    /** @return list<array<string, mixed>> */
    private function publicationRows(float $mid): array
    {
        return [[
            'ticker' => 'O:TEST260821C00100000',
            'type' => 'call',
            'strike' => 100,
            'bid' => $mid - 0.1,
            'ask' => $mid + 0.1,
            'mid' => $mid,
            'implied_volatility' => 0.25,
        ]];
    }

    private function insertLegacyContract(
        string $symbol,
        string $expiration,
        string $fetchedAt,
        ?float $snapshotUnderlying
    ): void {
        DB::table('option_snapshots')->insert([
            'symbol' => $symbol,
            'ticker' => sprintf('O:%s%sC00100000', $symbol, substr(str_replace('-', '', $expiration), 2)),
            'type' => 'call',
            'strike' => 100,
            'expiry' => $expiration,
            'bid' => 4.9,
            'ask' => 5.1,
            'mid' => 5,
            'implied_volatility' => 0.25,
            'underlying_price' => $snapshotUnderlying,
            'fetched_at' => $fetchedAt,
        ]);
    }

    private function createTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('option_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->string('ticker', 128)->nullable();
            $table->string('type', 8);
            $table->decimal('strike', 18, 6);
            $table->date('expiry');
            $table->decimal('bid', 18, 6)->nullable();
            $table->decimal('ask', 18, 6)->nullable();
            $table->decimal('mid', 18, 6)->nullable();
            $table->decimal('implied_volatility', 18, 10)->nullable();
            $table->decimal('underlying_price', 18, 6)->nullable();
            $table->dateTime('fetched_at', 6);
        });
        $schema->create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('expiration_date');
        });
        $schema->create('underlying_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32)->unique();
            $table->string('source')->nullable();
            $table->decimal('last_price', 18, 6);
            $table->decimal('prev_close', 18, 6)->nullable();
            $table->dateTime('asof', 6);
            $table->timestamps(6);
        });
        $schema->create('work_run_slots', function (Blueprint $table): void {
            $table->char('key', 64)->primary();
            $table->uuid('current_run_id')->nullable();
        });

        $migration = require database_path(
            'migrations/2026_08_16_000003_create_calculator_publication_tables.php'
        );
        $migration->up();
    }
}
