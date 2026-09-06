<?php

namespace Tests\Feature;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use App\Support\PolygonClient;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MySqlTestCase;

class Gex022IntegrationAuditTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
        config()->set([
            'quote_refresh.enabled' => true,
            'provider_backpressure.enabled' => true,
            'provider_backpressure.replay.enabled' => false,
            'services.massive.concurrency.enabled' => false,
            'queue_lanes.isolated' => false,
            'cache.default' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function deliveryModes(): array
    {
        return ['durable per-symbol intents' => [true], 'legacy queued batch' => [false]];
    }

    #[DataProvider('deliveryModes')]
    public function test_one_malformed_source_timestamp_does_not_discard_a_valid_batch_peer(bool $durable): void
    {
        // AAPL sorts first. Its invalid source time used to abort publication
        // of the valid SPY quote that the same provider response already held.
        $deliveries = $durable ? $this->deliveries(['AAPL', 'SPY']) : [];
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['AAPL', 'SPY'])->andReturn([
            'AAPL' => array_replace($this->quote(), ['asof' => 'not-a-valid-source-timestamp']),
            'SPY' => $this->quote(),
        ]);
        $this->app->instance(PolygonClient::class, $client);

        $thrown = null;
        try {
            $this->job(['AAPL', 'SPY'], $deliveries)->handle();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        if ($durable) {
            $this->assertNull($thrown);
            $this->assertSame('failed', WorkRun::findOrFail($deliveries['AAPL']['run_id'])->status);
            $this->assertSame('completed', WorkRun::findOrFail($deliveries['SPY']['run_id'])->status);
        } else {
            $this->assertInstanceOf(RuntimeException::class, $thrown);
        }
        $this->assertDatabaseMissing('underlying_quotes', ['symbol' => 'AAPL']);
        $this->assertDatabaseMissing('quote_refresh_states', ['symbol' => 'AAPL']);
        $this->assertDatabaseHas('underlying_quotes', [
            'symbol' => 'SPY', 'last_price' => 501.125, 'prev_close' => 499.5,
            'source' => 'massive-v2-snapshot', 'asof' => '2026-09-08 13:59:00',
        ]);
        $this->assertDatabaseHas('quote_refresh_states', [
            'symbol' => 'SPY', 'session_date' => '2026-09-08',
            'ingestion_completed_at' => '2026-09-08 14:00:00.000000',
        ]);
    }

    public function test_superseding_the_current_slot_fences_publication_even_if_the_old_delivery_token_is_unchanged(): void
    {
        $deliveries = $this->deliveries(['SPY']);
        $oldId = $deliveries['SPY']['run_id'];
        $replacementId = null;
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['SPY'])->andReturnUsing(function () use ($oldId, &$replacementId): array {
            $old = WorkRun::findOrFail($oldId);
            $replacement = $old->replicate();
            $replacement->generation = $old->generation + 1;
            $replacement->status = WorkRun::STATUS_PENDING;
            $replacement->delivery_token = null;
            $replacement->attempt = 0;
            $replacement->save();
            $replacementId = $replacement->id;
            WorkRunSlot::query()->whereKey($old->slot_key)->update([
                'current_run_id' => $replacement->id, 'generation' => $replacement->generation,
            ]);

            return ['SPY' => $this->quote()];
        });
        $this->app->instance(PolygonClient::class, $client);

        $this->job(['SPY'], $deliveries)->handle();

        $this->assertNotNull($replacementId);
        $this->assertSame($deliveries['SPY']['delivery_token'], WorkRun::findOrFail($oldId)->delivery_token);
        $this->assertSame('pending', WorkRun::findOrFail($replacementId)->status);
        $this->assertNull(WorkRun::findOrFail($replacementId)->delivery_token);
        $this->assertDatabaseMissing('underlying_quotes', ['symbol' => 'SPY']);
        $this->assertDatabaseMissing('quote_refresh_states', ['symbol' => 'SPY']);
        $this->assertSame(0, DB::table('quote_refresh_states')->count());
    }

    private function quote(): array
    {
        return [
            'last_price' => 501.125, 'prev_close' => 499.5,
            'asof' => '2026-09-08T13:59:00+00:00', 'source' => 'massive-v2-snapshot',
        ];
    }

    private function deliveries(array $symbols): array
    {
        $runs = app(WorkRunCoordinator::class);
        $result = [];
        foreach ($symbols as $symbol) {
            $run = $runs->claim('quote_refresh', $symbol,
                ['session_date' => '2026-09-08', 'phase' => 'regular'],
                'quotes', applyAdmissionLimits: false)['run'];
            $reservation = $runs->reserveDispatch($run->id);
            $result[$symbol] = ['run_id' => $run->id, 'delivery_token' => $reservation['delivery_token']];
        }

        return $result;
    }

    private function job(array $symbols, array $deliveries): FetchUnderlyingQuotesJob
    {
        return new FetchUnderlyingQuotesJob($symbols, scheduled: true, sessionDate: '2026-09-08',
            workRunDeliveries: $deliveries, phase: 'regular');
    }
}
