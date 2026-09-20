<?php

namespace Tests\Feature;

use App\Support\PositioningRegimeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PositioningRegimeRepositoryTest extends TestCase
{
    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'positioning-regime-');
        if ($database === false) {
            $this->fail('Unable to create the isolated positioning-regime database.');
        }
        $this->databasePath = $database;
        $this->originalConnection = DB::getDefaultConnection();
        $sqlite = config('database.connections.sqlite');
        $sqlite['database'] = $this->databasePath;
        config()->set('database.connections.positioning_regime_test', $sqlite);
        DB::setDefaultConnection('positioning_regime_test');
        DB::purge('positioning_regime_test');

        (require database_path('migrations/2026_09_14_000100_create_positioning_regimes_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('positioning_regime_test');
        DB::purge('positioning_regime_test');
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_it_persists_and_updates_a_scoped_daily_regime_without_losing_zero_values(): void
    {
        $repository = app(PositioningRegimeRepository::class);
        $repository->write('spy', '2026-09-11', 14, [
            'strength' => 0.75,
            'sign' => -1,
            'net_gamma' => -125000.5,
            'absolute_gamma' => 500000.5,
            'source_meta' => ['expiration_dates' => ['2026-09-11', '2026-09-18']],
        ]);
        $repository->write('SPY', '2026-09-11', 14, [
            'strength' => 0.0,
            'sign' => 0,
            'net_gamma' => 0.0,
            'absolute_gamma' => 250000.0,
            'source_meta' => ['expiration_dates' => ['2026-09-11']],
        ]);

        $this->assertSame(1, DB::table('positioning_regimes')->count());
        $this->assertSame([
            'date' => '2026-09-11',
            'scope_days' => 14,
            'strength' => 0.0,
            'sign' => 0,
            'net_gamma' => 0.0,
            'absolute_gamma' => 250000.0,
            'source_meta' => ['expiration_dates' => ['2026-09-11']],
        ], $repository->find('SPY', '2026-09-11'));
    }

    public function test_find_returns_null_during_a_rolling_deploy_before_the_table_exists(): void
    {
        Schema::drop('positioning_regimes');

        $this->assertNull(app(PositioningRegimeRepository::class)->find('SPY', '2026-09-11'));
    }
}
