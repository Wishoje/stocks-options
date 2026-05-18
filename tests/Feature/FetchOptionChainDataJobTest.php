<?php

namespace Tests\Feature;

use App\Jobs\FetchOptionChainDataJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchOptionChainDataJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_eod_fetch_backfills_all_massive_expirations_when_finnhub_chain_is_sparse(): void
    {
        config()->set('services.finnhub.api_key', 'finnhub-test');
        config()->set('services.massive.key', 'massive-test');
        config()->set('services.massive.base', 'https://api.massive.com');
        config()->set('services.massive.eod_chain_max_hint_expiries', 90);

        Http::fake(function (Request $request) {
            $url = (string) $request->url();

            if (str_contains($url, 'finnhub.io/api/v1/stock/option-chain')) {
                return Http::response([
                    'lastTradePrice' => 500.0,
                    'data' => [
                        $this->finnhubSet('2026-05-18'),
                    ],
                ]);
            }

            if (str_contains($url, '/v3/reference/options/contracts')) {
                return Http::response([
                    'results' => [
                        ['expiration_date' => '2026-05-18'],
                        ['expiration_date' => '2026-05-22'],
                        ['expiration_date' => '2026-06-05'],
                    ],
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY') && str_contains($url, 'expiration_date=2026-05-22')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-05-22'),
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY') && str_contains($url, 'expiration_date=2026-06-05')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-06-05'),
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-05-18'),
                ]);
            }

            return Http::response([], 404);
        });

        (new FetchOptionChainDataJob(['SPY'], 30, '2026-05-18'))->handle();

        $expirationsWithRows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', 'SPY')
            ->whereDate('o.data_date', '2026-05-18')
            ->distinct()
            ->orderBy('e.expiration_date')
            ->pluck('e.expiration_date')
            ->map(fn ($date) => (string) $date)
            ->all();

        $this->assertSame([
            '2026-05-18',
            '2026-05-22',
            '2026-06-05',
        ], $expirationsWithRows);

        $meta = Cache::get('eod:fetch-meta:SPY:2026-05-18');
        $this->assertSame('massive', $meta['provider'] ?? null);
        $this->assertSame(3, $meta['massive_expiries_in_window'] ?? null);
    }

    public function test_eod_fetch_probes_daily_expiration_candidates_when_reference_discovery_is_sparse(): void
    {
        config()->set('services.finnhub.api_key', 'finnhub-test');
        config()->set('services.massive.key', 'massive-test');
        config()->set('services.massive.base', 'https://api.massive.com');
        config()->set('services.massive.eod_chain_max_hint_expiries', 90);

        Http::fake(function (Request $request) {
            $url = (string) $request->url();

            if (str_contains($url, 'finnhub.io/api/v1/stock/option-chain')) {
                return Http::response([
                    'lastTradePrice' => 500.0,
                    'data' => [
                        $this->finnhubSet('2026-05-18'),
                        $this->finnhubSet('2026-05-19'),
                    ],
                ]);
            }

            if (str_contains($url, '/v3/reference/options/contracts')) {
                return Http::response([
                    'results' => [
                        ['expiration_date' => '2026-05-18'],
                        ['expiration_date' => '2026-05-19'],
                    ],
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY') && str_contains($url, 'expiration_date=2026-05-20')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-05-20'),
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY') && str_contains($url, 'expiration_date=2026-05-22')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-05-22'),
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY') && str_contains($url, 'expiration_date=')) {
                return Http::response(['results' => []]);
            }

            if (str_contains($url, '/v3/snapshot/options/SPY')) {
                return Http::response([
                    'results' => $this->massiveContracts('2026-05-18'),
                ]);
            }

            return Http::response([], 404);
        });

        (new FetchOptionChainDataJob(['SPY'], 30, '2026-05-18'))->handle();

        $expirationsWithRows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', 'SPY')
            ->whereDate('o.data_date', '2026-05-18')
            ->distinct()
            ->orderBy('e.expiration_date')
            ->pluck('e.expiration_date')
            ->map(fn ($date) => (string) $date)
            ->all();

        $this->assertSame([
            '2026-05-18',
            '2026-05-20',
            '2026-05-22',
        ], $expirationsWithRows);

        $meta = Cache::get('eod:fetch-meta:SPY:2026-05-18');
        $this->assertGreaterThan(2, $meta['candidate_expiries_generated'] ?? 0);
        $this->assertSame(2, $meta['expiry_backfill_fetched'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function finnhubSet(string $expirationDate): array
    {
        return [
            'expirationDate' => $expirationDate,
            'options' => [
                'CALL' => [[
                    'strike' => 500.0,
                    'openInterest' => 1200,
                    'volume' => 300,
                    'impliedVolatility' => 0.2,
                    'apiGamma' => 0.01,
                    'apiDelta' => 0.52,
                    'apiVega' => 0.2,
                ]],
                'PUT' => [[
                    'strike' => 500.0,
                    'openInterest' => 1100,
                    'volume' => 250,
                    'impliedVolatility' => 0.22,
                    'apiGamma' => 0.011,
                    'apiDelta' => -0.48,
                    'apiVega' => 0.21,
                ]],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function massiveContracts(string $expirationDate): array
    {
        return [
            $this->massiveContract($expirationDate, 'call'),
            $this->massiveContract($expirationDate, 'put'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function massiveContract(string $expirationDate, string $type): array
    {
        return [
            'details' => [
                'expiration_date' => $expirationDate,
                'strike_price' => 500.0,
                'contract_type' => $type,
            ],
            'underlying_asset' => [
                'price' => 501.0,
            ],
            'open_interest' => $type === 'call' ? 1300 : 1250,
            'session' => [
                'volume' => $type === 'call' ? 320 : 280,
            ],
            'implied_volatility' => $type === 'call' ? 0.2 : 0.22,
            'greeks' => [
                'gamma' => $type === 'call' ? 0.01 : 0.011,
                'delta' => $type === 'call' ? 0.52 : -0.48,
                'vega' => $type === 'call' ? 0.2 : 0.21,
            ],
        ];
    }
}
