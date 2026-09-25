<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\User;
use App\Models\WallObservation;
use App\Support\WallIntelligence\WallFoundationAudit;
use App\Support\WallIntelligence\WallObservationStore;
use App\Support\WallIntelligence\WallSnapshotContract;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class WallFoundationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.wall-foundation-test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('wall-foundation-test');
        DB::setDefaultConnection('wall-foundation-test');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        config()->set('ui_review.now', null);
        $this->app['env'] = 'local';
        Http::preventStrayRequests();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('wall-foundation-test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    private function source(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/wall-foundation.json')), true);
    }

    private function snapshot(): array
    {
        return (new WallSnapshotContract)->fromLegacyGex($this->source(), [
            'symbol' => 'SPY', 'timeframe' => '14d', 'view' => 'latest_eod', 'dataset' => 'local_review',
            'captured_at' => '2026-08-21T12:00:00+00:00', 'http_status' => 200,
        ]);
    }

    private function signIn(bool $entitled = true): void
    {
        $user = (new User)->forceFill(['id' => 789, 'name' => 'Review', 'email' => 'review@example.test',
            'trial_ends_at' => $entitled ? now()->addDays(2) : null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
    }

    public function test_storage_deduplicates_repeated_captures_and_appends_corrected_evidence(): void
    {
        $store = new WallObservationStore;
        $s = $this->snapshot();
        $first = $store->record($s);
        $s['provenance']['captured_at'] = '2026-08-21T12:05:00+00:00';
        $s['provenance']['generated_at'] = '2026-08-21T12:05:00+00:00';
        $this->assertSame($first->id, $store->record($s)->id);
        $s['raw']['gex_response']['correction_note'] = 'provider revision';
        $second = $store->record($s);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, WallObservation::count());
        $this->assertNull($first->observed_at);
        $this->assertFalse($first->historical_outcome_eligible);
        $this->assertSame($this->source(), json_decode($first->payload_json, true)['raw']['gex_response']);
    }

    public function test_model_rejects_mutation_of_recorded_evidence(): void
    {
        $record = (new WallObservationStore)->record($this->snapshot());
        $this->expectException(DomainException::class);
        $record->update(['quality_state' => 'complete']);
    }

    public function test_model_rejects_deletion_of_recorded_evidence(): void
    {
        $record = (new WallObservationStore)->record($this->snapshot());
        $this->expectException(DomainException::class);
        $record->delete();
    }

    public function test_audit_storage_rejects_outcome_eligible_or_unversioned_payloads(): void
    {
        $s = $this->snapshot();
        $s['quality']['historical_outcome_eligible'] = true;
        $this->expectException(DomainException::class);
        (new WallObservationStore)->record($s);
    }

    public function test_read_only_audit_is_bounded_and_does_not_dispatch_or_record(): void
    {
        $controller = Mockery::mock(GexController::class);
        $controller->shouldReceive('getGexLevels')->times(3)->andReturnUsing(function ($request) {
            $input = $this->source();
            $input['symbol'] = $request->query('symbol');

            return response()->json($input);
        });
        $this->app->instance(GexController::class, $controller);
        $this->signIn();
        $this->getJson('/api/wall-foundation/audit?dataset=local_review&view=latest_eod')
            ->assertOk()->assertJsonCount(3, 'snapshots')
            ->assertJsonPath('provider_access.new_provider_requests', 0)
            ->assertJsonPath('storage.collector_enabled', false);
        $this->assertSame(0, WallObservation::count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_review_routes_require_authentication_and_entitlement(): void
    {
        $this->getJson('/api/wall-foundation/audit?dataset=local_review&view=latest_eod')->assertUnauthorized();
        $this->signIn(false);
        $this->getJson('/api/wall-foundation/audit?dataset=local_review&view=latest_eod')->assertForbidden();
    }

    public function test_review_is_not_available_in_production_even_for_an_entitled_user(): void
    {
        $this->signIn();
        $this->app['env'] = 'production';
        $this->getJson('/api/wall-foundation/audit?dataset=local_review&view=latest_eod')->assertNotFound();
        $this->get('/wall-foundation')->assertNotFound();
        $this->artisan('walls:audit-foundation', ['--record' => true])->assertFailed();
        $this->assertSame(0, WallObservation::count());
    }

    public function test_request_parameters_are_validated_before_source_reads(): void
    {
        $this->signIn();
        $audit = Mockery::mock(WallFoundationAudit::class);
        $audit->shouldNotReceive('report');
        $this->app->instance(WallFoundationAudit::class, $audit);
        $this->getJson('/api/wall-foundation/audit?dataset=anything&view=unknown')->assertUnprocessable();
    }

    public function test_missing_production_capture_is_a_recoverable_error(): void
    {
        $this->signIn();
        config()->set('wall_foundation.production_capture_path', base_path('storage/no-such-wall-capture.json'));
        $this->getJson('/api/wall-foundation/audit?dataset=production_capture&view=latest_eod')
            ->assertUnprocessable()->assertJsonPath('message', 'No production capture is installed locally.');
    }
}
