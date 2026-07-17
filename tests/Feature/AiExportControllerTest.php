<?php

namespace Tests\Feature;

use App\Models\AiExport;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_the_authenticated_users_export_metadata(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

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
        $user = User::factory()->create();

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
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

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
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
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
        $owner = User::factory()->create();
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

    public function test_export_history_requires_authentication(): void
    {
        $this->getJson('/api/watchlist/eod-exports')->assertUnauthorized();
    }
}
