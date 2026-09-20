<?php

namespace Tests\Feature;

use App\Http\Controllers\VolController;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Support\PositioningRegimeRepository;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PositioningDerivationTest extends TestCase
{
    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'positioning-derivation-');
        if ($database === false) {
            $this->fail('Unable to create the isolated positioning-derivation database.');
        }
        $this->databasePath = $database;
        $this->originalConnection = DB::getDefaultConnection();
        $sqlite = config('database.connections.sqlite');
        $sqlite['database'] = $this->databasePath;
        config()->set('database.connections.positioning_derivation_test', $sqlite);
        DB::setDefaultConnection('positioning_derivation_test');
        DB::purge('positioning_derivation_test');

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('GREATEST', fn (...$values) => max($values), -1);
        $pdo->sqliteCreateFunction('LEAST', fn (...$values) => min($values), -1);
        $this->createTables();
        Carbon::setTestNow(Carbon::parse('2026-03-18 17:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('positioning_derivation_test');
        DB::purge('positioning_derivation_test');
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_positioning_persists_a_fixed_fourteen_calendar_day_gamma_regime(): void
    {
        $anchor = $this->createExpiration('SPY', '2026-03-18');
        $boundary = $this->createExpiration('SPY', '2026-04-01');
        $outside = $this->createExpiration('SPY', '2026-04-02');
        foreach ([$anchor, $boundary, $outside] as $expirationId) {
            $this->insertChainPair($expirationId, '2026-03-18');
        }

        (new ComputePositioningJob(['SPY']))->handle();

        $regime = app(PositioningRegimeRepository::class)->find('SPY', '2026-03-18');
        $this->assertNotNull($regime);
        $this->assertSame(14, $regime['scope_days']);
        $this->assertSame(1, $regime['sign']);
        $this->assertEqualsWithDelta(1 / 9, $regime['strength'], 0.000000000001);
        $this->assertSame('2026-04-01', $regime['source_meta']['scope_end_date']);
        $this->assertSame(
            ['2026-03-18', '2026-04-01'],
            $regime['source_meta']['expiration_dates']
        );
        $this->assertArrayNotHasKey($outside, $regime['source_meta']['selected_snapshot_dates']);
    }

    public function test_dense_smile_keeps_wing_coverage_and_day_changes_distinguish_null_from_zero(): void
    {
        $expirationId = $this->createExpiration('SPY', '2026-04-17');
        $this->insertDenseSmile($expirationId, '2026-03-18');
        DB::table('iv_skew')->insert([
            'symbol' => 'SPY',
            'data_date' => '2026-03-17',
            'exp_date' => '2026-04-17',
            'skew_pc' => null,
            'curvature' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ComputeVolMetricsJob(['SPY']))->handle();

        $stored = $this->storedSkew();
        $this->assertNotNull($stored->curvature);
        $this->assertNull($stored->skew_pc_dod);
        $this->assertNull($stored->curvature_dod);

        $debug = app(VolController::class)->skewDebug(Request::create(
            '/api/iv/skew/debug',
            'GET',
            ['symbol' => 'SPY', 'exp' => '2026-04-17']
        ))->getData(true);
        $this->assertSame(322, $debug['counts']['pts_used']);
        $this->assertGreaterThan(0.18, $debug['moneyness']['k_abs_span']);
        $this->assertEqualsWithDelta(
            (float) $stored->curvature,
            (float) $debug['curvature']['curv_scaled'],
            0.00000001
        );

        DB::table('iv_skew')
            ->where('symbol', 'SPY')
            ->where('data_date', '2026-03-17')
            ->where('exp_date', '2026-04-17')
            ->update(['skew_pc' => 0.0, 'curvature' => 0.0]);
        (new ComputeVolMetricsJob(['SPY']))->handle();
        $withZeroPrior = $this->storedSkew();
        $this->assertEqualsWithDelta((float) $withZeroPrior->skew_pc, (float) $withZeroPrior->skew_pc_dod, 0.000001);
        $this->assertEqualsWithDelta((float) $withZeroPrior->curvature, (float) $withZeroPrior->curvature_dod, 0.00000001);
    }

    protected function storedSkew(): object
    {
        return DB::table('iv_skew')
            ->where('symbol', 'SPY')
            ->where('data_date', '2026-03-18')
            ->where('exp_date', '2026-04-17')
            ->firstOrFail();
    }

    protected function createExpiration(string $symbol, string $expirationDate): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertChainPair(int $expirationId, string $dataDate): void
    {
        DB::table('option_chain_data')->insert([
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'call',
                'strike' => 100,
                'open_interest' => 100,
                'volume' => 25,
                'gamma' => 0.01,
                'delta' => 0.5,
                'iv' => 0.24,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'put',
                'strike' => 100,
                'open_interest' => 80,
                'volume' => 20,
                'gamma' => 0.01,
                'delta' => -0.5,
                'iv' => 0.26,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function insertDenseSmile(int $expirationId, string $dataDate): void
    {
        $rows = [];
        for ($step = 0; $step <= 160; $step++) {
            $strike = 80 + ($step * 0.25);
            $k = log($strike / 100);
            $baseIv = 0.22 + (0.08 * $k) + (0.6 * $k * $k);

            foreach ([['call', 0.0, 0.25], ['put', 0.02, -0.25]] as [$type, $offset, $delta]) {
                $rows[] = [
                    'expiration_id' => $expirationId,
                    'data_date' => $dataDate,
                    'option_type' => $type,
                    'strike' => $strike,
                    'open_interest' => 100,
                    'volume' => 25,
                    'gamma' => 0.01,
                    'delta' => $delta,
                    'iv' => $baseIv + $offset,
                    'underlying_price' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 40) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
    }

    protected function createTables(): void
    {
        Schema::create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('expiration_date');
            $table->timestamps();
        });
        Schema::create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->date('data_date');
            $table->string('option_type', 4);
            $table->decimal('strike', 8, 2);
            $table->bigInteger('open_interest')->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('gamma', 12, 8)->nullable();
            $table->decimal('delta', 12, 8)->nullable();
            $table->decimal('iv', 12, 8)->nullable();
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->timestamps();
        });
        Schema::create('prices_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('trade_date');
            $table->decimal('close', 14, 6);
            $table->timestamps();
        });
        Schema::create('iv_term', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->date('exp_date');
            $table->decimal('iv', 12, 8)->nullable();
            $table->date('source_chain_date')->nullable();
            $table->timestamps();
        });
        Schema::create('vrp_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->decimal('iv1m', 12, 8)->nullable();
            $table->decimal('rv20', 12, 8)->nullable();
            $table->decimal('vrp', 12, 8)->nullable();
            $table->decimal('z', 12, 8)->nullable();
            $table->json('source_meta_json')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'data_date']);
        });
        Schema::create('iv_skew', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->date('exp_date');
            $table->decimal('iv_put_25d', 12, 8)->nullable();
            $table->decimal('iv_call_25d', 12, 8)->nullable();
            $table->decimal('skew_pc', 12, 8)->nullable();
            $table->decimal('curvature', 14, 10)->nullable();
            $table->decimal('skew_pc_dod', 12, 8)->nullable();
            $table->decimal('curvature_dod', 14, 10)->nullable();
            $table->date('source_chain_date')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'data_date', 'exp_date']);
        });
        Schema::create('dex_by_expiry', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->date('exp_date');
            $table->double('dex_total');
            $table->date('source_chain_date')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'data_date', 'exp_date']);
        });
        Schema::create('positioning_regimes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->unsignedSmallInteger('scope_days');
            $table->double('strength')->nullable();
            $table->tinyInteger('gamma_sign')->nullable();
            $table->double('net_gamma')->nullable();
            $table->double('absolute_gamma')->nullable();
            $table->json('source_meta_json')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'data_date', 'scope_days']);
        });
    }
}
