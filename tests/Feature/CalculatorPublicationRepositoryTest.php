<?php

namespace Tests\Feature;

use App\Support\CalculatorPublicationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CalculatorPublicationRepositoryTest extends TestCase
{
    private CalculatorPublicationRepository $publications;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('calculator_publication_runs')) {
            $migration = require database_path(
                'migrations/2026_08_16_000003_create_calculator_publication_tables.php'
            );
            $migration->up();
        }
        if (! Schema::hasTable('option_snapshots')) {
            Schema::create('option_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->string('ticker');
                $table->string('type');
                $table->decimal('strike', 12, 2);
                $table->date('expiry');
                $table->decimal('bid', 10, 2);
                $table->decimal('ask', 10, 2);
                $table->decimal('mid', 10, 2);
                $table->decimal('implied_volatility', 10, 6)->nullable();
                $table->decimal('underlying_price', 12, 2)->nullable();
                $table->timestamp('fetched_at');
                $table->index(['symbol', 'expiry']);
            });
        }
        if (! Schema::hasTable('option_expirations')) {
            Schema::create('option_expirations', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 32);
                $table->date('expiration_date');
                $table->timestamps();
                $table->unique(['symbol', 'expiration_date']);
            });
        }

        foreach ([
            'calculator_expiry_heads',
            'calculator_catalog_heads',
            'calculator_run_expirations',
            'calculator_expiry_publication_rows',
            'calculator_expiry_publications',
            'calculator_publication_runs',
            'calculator_symbol_generations',
        ] as $table) {
            DB::table($table)->delete();
        }
        DB::table('option_snapshots')->delete();
        DB::table('option_expirations')->delete();

        $this->publications = new CalculatorPublicationRepository;
    }

    public function test_generations_are_monotonic_per_symbol_and_independent_from_optional_work_runs(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $first = $this->publications->startCatalogRun(' spy ', ownerKey: 'scheduler:first', at: $at);
        $second = $this->publications->startSelectedExpiryRun(
            'SPY',
            '2026-08-21',
            workRunId: '11111111-1111-4111-8111-111111111111',
            at: $at->addSecond()
        );
        $retry = $this->publications->startSelectedExpiryRun(
            'SPY',
            '2026-08-21',
            workRunId: '11111111-1111-4111-8111-111111111111',
            at: $at->addSeconds(2)
        );
        $other = $this->publications->startCatalogRun(
            'QQQ',
            ownerKey: 'scheduler:first',
            at: $at->addSeconds(3)
        );

        $this->assertSame('SPY', $first['symbol']);
        $this->assertSame(1, (int) $first['generation']);
        $this->assertNull($first['work_run_id']);
        $this->assertSame(2, (int) $second['generation']);
        $this->assertSame($second['id'], $retry['id']);
        $this->assertSame(2, (int) $retry['generation']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $second['work_run_id']);
        $this->assertSame(1, (int) $other['generation']);
        $this->assertSame(
            $second['id'],
            $this->publications->runForWorkRun(
                '11111111-1111-4111-8111-111111111111'
            )['run']['id']
        );
        $this->assertSame($second['id'], $this->publications->latestRunForSymbol('SPY')['run']['id']);

        try {
            $this->publications->startCatalogRun(
                'QQQ',
                workRunId: '11111111-1111-4111-8111-111111111111',
                at: $at->addSeconds(4)
            );
            $this->fail('One durable work run must not own multiple publication scopes.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('multiple calculator publication scopes', $exception->getMessage());
        }
        $this->assertSame(3, DB::table('calculator_publication_runs')->count());
    }

    public function test_catalog_membership_is_absent_until_terminal_discovery_is_frozen_and_then_immutable(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->publications->startCatalogRun('SPY', ownerKey: 'test:freeze', at: $at);

        $this->assertSame(0, DB::table('calculator_run_expirations')->count());
        $this->expectExceptionForStageBeforeFreeze((string) $run['id'], $at);
        try {
            $this->publications->freezeCatalog(
                (string) $run['id'],
                ['2026-08-21'],
                'massive-contracts',
                $at,
                false,
                '2026-11-20',
                at: $at
            );
            $this->fail('A non-terminal discovery must not freeze expected expirations.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('terminal discovery cursor', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('calculator_run_expirations')->count());

        $frozen = $this->publications->freezeCatalog(
            (string) $run['id'],
            ['2026-09-18', '2026-08-21', '2026-08-21'],
            'massive-contracts',
            $at,
            true,
            '2026-11-20',
            at: $at
        );
        $this->assertSame(2, (int) $frozen['expected_count']);
        $this->assertSame(2, DB::table('calculator_run_expirations')->count());
        $this->assertTrue((bool) $frozen['discovery_terminal']);

        $same = $this->publications->freezeCatalog(
            (string) $run['id'],
            ['2026-08-21', '2026-09-18'],
            'massive-contracts',
            $at,
            true,
            '2026-11-20',
            at: $at->addMinute()
        );
        $this->assertSame($frozen['expected_expirations_hash'], $same['expected_expirations_hash']);

        $this->expectException(LogicException::class);
        $this->publications->freezeCatalog(
            (string) $run['id'],
            ['2026-08-21'],
            'massive-contracts',
            $at,
            true,
            '2026-11-20',
            at: $at->addMinutes(2)
        );
    }

    public function test_identical_duplicate_contracts_collapse_to_one_deterministic_row(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->catalogRun('SPY', ['2026-08-21'], $at);
        $row = $this->row(600.0);

        $published = $this->publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-08-21',
            'massive-snapshot',
            $at,
            $at,
            [$row, $row],
            $at
        );
        $stored = DB::table('calculator_expiry_publications')
            ->where('id', $published['publication_id'])
            ->first(['row_count', 'content_hash']);

        $this->assertSame(1, (int) $stored->row_count);
        $this->assertSame(1, DB::table('calculator_expiry_publication_rows')->count());

        $nextAt = $at->addMinute();
        $nextRun = $this->catalogRun('SPY', ['2026-08-21'], $nextAt);
        $next = $this->publications->stageAndPublishExpiry(
            (string) $nextRun['id'],
            '2026-08-21',
            'massive-snapshot',
            $nextAt,
            $nextAt,
            [$row],
            $nextAt
        );
        $nextHash = DB::table('calculator_expiry_publications')
            ->where('id', $next['publication_id'])
            ->value('content_hash');

        $this->assertSame($stored->content_hash, $nextHash);
    }

    public function test_conflicting_duplicate_contract_identity_fails_without_replacing_last_known_good(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $lastKnownGood = $this->completeOneExpiryCatalog('SPY', $at, 600.0);
        $candidateAt = $at->addMinutes(2);
        $candidate = $this->catalogRun('SPY', ['2026-08-21'], $candidateAt);
        $first = $this->row(600.0);
        $conflict = $first;
        $conflict['mid'] = 9.99;

        try {
            $this->publications->stageAndPublishExpiry(
                (string) $candidate['id'],
                '2026-08-21',
                'massive-snapshot',
                $candidateAt,
                $candidateAt,
                [$first, $conflict],
                $candidateAt
            );
            $this->fail('Conflicting quotes for one contract identity must fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Conflicting rows', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(1, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame(
            $lastKnownGood['publication_id'],
            $this->publications->expiryHead('SPY', '2026-08-21')['current_publication_id']
        );
        $this->assertSame(
            'pending',
            DB::table('calculator_run_expirations')
                ->where('run_id', $candidate['id'])
                ->value('readiness')
        );
    }

    public function test_failure_between_row_chunks_rolls_back_rows_readiness_and_pointer(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->catalogRun('SPY', ['2026-08-21'], $at);
        $rows = [];
        for ($strike = 1; $strike <= 101; $strike++) {
            $rows[] = $this->row((float) $strike);
        }

        $rowInsertCount = 0;
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$rowInsertCount, &$armed): void {
            if (
                ! $armed
                || ! str_starts_with(strtolower(ltrim($query)), 'insert')
                || ! str_contains(strtolower($query), 'calculator_expiry_publication_rows')
            ) {
                return;
            }

            $rowInsertCount++;
            if ($rowInsertCount === 2) {
                $armed = false;

                throw new RuntimeException('Injected failure before the second publication row chunk.');
            }
        });

        try {
            $this->publications->stageAndPublishExpiry(
                (string) $run['id'],
                '2026-08-21',
                'massive-snapshot',
                $at,
                $at,
                $rows,
                $at
            );
            $this->fail('The injected second-chunk failure should escape the publication transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('second publication row chunk', $exception->getMessage());
        }

        $this->assertSame(2, $rowInsertCount);
        $this->assertSame(0, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(0, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame(0, DB::table('calculator_expiry_heads')->count());
        $this->assertSame(
            'pending',
            DB::table('calculator_run_expirations')->where('run_id', $run['id'])->value('readiness')
        );
    }

    public function test_adjusted_contracts_with_the_same_type_and_strike_keep_distinct_identity(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->catalogRun('AAPL', ['2026-08-21'], $at);
        $standard = $this->row(150.0);
        $standard['ticker'] = 'O:AAPL260821C00150000';
        $adjusted = $this->row(150.0);
        $adjusted['ticker'] = 'O:AAPL1260821C00150000';
        $adjusted['mid'] = 3.1;

        $published = $this->publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-08-21',
            'massive-snapshot',
            $at,
            $at,
            [$standard, $adjusted],
            $at
        );

        $rows = collect($this->publications->publicationRows($published['publication_id']));
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['O:AAPL1260821C00150000', 'O:AAPL260821C00150000'],
            $rows->pluck('ticker')->sort()->values()->all()
        );
        $this->assertCount(2, $rows->pluck('contract_key')->unique());
        $this->assertSame(2, (int) DB::table('calculator_expiry_publications')
            ->where('id', $published['publication_id'])
            ->value('row_count'));
    }

    public function test_selected_expiry_publishes_rows_and_iv_without_advancing_the_catalog(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->publications->startSelectedExpiryRun(
            'SPY',
            '2026-08-21',
            ownerKey: 'test:selected',
            at: $at
        );
        $result = $this->publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-08-21',
            'massive-snapshot',
            $at,
            $at,
            [$this->row(600.0, 0.245)],
            $at
        );

        $this->assertTrue($result['head_advanced']);
        $this->assertSame('complete', $result['run']['status']);
        $this->assertNull($this->publications->catalogHead('SPY'));

        $published = $this->publications->publishedExpiry('SPY', '2026-08-21');
        $this->assertNotNull($published);
        $this->assertSame($result['publication_id'], $published['publication_id']);
        $this->assertSame(1, $published['row_count']);
        $this->assertSame(0.245, (float) $published['rows'][0]['implied_volatility']);
    }

    public function test_catalog_advances_only_after_every_frozen_expiration_is_ready(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->catalogRun('SPY', ['2026-08-21', '2026-09-18'], $at);

        $this->stage((string) $run['id'], '2026-08-21', $at, 600.0);
        $incomplete = $this->publications->completeCatalog((string) $run['id'], $at->addMinute());
        $this->assertFalse($incomplete['advanced']);
        $this->assertSame('expirations_not_ready', $incomplete['reason']);
        $this->assertNull($this->publications->catalogHead('SPY'));

        $this->stage((string) $run['id'], '2026-09-18', $at->addMinutes(2), 610.0);
        $complete = $this->publications->completeCatalog((string) $run['id'], $at->addMinutes(3));
        $this->assertTrue($complete['advanced']);
        $this->assertSame('complete', $complete['run']['status']);

        $catalog = $this->publications->authoritativeCatalog('SPY');
        $this->assertNotNull($catalog);
        $this->assertSame((string) $run['id'], $catalog['run_id']);
        $this->assertSame(2, $catalog['expected_count']);
        $this->assertCount(2, $catalog['expirations']);
        $this->assertNotNull($catalog['expirations'][0]['publication_id']);
        $this->assertNotNull($catalog['expirations'][1]['publication_id']);
    }

    public function test_terminal_empty_discovery_is_a_complete_empty_catalog_not_a_failed_fetch(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $run = $this->catalogRun('EMPTY', [], $at);

        $completion = $this->publications->completeCatalog((string) $run['id'], $at->addMinute());
        $this->assertTrue($completion['advanced']);
        $this->assertSame('complete', $completion['run']['status']);
        $this->assertSame(0, (int) $completion['run']['expected_count']);
        $this->assertSame(0, (int) $completion['run']['failed_count']);

        $catalog = $this->publications->authoritativeCatalog('EMPTY');
        $this->assertNotNull($catalog);
        $this->assertSame('complete', $catalog['state']);
        $this->assertSame([], $catalog['expirations']);
    }

    public function test_empty_candidate_cannot_replace_a_nonempty_head_that_appears_after_discovery_freezes(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $baseline = $this->publications->startCatalogRun(
            'SPY',
            ownerKey: 'test:concurrent-nonempty-baseline',
            at: $at
        );
        $emptyCandidate = $this->catalogRun('SPY', [], $at->addMinute());

        $baseline = $this->publications->freezeCatalog(
            (string) $baseline['id'],
            ['2026-08-21'],
            'massive-contracts',
            $at,
            true,
            '2026-08-21',
            at: $at->addMinutes(2)
        );
        $baselinePublication = $this->stage(
            (string) $baseline['id'],
            '2026-08-21',
            $at,
            600.0
        );
        $this->assertTrue($this->publications->completeCatalog(
            (string) $baseline['id'],
            $at->addMinutes(3)
        )['advanced']);

        $completion = $this->publications->completeCatalog(
            (string) $emptyCandidate['id'],
            $at->addMinutes(4)
        );

        $this->assertFalse($completion['advanced']);
        $this->assertSame('provider_empty_after_nonempty', $completion['reason']);
        $this->assertSame('failed', $completion['run']['status']);
        $this->assertSame('provider_empty_after_nonempty', $completion['run']['failure_code']);
        $this->assertNotNull($completion['run']['completed_at']);
        $this->assertSame(
            $baseline['id'],
            $this->publications->catalogHead('SPY')['current_run_id']
        );
        $this->assertSame(
            $baselinePublication['publication_id'],
            $this->publications->expiryHead('SPY', '2026-08-21')['current_publication_id']
        );
        $this->assertSame(1, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(1, DB::table('calculator_expiry_publication_rows')->count());
    }

    public function test_empty_candidate_cannot_hide_current_rollout_evidence_without_a_catalog_head(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        DB::table('option_snapshots')->insert([
            'symbol' => 'LEGACY',
            'ticker' => 'LEGACY-ONLY',
            'type' => 'call',
            'strike' => 100,
            'expiry' => '2026-08-21',
            'bid' => 1,
            'ask' => 1.2,
            'mid' => 1.1,
            'implied_volatility' => 0.2,
            'underlying_price' => 100,
            'fetched_at' => $at,
        ]);
        DB::table('option_expirations')->insert([
            'symbol' => 'EOD',
            'expiration_date' => '2026-08-21',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $selected = $this->publications->startSelectedExpiryRun(
            'SELECTED',
            '2026-08-21',
            ownerKey: 'test:selected-evidence',
            at: $at
        );
        $this->publications->stageAndPublishExpiry(
            (string) $selected['id'],
            '2026-08-21',
            'massive-snapshot',
            $at,
            $at,
            [$this->row(100.0)],
            $at
        );

        foreach (['LEGACY', 'EOD', 'SELECTED'] as $offset => $symbol) {
            $candidate = $this->catalogRun($symbol, [], $at->addMinutes($offset + 1));
            $completion = $this->publications->completeCatalog(
                (string) $candidate['id'],
                $at->addMinutes($offset + 5)
            );

            $this->assertFalse($completion['advanced']);
            $this->assertSame('provider_empty_after_nonempty', $completion['reason']);
            $this->assertSame('failed', $completion['run']['status']);
            $this->assertSame(
                'provider_empty_after_nonempty',
                $completion['run']['failure_code']
            );
            $this->assertNull($this->publications->catalogHead($symbol));
        }
    }

    public function test_capped_and_failed_runs_cannot_claim_complete_or_move_the_catalog(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $capped = $this->publications->startCatalogRun('SPY', ownerKey: 'test:capped', at: $at);
        $this->publications->markCapped((string) $capped['id'], at: $at->addMinute());
        $cappedCompletion = $this->publications->completeCatalog(
            (string) $capped['id'],
            $at->addMinutes(2)
        );
        $this->assertFalse($cappedCompletion['advanced']);
        $this->assertSame('capped', $cappedCompletion['reason']);

        $failed = $this->catalogRun('QQQ', ['2026-08-21'], $at);
        $this->publications->markExpiryFailed(
            (string) $failed['id'],
            '2026-08-21',
            'provider_error',
            'Provider request failed.',
            $at->addMinute()
        );
        $failedCompletion = $this->publications->completeCatalog(
            (string) $failed['id'],
            $at->addMinutes(2)
        );
        $this->assertFalse($failedCompletion['advanced']);
        $this->assertSame('expirations_not_ready', $failedCompletion['reason']);
        $this->assertSame('failed', $failedCompletion['run']['status']);
        $this->assertSame('expiration_failed', $failedCompletion['run']['failure_code']);
        $this->assertNotNull($failedCompletion['run']['completed_at']);
        $duplicateTerminal = $this->publications->markCapped(
            (string) $failed['id'],
            at: $at->addMinutes(3)
        );
        $this->assertSame('failed', $duplicateTerminal['status']);
        $this->assertSame('expiration_failed', $duplicateTerminal['failure_code']);
        $this->assertNull($this->publications->catalogHead('SPY'));
        $this->assertNull($this->publications->catalogHead('QQQ'));
    }

    public function test_first_failed_or_capped_terminal_result_wins_duplicate_delivery_races(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $failedRun = $this->publications->startCatalogRun(
            'FAIL',
            ownerKey: 'test:first-failed',
            at: $at
        );
        $failed = $this->publications->markRunFailed(
            (string) $failedRun['id'],
            'provider_http_error',
            'The first terminal result failed.',
            $at->addMinute()
        );
        $failedAfterCap = $this->publications->markCapped(
            (string) $failedRun['id'],
            'A duplicate delivery later reached its page cap.',
            $at->addMinutes(2)
        );
        $failedAgain = $this->publications->markRunFailed(
            (string) $failedRun['id'],
            'different_failure',
            'A later failure must not rewrite the first terminal result.',
            $at->addMinutes(3)
        );

        foreach ([$failed, $failedAfterCap, $failedAgain] as $result) {
            $this->assertSame('failed', $result['status']);
            $this->assertSame('provider_http_error', $result['failure_code']);
            $this->assertSame('The first terminal result failed.', $result['failure_reason']);
            $this->assertFalse((bool) $result['discovery_capped']);
            $this->assertTrue(CarbonImmutable::parse((string) $result['completed_at'])
                ->equalTo($at->addMinute()));
        }
        $this->assertSame(
            'failed',
            $this->publications->completeCatalog((string) $failedRun['id'], $at->addMinutes(4))['reason']
        );

        $cappedRun = $this->publications->startCatalogRun(
            'CAP',
            ownerKey: 'test:first-capped',
            at: $at
        );
        $capped = $this->publications->markCapped(
            (string) $cappedRun['id'],
            'The first terminal result reached its page cap.',
            $at->addMinute()
        );
        $cappedAfterFailure = $this->publications->markRunFailed(
            (string) $cappedRun['id'],
            'terminal_exception',
            'A duplicate delivery failed later.',
            $at->addMinutes(2)
        );
        $cappedAgain = $this->publications->markCapped(
            (string) $cappedRun['id'],
            'A later cap must not rewrite the first terminal result.',
            $at->addMinutes(3)
        );

        foreach ([$capped, $cappedAfterFailure, $cappedAgain] as $result) {
            $this->assertSame('capped', $result['status']);
            $this->assertSame('discovery_capped', $result['failure_code']);
            $this->assertSame(
                'The first terminal result reached its page cap.',
                $result['failure_reason']
            );
            $this->assertTrue((bool) $result['discovery_capped']);
            $this->assertTrue(CarbonImmutable::parse((string) $result['completed_at'])
                ->equalTo($at->addMinute()));
        }
    }

    public function test_older_generation_and_older_source_time_cannot_replace_a_newer_expiry_or_catalog(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $old = $this->catalogRun('SPY', ['2026-08-21'], $at, $at);
        $new = $this->catalogRun(
            'SPY',
            ['2026-08-21'],
            $at->addMinute(),
            $at->addMinutes(2)
        );

        $newPublication = $this->stage(
            (string) $new['id'],
            '2026-08-21',
            $at->addMinutes(2),
            601.0
        );
        $this->assertTrue($newPublication['head_advanced']);
        $this->assertTrue(
            $this->publications->completeCatalog((string) $new['id'], $at->addMinutes(3))['advanced']
        );

        $oldPublication = $this->stage(
            (string) $old['id'],
            '2026-08-21',
            $at->addMinutes(4),
            599.0
        );
        $this->assertFalse($oldPublication['head_advanced']);
        $oldCompletion = $this->publications->completeCatalog((string) $old['id'], $at->addMinutes(5));
        $this->assertFalse($oldCompletion['advanced']);
        $this->assertSame('older_than_catalog_head', $oldCompletion['reason']);

        $newerGenerationOlderSource = $this->publications->startSelectedExpiryRun(
            'SPY',
            '2026-08-21',
            ownerKey: 'test:older-source',
            at: $at->addMinutes(6)
        );
        $olderSourcePublication = $this->stage(
            (string) $newerGenerationOlderSource['id'],
            '2026-08-21',
            $at->subMinute(),
            602.0
        );
        $this->assertFalse($olderSourcePublication['head_advanced']);

        $head = $this->publications->expiryHead('SPY', '2026-08-21');
        $this->assertSame($newPublication['publication_id'], $head['current_publication_id']);
        $this->assertSame(3, DB::table('calculator_expiry_publications')->count());
    }

    public function test_catalog_and_expiry_rollback_swap_only_pointers_and_preserve_high_water_marks(): void
    {
        $at = $this->time('2026-08-16 14:00:00');
        $first = $this->completeOneExpiryCatalog('SPY', $at, 600.0);
        $second = $this->completeOneExpiryCatalog('SPY', $at->addMinutes(2), 605.0);

        $catalog = $this->publications->rollbackCatalog('SPY', (string) $second['run']['id'], $at->addMinutes(4));
        $expiry = $this->publications->rollbackExpiry(
            'SPY',
            '2026-08-21',
            $second['publication_id'],
            $at->addMinutes(4)
        );

        $this->assertSame((string) $first['run']['id'], $catalog['current_run_id']);
        $this->assertSame((string) $second['run']['id'], $catalog['previous_run_id']);
        $this->assertSame(2, (int) $catalog['max_generation']);
        $this->assertSame($first['publication_id'], $expiry['current_publication_id']);
        $this->assertSame($second['publication_id'], $expiry['previous_publication_id']);
        $this->assertSame(2, (int) $expiry['max_generation']);
        $this->assertSame(2, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(2, DB::table('calculator_expiry_publication_rows')->count());

        $retry = $this->publications->stageAndPublishExpiry(
            (string) $second['run']['id'],
            '2026-08-21',
            'massive-snapshot',
            $at->addMinutes(2),
            $at->addMinutes(2),
            [$this->row(605.0)],
            $at->addMinutes(5)
        );
        $this->assertTrue($retry['idempotent']);
        $this->assertFalse($retry['head_advanced']);
        $this->assertSame($first['publication_id'], $this->publications->expiryHead(
            'SPY',
            '2026-08-21'
        )['current_publication_id']);
    }

    private function expectExceptionForStageBeforeFreeze(string $runId, CarbonImmutable $at): void
    {
        try {
            $this->stage($runId, '2026-08-21', $at, 600.0);
            $this->fail('A catalog run must not stage before terminal discovery is frozen.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('freeze terminal discovery', $exception->getMessage());
        }
    }

    /** @param list<string> $expirations @return array<string,mixed> */
    private function catalogRun(
        string $symbol,
        array $expirations,
        CarbonImmutable $at,
        ?CarbonImmutable $catalogSourceAsOf = null
    ): array {
        $run = $this->publications->startCatalogRun(
            $symbol,
            ownerKey: 'test:catalog:'.$at->format('U.u'),
            at: $at
        );

        return $this->publications->freezeCatalog(
            (string) $run['id'],
            $expirations,
            'massive-contracts',
            $catalogSourceAsOf ?? $at,
            true,
            '2026-11-20',
            at: $at
        );
    }

    /** @return array<string,mixed> */
    private function stage(
        string $runId,
        string $expiration,
        CarbonImmutable $sourceAsOf,
        float $strike
    ): array {
        return $this->publications->stageAndPublishExpiry(
            $runId,
            $expiration,
            'massive-snapshot',
            $sourceAsOf,
            $sourceAsOf,
            [$this->row($strike)],
            $sourceAsOf
        );
    }

    /** @return array{run:array<string,mixed>,publication_id:string} */
    private function completeOneExpiryCatalog(
        string $symbol,
        CarbonImmutable $at,
        float $strike
    ): array {
        $run = $this->catalogRun($symbol, ['2026-08-21'], $at);
        $publication = $this->stage((string) $run['id'], '2026-08-21', $at, $strike);
        $completion = $this->publications->completeCatalog((string) $run['id'], $at->addMinute());
        $this->assertTrue($completion['advanced']);

        return ['run' => $completion['run'], 'publication_id' => $publication['publication_id']];
    }

    /** @return array<string,mixed> */
    private function row(float $strike, ?float $impliedVolatility = null): array
    {
        return [
            'ticker' => sprintf('O:TEST%08dC%08d', 20260821, (int) ($strike * 1000)),
            'type' => 'call',
            'strike' => $strike,
            'bid' => 1.0,
            'ask' => 1.2,
            'mid' => 1.1,
            'implied_volatility' => $impliedVolatility,
        ];
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }
}
