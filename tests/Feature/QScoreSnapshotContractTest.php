<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFeature;
use Carbon\Carbon;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QScoreSnapshotContractTest extends TestCase
{
    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'qscore-snapshot-');
        if ($database === false) {
            $this->fail('Unable to create the isolated Q-Score database.');
        }
        $this->databasePath = $database;
        $this->originalConnection = DB::getDefaultConnection();
        $sqlite = config('database.connections.sqlite');
        $sqlite['database'] = $this->databasePath;
        config()->set([
            'database.connections.qscore_snapshot_test' => $sqlite,
            'services.massive.eod_force_data_date' => '',
        ]);
        DB::setDefaultConnection('qscore_snapshot_test');
        DB::purge('qscore_snapshot_test');

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('GREATEST', fn (...$values) => max($values), -1);
        $pdo->sqliteCreateFunction('LEAST', fn (...$values) => min($values), -1);
        $this->createTables();
        // Authentication and entitlement behavior is covered by
        // AuthenticatedProductRouteBoundaryTest; this fixture isolates Q-Score data semantics.
        $this->withoutMiddleware([Authenticate::class, EnsureFeature::class]);

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('qscore_snapshot_test');
        DB::purge('qscore_snapshot_test');
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_explicit_dashboard_date_anchors_every_score_and_reports_each_source_date(): void
    {
        $front = $this->createExpiration('SPY', '2026-10-16');
        $back = $this->createExpiration('SPY', '2026-11-20');
        $this->insertOptionPair($front, '2026-09-11');
        $this->insertOptionPair($back, '2026-09-10');
        $this->insertOptionPair($front, '2026-09-14', callOpenInterest: 1, putOpenInterest: 1000, gamma: -1.0);
        $this->insertOptionPair($back, '2026-09-14', callOpenInterest: 1, putOpenInterest: 1000, gamma: -1.0);

        DB::table('vrp_daily')->insert([
            ['symbol' => 'SPY', 'data_date' => '2026-09-10', 'iv1m' => 0.25, 'rv20' => 0.20, 'vrp' => 0.05, 'z' => 1.5],
            ['symbol' => 'SPY', 'data_date' => '2026-09-14', 'iv1m' => 0.15, 'rv20' => 0.30, 'vrp' => -0.15, 'z' => -3.0],
        ]);
        DB::table('seasonality_5d')->insert([
            ['symbol' => 'SPY', 'data_date' => '2026-09-09', 'cum5' => -0.02, 'z' => -1.5],
            ['symbol' => 'SPY', 'data_date' => '2026-09-14', 'cum5' => 0.03, 'z' => 3.0],
        ]);
        $this->insertRisingPriceHistory();

        $response = $this->getJson('/api/qscore?symbol=spy&date=2026-09-11');

        $response->assertOk()
            ->assertJsonPath('symbol', 'SPY')
            ->assertJsonPath('date', '2026-09-11')
            ->assertJsonPath('scores.option.score', 3.2)
            ->assertJsonPath('scores.vol.score', 3)
            ->assertJsonPath('scores.momo.score', 4)
            ->assertJsonPath('scores.season.score', 1)
            ->assertJsonPath('source_dates.option.earliest', '2026-09-10')
            ->assertJsonPath('source_dates.option.latest', '2026-09-11')
            ->assertJsonPath('source_dates.volatility', '2026-09-10')
            ->assertJsonPath('source_dates.momentum', '2026-09-11')
            ->assertJsonPath('source_dates.seasonality', '2026-09-09');
    }

    public function test_default_anchor_uses_the_last_completed_session_and_excludes_future_rows(): void
    {
        $expiration = $this->createExpiration('QQQ', '2026-10-16');
        $this->insertOptionPair($expiration, '2026-09-14');
        DB::table('vrp_daily')->insert([
            'symbol' => 'QQQ', 'data_date' => '2026-09-14',
            'iv1m' => 0.25, 'rv20' => 0.20, 'vrp' => 0.05, 'z' => 2.0,
        ]);
        DB::table('prices_daily')->insert([
            'symbol' => 'QQQ', 'trade_date' => '2026-09-14', 'close' => 500,
        ]);
        DB::table('seasonality_5d')->insert([
            'symbol' => 'QQQ', 'data_date' => '2026-09-14', 'cum5' => 0.02, 'z' => 2.0,
        ]);

        $response = $this->getJson('/api/qscore?symbol=QQQ');

        $response->assertOk()
            ->assertJsonPath('date', '2026-09-11')
            ->assertJsonPath('scores.option.score', 2)
            ->assertJsonPath('scores.vol.score', 2)
            ->assertJsonPath('scores.momo.score', 2)
            ->assertJsonPath('scores.season.score', 2)
            ->assertJsonPath('source_dates.option.earliest', null)
            ->assertJsonPath('source_dates.option.latest', null)
            ->assertJsonPath('source_dates.volatility', null)
            ->assertJsonPath('source_dates.momentum', null)
            ->assertJsonPath('source_dates.seasonality', null);
    }

    private function createExpiration(string $symbol, string $expirationDate): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
        ]);
    }

    private function insertOptionPair(
        int $expirationId,
        string $dataDate,
        int $callOpenInterest = 100,
        int $putOpenInterest = 50,
        float $gamma = 0.01
    ): void {
        DB::table('option_chain_data')->insert([
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'call',
                'strike' => 100,
                'open_interest' => $callOpenInterest,
                'gamma' => $gamma,
            ],
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'put',
                'strike' => 100,
                'open_interest' => $putOpenInterest,
                'gamma' => $gamma,
            ],
        ]);
    }

    private function insertRisingPriceHistory(): void
    {
        $start = Carbon::parse('2026-07-14', 'America/New_York');
        $rows = [];
        for ($index = 0; $index < 60; $index++) {
            $rows[] = [
                'symbol' => 'SPY',
                'trade_date' => $start->copy()->addDays($index)->toDateString(),
                'close' => 100 + $index,
            ];
        }
        $rows[] = ['symbol' => 'SPY', 'trade_date' => '2026-09-14', 'close' => 1];
        DB::table('prices_daily')->insert($rows);
    }

    private function createTables(): void
    {
        Schema::create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('expiration_date');
        });
        Schema::create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->date('data_date');
            $table->string('option_type', 4);
            $table->decimal('strike', 10, 2);
            $table->bigInteger('open_interest')->nullable();
            $table->decimal('gamma', 12, 8)->nullable();
        });
        Schema::create('vrp_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->decimal('iv1m', 12, 8)->nullable();
            $table->decimal('rv20', 12, 8)->nullable();
            $table->decimal('vrp', 12, 8)->nullable();
            $table->decimal('z', 12, 8)->nullable();
        });
        Schema::create('prices_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('trade_date');
            $table->decimal('close', 14, 6);
        });
        Schema::create('seasonality_5d', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->decimal('d1', 12, 8)->nullable();
            $table->decimal('d2', 12, 8)->nullable();
            $table->decimal('d3', 12, 8)->nullable();
            $table->decimal('d4', 12, 8)->nullable();
            $table->decimal('d5', 12, 8)->nullable();
            $table->decimal('cum5', 12, 8)->nullable();
            $table->decimal('z', 12, 8)->nullable();
        });
    }
}
