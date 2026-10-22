<?php

namespace Tests\Feature;

use App\Models\AiExport;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class AiExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_resolves_wall_controller_dependencies_and_keeps_setup_context(): void
    {
        $payload = ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '7d',
            'gamma_context' => ['status' => 'ready', 'model_version' => 'intraday-fixed-reference-iv-gamma-profile.v1',
                'regime' => 'positive_gamma', 'nearest_crossing' => ['price' => 99.5, 'below_sign' => -1, 'above_sign' => 1]],
            'wall_interaction' => ['setups' => ['rule_version' => 'conditional-walls.v1',
                'as_of' => '2026-09-30T15:00:00Z', 'current' => ['put' => ['status' => 'watch']]],
                'setup_reviews' => ['rule_version' => 'frozen-levels-60m-close.v1', 'summary' => ['qualified' => 2, 'target_reached' => 1]]]];
        $this->mock(\App\Services\IntradayWallTracker::class)
            ->shouldReceive('history')->once()->with('SPY', null, '7d')->andReturn($payload);
        $result = app(\App\Services\AiExportBuilder::class)->build(['SPY'], ['intraday_walls'], '14d');
        $item = $result['items'][0];
        $this->assertTrue($item['intraday_walls']['ok']);
        $this->assertSame($payload, $item['intraday_walls']['data']);
        $this->assertSame($payload['gamma_context'], $item['summary']['intraday_gamma_context']);
        $this->assertSame('conditional-walls.v1', $item['summary']['intraday_setups']['rule_version']);
        $this->assertFalse($item['summary']['intraday_setups']['actionable']);
        $this->assertSame(2, $item['summary']['intraday_setups']['recorded_reviews']['qualified']);
        $this->assertSame('frozen-levels-60m-close.v1', $item['summary']['intraday_setups']['outcome_rule_version']);
    }

    public function test_builder_preserves_requests_for_controllers_with_a_different_parameter_name(): void
    {
        $result = app(\App\Services\AiExportBuilder::class)->build(['QQQ'], ['qscore'], '14d');
        $this->assertTrue($result['items'][0]['qscore']['ok']);
        $this->assertSame('QQQ', $result['items'][0]['qscore']['data']['symbol']);
    }

    public function test_intraday_volatility_is_opt_in_and_can_be_queued_explicitly(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $user = $this->productUser();
        $this->actingAs($user)->postJson('/api/watchlist/eod-export', ['symbols' => ['SPY']])->assertAccepted();
        $this->assertNotContains('intraday_volatility', AiExport::query()->latest('id')->first()->indicators);
        $this->assertNotContains('intraday_walls', AiExport::query()->latest('id')->first()->indicators);
        $this->actingAs($user)->postJson('/api/watchlist/eod-export', ['symbols' => ['SPY'], 'indicators' => ['intraday_volatility']])->assertAccepted();
        $this->assertSame(['intraday_volatility'], AiExport::query()->latest('id')->first()->indicators);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\BuildAiExportJob::class, 2);
    }

    public function test_intraday_walls_are_opt_in_and_independent_of_eod_scope_and_require_intraday_access(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $user = $this->productUser();
        $this->actingAs($user)->postJson('/api/watchlist/eod-export', ['symbols' => ['SPY'], 'indicators' => ['intraday_walls'], 'timeframe' => '14d'])->assertAccepted();
        $this->assertSame(['intraday_walls'], AiExport::query()->latest('id')->first()->indicators);
        $this->actingAs($user)->postJson('/api/watchlist/eod-export', ['symbols' => ['SPY'], 'indicators' => ['intraday_walls'], 'timeframe' => 'monthly'])->assertAccepted();
        $guard = \Mockery::mock(\App\Http\Middleware\EnsureFeature::class)->makePartial();
        $guard->shouldReceive('handle')->withArgs(fn ($request, $next, $feature, $mode = null) => $feature === 'intraday.access' && $mode === 'strict')
            ->once()->andReturn(response()->json(['message' => 'Intraday access required'], 403));
        $this->app->instance(\App\Http\Middleware\EnsureFeature::class, $guard);
        $this->actingAs($user)->postJson('/api/watchlist/eod-export', ['symbols' => ['SPY'], 'indicators' => ['intraday_walls'], 'timeframe' => '14d'])->assertForbidden();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\BuildAiExportJob::class, 2);
    }

    public function test_index_returns_only_the_authenticated_users_export_metadata(): void
    {
        $owner = $this->productUser();
        $otherUser = $this->productUser();

        $owned = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY', 'QQQ'],
            'indicators' => ['gex_levels'],
            'options' => ['gex_timeframe' => '30d'],
            'payload_json' => str_repeat('x', 1024 * 1024),
            'file_name' => 'owned-export.json',
            'completed_at' => now(),
        ]);

        AiExport::query()->create([
            'user_id' => $otherUser->id,
            'status' => 'completed',
            'symbols' => ['AAPL'],
            'indicators' => ['qscore'],
            'payload_json' => '{}',
            'completed_at' => now(),
        ]);

        $exportSelects = [];
        DB::listen(function (QueryExecuted $query) use (&$exportSelects): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'ai_exports')) {
                $exportSelects[] = $sql;
            }
        });

        $response = $this->actingAs($owner)->getJson('/api/watchlist/eod-exports');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $owned->id)
            ->assertJsonPath('items.0.symbol_count', 2)
            ->assertJsonPath('items.0.indicator_count', 1)
            ->assertJsonPath(
                'items.0.download_url',
                route('api.ai-export.download', ['export' => $owned->id])
            )
            ->assertJsonMissingPath('items.0.payload_json');

        $this->assertNotEmpty($exportSelects);
        foreach ($exportSelects as $sql) {
            $this->assertStringNotContainsString('payload_json', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        }
    }

    public function test_scalar_legacy_json_metadata_cannot_crash_the_export_list(): void
    {
        $user = $this->productUser();

        $exportId = DB::table('ai_exports')->insertGetId([
            'user_id' => $user->id,
            'status' => 'completed',
            'symbols' => json_encode('SPY', JSON_THROW_ON_ERROR),
            'indicators' => json_encode('gex_levels', JSON_THROW_ON_ERROR),
            'options' => json_encode('legacy', JSON_THROW_ON_ERROR),
            'payload_json' => '{}',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/watchlist/eod-exports');

        $response
            ->assertOk()
            ->assertJsonPath('items.0.id', $exportId)
            ->assertJsonPath('items.0.symbols', [])
            ->assertJsonPath('items.0.symbol_count', 0)
            ->assertJsonPath('items.0.indicators', [])
            ->assertJsonPath('items.0.indicator_count', 0)
            ->assertJsonPath('items.0.options', []);
    }

    public function test_show_returns_metadata_without_exposing_the_payload_and_enforces_ownership(): void
    {
        $owner = $this->productUser();
        $otherUser = $this->productUser();

        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => str_repeat('x', 1024 * 1024),
            'completed_at' => now(),
        ]);

        $exportSelects = [];
        DB::listen(function (QueryExecuted $query) use (&$exportSelects): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'ai_exports')) {
                $exportSelects[] = $sql;
            }
        });

        $this->actingAs($owner)
            ->getJson("/api/watchlist/eod-export/{$export->id}")
            ->assertOk()
            ->assertJsonPath('item.id', $export->id)
            ->assertJsonPath('item.symbol_count', 1)
            ->assertJsonMissingPath('item.payload_json');

        $this->actingAs($otherUser)
            ->getJson("/api/watchlist/eod-export/{$export->id}")
            ->assertForbidden();

        $this->assertNotEmpty($exportSelects);
        foreach ($exportSelects as $sql) {
            $this->assertStringNotContainsString('payload_json', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        }
    }

    public function test_download_authorizes_before_loading_and_returns_the_exact_database_payload(): void
    {
        $owner = $this->productUser();
        $otherUser = $this->productUser();
        $payload = json_encode(['data' => str_repeat('x', 4096)], JSON_THROW_ON_ERROR);

        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => $payload,
            'file_name' => 'owned-export.json',
            'completed_at' => now(),
        ]);

        $exportSelects = [];
        DB::listen(function (QueryExecuted $query) use (&$exportSelects): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'ai_exports')) {
                $exportSelects[] = $sql;
            }
        });

        $this->actingAs($otherUser)
            ->get("/api/watchlist/eod-export/{$export->id}/download")
            ->assertForbidden();

        $this->assertNotEmpty($exportSelects);
        foreach ($exportSelects as $sql) {
            $this->assertStringNotContainsString('payload_json', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        }

        $exportSelects = [];
        $response = $this->actingAs($owner)
            ->get("/api/watchlist/eod-export/{$export->id}/download");

        $response->assertOk()->assertHeader('content-type', 'application/json');
        $this->assertStringContainsString(
            'owned-export.json',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertSame($payload, $response->streamedContent());
        $this->assertTrue(collect($exportSelects)->contains(
            fn (string $sql): bool => str_contains($sql, 'payload_json')
        ));
    }

    public function test_pending_export_download_does_not_load_the_payload(): void
    {
        $owner = $this->productUser();
        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'processing',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => null,
        ]);

        $exportSelects = [];
        DB::listen(function (QueryExecuted $query) use (&$exportSelects): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'ai_exports')) {
                $exportSelects[] = $sql;
            }
        });

        $this->actingAs($owner)
            ->getJson("/api/watchlist/eod-export/{$export->id}/download")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Export is not ready yet.');

        foreach ($exportSelects as $sql) {
            $this->assertStringNotContainsString('payload_json', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        }
    }

    #[DataProvider('chunkedPayloads')]
    public function test_download_preserves_payload_bytes_across_chunk_boundaries(string $payload): void
    {
        $owner = $this->productUser();
        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => $payload,
            'completed_at' => now(),
        ]);

        $payloadReads = [];
        DB::listen(function (QueryExecuted $query) use (&$payloadReads): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'payload_json')) {
                $payloadReads[] = $query;
            }
        });

        $response = $this->actingAs($owner)
            ->get("/api/watchlist/eod-export/{$export->id}/download");

        $response->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('x-accel-buffering', 'no');
        $this->assertSame($payload, $response->streamedContent());
        $this->assertGreaterThan(1, count($payloadReads));
        foreach ($payloadReads as $query) {
            $this->assertStringContainsString('substr(payload_json, ?, ?)', strtolower($query->sql));
            $this->assertSame(1024 * 1024, $query->bindings[1]);
        }
    }

    public static function chunkedPayloads(): array
    {
        return [
            'exact boundary' => ['{"data":"'.str_repeat('x', 1024 * 1024 - 11).'"}'],
            'multiple chunks and final short chunk' => [json_encode([
                'data' => str_repeat('x', 2 * 1024 * 1024 + 17),
            ], JSON_THROW_ON_ERROR)],
            'UTF-8 around boundaries' => [json_encode([
                'data' => str_repeat('x', 1024 * 1024 - 10).'🙂é'.str_repeat('漢', 1024 * 1024).'끝',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_large_mysql_export_download_stays_within_the_web_memory_limit(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The production-size LOB regression requires MySQL.');
        }

        ini_set('memory_limit', '128M');
        $owner = $this->productUser();
        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => '',
            'completed_at' => now(),
        ]);

        $prefix = '{"data":"';
        $suffix = '"}';
        $size = 55 * 1024 * 1024;
        // Generate the fixture in MySQL so PHP never holds the full LOB,
        // including during fixture setup or output verification.
        DB::update("UPDATE ai_exports SET payload_json = CONCAT(?, REPEAT('x', ?), ?) WHERE id = ?", [
            $prefix, $size, $suffix, $export->id,
        ]);

        $expected = hash_init('sha256');
        hash_update($expected, $prefix);
        for ($i = 0; $i < 55; $i++) {
            hash_update($expected, str_repeat('x', 1024 * 1024));
        }
        hash_update($expected, $suffix);

        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        $response = $this->actingAs($owner)
            ->get("/api/watchlist/eod-export/{$export->id}/download");
        $response->assertOk();

        $actual = hash_init('sha256');
        $bytes = 0;
        ob_start(static function (string $chunk) use ($actual, &$bytes): string {
            hash_update($actual, $chunk);
            $bytes += strlen($chunk);

            return '';
        }, 8192);
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertSame($size + strlen($prefix) + strlen($suffix), $bytes);
        $this->assertSame(hash_final($expected), hash_final($actual));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function test_legacy_storage_download_still_works_without_a_database_payload(): void
    {
        Storage::fake('local');
        $payload = '{"legacy":true}';
        Storage::disk('local')->put('exports/legacy.json', $payload);
        $owner = $this->productUser();
        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => null,
            'file_disk' => 'local',
            'file_path' => 'exports/legacy.json',
            'file_name' => 'legacy.json',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get("/api/watchlist/eod-export/{$export->id}/download");

        $response->assertOk()->assertHeader('content-type', 'application/json');
        $this->assertSame($payload, $response->streamedContent());
    }

    public function test_missing_payload_still_marks_export_failed_and_returns_not_found(): void
    {
        $owner = $this->productUser();
        $export = AiExport::query()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex_levels'],
            'payload_json' => '',
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/watchlist/eod-export/{$export->id}/download")
            ->assertNotFound()
            ->assertJsonPath('message', 'Export file is missing.');

        $this->assertSame('failed', $export->fresh()->status);
    }

    public function test_export_history_requires_authentication(): void
    {
        $this->getJson('/api/watchlist/eod-exports')->assertUnauthorized();
    }

    private function productUser(): User
    {
        return User::factory()->create([
            'trial_ends_at' => now()->addDay(),
        ]);
    }
}
