<?php

namespace Tests\Feature;

use App\Support\WallIntelligence\WallIvReference;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WallIvReferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('option_expirations', function (Blueprint $t) {
            $t->id();
            $t->date('expiration_date');
        });
        Schema::create('option_chain_data', function (Blueprint $t) {
            $t->id();
            $t->integer('expiration_id');
            $t->string('option_type');
            $t->double('strike');
            $t->double('iv')->nullable();
            $t->date('data_date');
            $t->timestamp('data_timestamp');
        });
        DB::table('option_expirations')->insert([
            ['id' => 1, 'expiration_date' => '2026-10-16'],
            ['id' => 2, 'expiration_date' => '2026-10-23'],
        ]);
    }

    private function contract(array $replace = []): array
    {
        return array_replace(['expiry' => '2026-10-16', 'type' => 'call', 'strike' => 100.,
            'oi' => 500., 'iv' => null, 'data_date' => '2026-10-09', 'data_timestamp' => '2026-10-09 20:16:00'], $replace);
    }

    private function stored(array $replace = []): void
    {
        DB::table('option_chain_data')->insert(array_replace(['expiration_id' => 1, 'option_type' => 'call',
            'strike' => 100., 'iv' => .2, 'data_date' => '2026-10-08', 'data_timestamp' => '2026-10-08 20:16:00'], $replace));
    }

    public function test_reference_uses_latest_exact_contract_without_changing_oi_or_valid_current_iv(): void
    {
        $this->stored(['data_date' => '2026-10-07', 'iv' => .15]);
        $this->stored();
        foreach ([['option_type' => 'put', 'iv' => .9], ['strike' => 101., 'iv' => .8],
            ['expiration_id' => 2, 'iv' => .7]] as $attributes) {
            $this->stored($attributes);
        }
        $valid = $this->contract(['iv' => .25]);
        $rows = app(WallIvReference::class)->resolve([$this->contract(), $valid], [1, 2], '2026-10-09');
        $this->assertSame(.2, $rows[0]['iv']);
        $this->assertSame('2026-10-08', $rows[0]['iv_data_date']);
        $this->assertSame(500., $rows[0]['oi']);
        $this->assertSame('2026-10-09', $rows[0]['data_date']);
        $this->assertSame(.25, $rows[1]['iv']);
        $this->assertSame('2026-10-09', $rows[1]['iv_data_date']);
    }

    public function test_reference_rejects_old_future_and_postdated_repairs_and_never_invents_a_value(): void
    {
        $this->stored(['data_date' => '2026-10-06']);
        $this->stored(['data_date' => '2026-10-12', 'data_timestamp' => '2026-10-12 14:00:00']);
        $this->stored(['data_date' => '2026-10-09', 'data_timestamp' => '2026-10-12 12:30:00']);
        $row = app(WallIvReference::class)->resolve([
            $this->contract(['iv' => .4, 'data_timestamp' => '2026-10-12 12:30:00']),
        ], [1], '2026-10-09')[0];
        $this->assertNull($row['iv']);
        $this->assertArrayNotHasKey('iv_data_date', $row);
        $this->assertSame(500., $row['oi']);
    }

    public function test_lookback_counts_completed_trading_sessions_across_weekends_and_holidays(): void
    {
        $this->stored(['data_date' => '2026-09-03', 'data_timestamp' => '2026-09-03 20:16:00']);
        $row = app(WallIvReference::class)->resolve([$this->contract(['data_date' => '2026-09-08', 'data_timestamp' => null])], [1], '2026-09-08')[0];
        $this->assertSame(.2, $row['iv']);
        $this->assertSame('2026-09-03', $row['iv_data_date']);
    }
}
