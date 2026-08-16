<?php

namespace Tests\Feature;

use App\Jobs\FetchCalculatorChainJob;
use App\Support\CalculatorRefreshState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FetchCalculatorChainJobStateTest extends TestCase
{
    use DatabaseTransactions;

    private CarbonImmutable $now;

    private CalculatorRefreshState $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-12 10:00:00', 'America/New_York');
        Carbon::setTestNow($this->now);
        Cache::flush();

        if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('option_snapshots')) {
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
                $table->decimal('underlying_price', 12, 2);
                $table->timestamp('fetched_at');
                $table->unique(
                    ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
                    'option_snapshots_contract_fetch_unique'
                );
            });
        }

        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('services.massive.key', 'massive-test');
        config()->set('services.massive.base', 'https://api.massive.test');
        config()->set('services.massive.mode', 'header');

        $this->states = app(CalculatorRefreshState::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scheduled_job_moves_from_pending_to_started_to_completed_after_rows_commit(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-success');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                $this->assertSame(
                    CalculatorRefreshState::STATUS_STARTED,
                    $this->states->get('AAPL')['status'] ?? null
                );

                return Http::response([
                    'results' => [[
                        'ticker' => 'O:AAPL260821C00150000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 2.0, 'ask' => 2.2],
                    ]],
                ]);
            }

            return Http::response([
                'results' => [[
                    'last_quote' => ['midpoint' => 150.0],
                ]],
            ]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $state['status'] ?? null);
        $this->assertSame($generation, $state['last_success_generation'] ?? null);
        $this->assertNotNull($state['completed_at'] ?? null);
        $this->assertSame(1, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_semantic_failure_is_failed_and_never_advances_success_freshness(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-empty');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $state['status'] ?? null);
        $this->assertSame('no_contracts', $state['failure_reason'] ?? null);
        $this->assertNull($state['completed_at'] ?? null);
        $this->assertNull($state['last_success_at'] ?? null);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_terminal_exception_marks_only_the_current_claim_failed(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-terminal');
        $job = new FetchCalculatorChainJob('AAPL', null, $generation, $token);

        $job->failed(new RuntimeException('provider failed'));

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $state['status'] ?? null);
        $this->assertSame('terminal_exception:RuntimeException', $state['failure_reason'] ?? null);
    }

    public function test_scheduler_identity_uses_generation_but_not_random_claim_token(): void
    {
        $first = new FetchCalculatorChainJob('AAPL', null, 'generation-a', 'token-a');
        $sameGeneration = new FetchCalculatorChainJob('AAPL', null, 'generation-a', 'token-b');
        $nextGeneration = new FetchCalculatorChainJob('AAPL', null, 'generation-b', 'token-c');

        $this->assertSame($first->idempotencyKey(), $sameGeneration->idempotencyKey());
        $this->assertNotSame($first->idempotencyKey(), $nextGeneration->idempotencyKey());
    }

    public function test_completed_generation_cannot_regress_to_started_after_redelivery(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-completed');
        $this->assertTrue($this->states->markStarted('AAPL', $generation, $token, 1, $this->now));
        $this->assertTrue($this->states->markCompleted('AAPL', $generation, $token, $this->now));

        Cache::put('calculator:refresh-active:v1:catalog:AAPL', [
            'generation' => $generation,
            'claim_token' => $token,
        ], $this->now->addHour());

        $this->assertFalse($this->states->markStarted(
            'AAPL',
            $generation,
            $token,
            2,
            $this->now->addMinute()
        ));
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
    }

    public function test_claim_rechecks_freshness_atomically_after_scheduler_bulk_read(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-race-winner');
        $this->states->markStarted('AAPL', $generation, $token, 1, $this->now);
        $this->states->markCompleted('AAPL', $generation, $token, $this->now);

        $losingClaim = $this->states->claim(
            'AAPL',
            'generation-race-loser',
            'calculator',
            $this->now,
            $this->now->subMinutes(10)
        );

        $this->assertNull($losingClaim);
        $this->assertSame('generation-race-winner', $this->states->get('AAPL')['generation']);
    }

    public function test_repeated_failures_back_off_exponentially_and_success_resets_the_count(): void
    {
        [$firstGeneration, $firstToken] = $this->claim('AAPL', 'generation-failure-one');
        $this->states->markStarted('AAPL', $firstGeneration, $firstToken, 1, $this->now);
        $this->states->markFailed('AAPL', $firstGeneration, $firstToken, 'no_contracts', $this->now);

        $secondAt = $this->now->addMinutes(5);
        $secondToken = $this->states->claim('AAPL', 'generation-failure-two', 'calculator', $secondAt);
        $this->assertNotNull($secondToken);
        $this->states->markStarted('AAPL', 'generation-failure-two', $secondToken, 1, $secondAt);
        $this->states->markFailed('AAPL', 'generation-failure-two', $secondToken, 'no_contracts', $secondAt);

        $failed = $this->states->get('AAPL');
        $this->assertSame(2, $failed['failure_count'] ?? null);
        $this->assertSame(
            $secondAt->addMinutes(10)->timestamp,
            CarbonImmutable::parse($failed['next_eligible_at'])->timestamp
        );

        $recoveryAt = $secondAt->addMinutes(10);
        $recoveryToken = $this->states->claim('AAPL', 'generation-recovered', 'calculator', $recoveryAt);
        $this->assertNotNull($recoveryToken);
        $this->states->markStarted('AAPL', 'generation-recovered', $recoveryToken, 1, $recoveryAt);
        $this->states->markCompleted('AAPL', 'generation-recovered', $recoveryToken, $recoveryAt);

        $this->assertSame(0, $this->states->get('AAPL')['failure_count'] ?? null);
    }

    /** @return array{string, string} */
    private function claim(string $symbol, string $generation): array
    {
        $token = $this->states->claim($symbol, $generation, 'calculator', $this->now);
        $this->assertNotNull($token);

        return [$generation, $token];
    }
}
