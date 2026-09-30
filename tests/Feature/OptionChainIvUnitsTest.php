<?php

namespace Tests\Feature;

use App\Jobs\FetchOptionChainDataJob;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OptionChainIvUnitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'eod_snapshot_health.enabled' => false,
            'services.massive.concurrency.enabled' => false]);
        DB::purge('sqlite');
        Schema::create('option_expirations', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->date('expiration_date');
        });
        Schema::create('option_chain_data', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('expiration_id');
            $t->date('data_date');
            $t->string('option_type');
            foreach (['strike', 'open_interest', 'volume', 'gamma', 'delta', 'vega', 'iv', 'underlying_price'] as $field) {
                $t->double($field)->nullable();
            }
            $t->timestamp('data_timestamp');
            $t->unique(['expiration_id', 'data_date', 'option_type', 'strike']);
        });
        $this->travelTo(Carbon::parse('2026-09-30 20:15:00', 'UTC'));
        Http::preventStrayRequests();
    }

    #[DataProvider('decimalValues')]
    public function test_adapters_and_persistence_keep_decimal_iv_without_rescaling(mixed $input, ?float $expected): void
    {
        foreach (['massive', 'finnhub'] as $provider) {
            $job = $this->job($input, $provider);
            $job->handle();
            $rows = DB::table('option_chain_data')->get();
            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                $this->assertSame($expected, $row->iv === null ? null : (float) $row->iv, $provider);
                $this->assertSame(.0123, (float) $row->gamma); // Provider Greeks remain unchanged.
                $this->assertSame(200, (int) $row->open_interest);
            }
            $this->assertSame('decimal.v1', Cache::get('eod:fetch-meta:SPY:2026-09-30')['iv_normalization_version']);
        }
        Http::assertNothingSent();
    }

    public static function decimalValues(): array
    {
        return ['below 100 percent' => [.25, .25], 'exactly 100 percent' => [1, 1.0],
            'just above 100 percent' => [1.0001, 1.0001], '150 percent string' => ['1.5', 1.5],
            '2000 percent' => [20, 20.0], 'no repeated division' => [101.5, 101.5],
            'tiny positive' => [.0001, .0001], 'absent' => [null, null], 'zero' => [0, null],
            'negative' => [-.2, null], 'text' => ['bad', null], 'empty' => ['', null],
            'infinity' => [INF, null], 'not a number' => [NAN, null]];
    }

    public function test_fallback_gamma_uses_high_iv_and_absent_iv_stays_absent(): void
    {
        $this->job(1.5, 'massive', false)->handle();
        // S=500, K=600, one month and 150% IV give positive OTM gamma.
        // The old 1.5% conversion underflowed to essentially zero.
        $row = DB::table('option_chain_data')->where('option_type', 'call')->first();
        $this->assertGreaterThan(.001, (float) $row->gamma);
        $this->assertLessThan(.003, (float) $row->gamma);
        $this->assertGreaterThan(.3, (float) $row->delta);
        $this->job(null, 'massive', false)->handle();
        $row = DB::table('option_chain_data')->where('option_type', 'call')->first();
        $this->assertNull($row->iv);
        $this->assertNull($row->gamma);
    }

    private function job(mixed $iv, string $provider, bool $greeks = true): FetchOptionChainDataJob
    {
        return new class($iv, $provider, $greeks) extends FetchOptionChainDataJob
        {
            public function __construct(private mixed $inputIv, private string $provider, private bool $greeks)
            {
                parent::__construct(['SPY'], 60, '2026-09-30');
            }

            protected function fetchChain(string $symbol, ?Carbon $windowStart = null, ?Carbon $windowEnd = null): array
            {
                if ($this->provider === 'massive') {
                    $contracts = [];
                    foreach (['call', 'put'] as $side) {
                        $contracts[] = ['details' => ['expiration_date' => '2026-10-30', 'contract_type' => $side, 'strike_price' => 600],
                            'open_interest' => 200, 'day' => ['volume' => 25], 'implied_volatility' => $this->inputIv,
                            'greeks' => $this->greeks ? ['gamma' => .0123, 'delta' => .5, 'vega' => .2] : []];
                    }
                    $sets = array_values($this->normalizeMassiveContracts($contracts));
                } else {
                    $option = ['strike' => 600, 'openInterest' => 200, 'volume' => 25,
                        'impliedVolatility' => $this->inputIv, 'apiGamma' => .0123, 'apiDelta' => .5, 'apiVega' => .2];
                    $sets = [['expirationDate' => '2026-10-30', 'options' => ['CALL' => [$option], 'PUT' => [$option]]]];
                }

                return [500.0, $sets, ['provider' => $this->provider, 'provider_complete' => true]];
            }
        };
    }
}
