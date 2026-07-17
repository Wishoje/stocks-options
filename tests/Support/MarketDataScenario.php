<?php

namespace Tests\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MarketDataScenario
{
    public const DATE = '2026-03-18';
    public const NOW = '2026-03-18 17:00:00';

    /** @var array<int,string> */
    public const SYMBOLS = ['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT', 'COLD'];

    /**
     * Seed one deterministic production-shaped scenario.
     *
     * @return array{user:User,expirations:array<string,array<string,int>>,expiration_dates:array<int,string>}
     */
    public static function seed(): array
    {
        $now = Carbon::parse(self::NOW, 'America/New_York');
        $createdAt = $now->copy()->utc()->format('Y-m-d H:i:s');
        $expirationDates = self::expirationDates();
        $dataSymbols = ['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT'];
        $spots = [
            'SPY' => 100.0,
            'QQQ' => 200.0,
            'IWM' => 150.0,
            'AAPL' => 180.0,
            'MSFT' => 420.0,
        ];

        $user = User::factory()->create([
            'name' => 'Regression User',
            'email' => 'regression@example.test',
        ]);

        foreach (['SPY', 'AAPL', 'COLD'] as $symbol) {
            DB::table('watchlists')->insert([
                'user_id' => $user->id,
                'symbol' => $symbol,
                'timeframe' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $expirationIds = [];
        foreach ($dataSymbols as $symbol) {
            foreach ($expirationDates as $expirationDate) {
                $expirationIds[$symbol][$expirationDate] = DB::table('option_expirations')->insertGetId([
                    'symbol' => $symbol,
                    'expiration_date' => $expirationDate,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }

        $chainRows = [];
        $dailyRows = [];
        foreach ($dataSymbols as $symbolIndex => $symbol) {
            $dates = $symbol === 'SPY'
                ? ['2026-03-11', '2026-03-17', self::DATE]
                : [self::DATE];

            foreach ($dates as $dataDateIndex => $dataDate) {
                foreach ($expirationDates as $expiryIndex => $expirationDate) {
                    $expirationId = $expirationIds[$symbol][$expirationDate];
                    $spot = $spots[$symbol];
                    $strikes = [$spot - 5, $spot, $spot + 5];
                    $daily = [
                        'call_oi' => 0,
                        'put_oi' => 0,
                        'call_vol' => 0,
                        'put_vol' => 0,
                        'sum_gamma' => 0.0,
                        'sum_delta' => 0.0,
                        'sum_vega' => 0.0,
                    ];

                    foreach ($strikes as $strikeIndex => $strike) {
                        foreach (['call', 'put'] as $type) {
                            $isCall = $type === 'call';
                            $openInterest = 100
                                + ($symbolIndex * 20)
                                + ($expiryIndex * 5)
                                + ($strikeIndex * 3)
                                + ($isCall ? 10 : 0)
                                + ($dataDateIndex * 2);
                            $volume = 20
                                + ($expiryIndex * 2)
                                + $strikeIndex
                                + ($isCall ? 5 : 0)
                                + $dataDateIndex;
                            $gamma = 0.010000 + ($strikeIndex * 0.000500);
                            $delta = $isCall ? 0.55 - ($strikeIndex * 0.05) : -0.45 - ($strikeIndex * 0.05);
                            $vega = 0.20 + ($expiryIndex * 0.01);

                            $chainRows[] = [
                                'expiration_id' => $expirationId,
                                'data_date' => $dataDate,
                                'data_timestamp' => $dataDate.' 21:05:00',
                                'option_type' => $type,
                                'strike' => $strike,
                                'open_interest' => $openInterest,
                                'volume' => $volume,
                                'gamma' => $gamma,
                                'delta' => $delta,
                                'vega' => $vega,
                                'iv' => $isCall ? 0.24 : 0.26,
                                'underlying_price' => $spot,
                                'created_at' => $createdAt,
                                'updated_at' => $createdAt,
                            ];

                            if ($dataDate === self::DATE) {
                                if ($isCall) {
                                    $daily['call_oi'] += $openInterest;
                                    $daily['call_vol'] += $volume;
                                } else {
                                    $daily['put_oi'] += $openInterest;
                                    $daily['put_vol'] += $volume;
                                }
                                $daily['sum_gamma'] += $gamma * $openInterest * 100;
                                $daily['sum_delta'] += $delta * $openInterest * 100;
                                $daily['sum_vega'] += $vega * $openInterest * 100;
                            }
                        }
                    }

                    if ($dataDate === self::DATE) {
                        $dailyRows[] = array_merge([
                            'symbol' => $symbol,
                            'data_date' => self::DATE,
                            'expiration_id' => $expirationId,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ], $daily);
                    }
                }
            }
        }

        foreach (array_chunk($chainRows, 250) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
        DB::table('daily_chain_snapshot')->insert($dailyRows);

        self::seedCalculatorSnapshots($expirationDates, $spots, $createdAt);
        self::seedIntraday($expirationDates, $dataSymbols, $spots, $createdAt);
        self::seedDerived($expirationDates, $dataSymbols, $spots, $createdAt);

        return [
            'user' => $user,
            'expirations' => $expirationIds,
            'expiration_dates' => $expirationDates,
        ];
    }

    /** @return array<int,string> */
    public static function expirationDates(): array
    {
        $anchor = Carbon::parse(self::DATE, 'America/New_York')->startOfDay();

        return collect([0, 1, 5, 10, 21, 64])
            ->map(fn (int $weekdays): string => $weekdays === 0
                ? $anchor->toDateString()
                : $anchor->copy()->addWeekdays($weekdays)->toDateString())
            ->all();
    }

    /**
     * @param  array<int,string>  $expirationDates
     * @param  array<string,float>  $spots
     */
    private static function seedCalculatorSnapshots(array $expirationDates, array $spots, string $createdAt): void
    {
        $rows = [];

        foreach (array_slice($expirationDates, 0, 2) as $expirationDate) {
            $rows = array_merge($rows, self::calculatorGeneration(
                'SPY',
                $expirationDate,
                '2026-03-18 20:50:00',
                $spots['SPY'],
                range(90, 110),
                $createdAt,
            ));
        }

        $rows = array_merge($rows, self::calculatorGeneration(
            'QQQ',
            $expirationDates[0],
            '2026-03-18 20:45:00',
            $spots['QQQ'],
            range(190, 210),
            $createdAt,
        ));
        $rows = array_merge($rows, self::calculatorGeneration(
            'QQQ',
            $expirationDates[0],
            '2026-03-18 20:55:00',
            $spots['QQQ'],
            range(198, 201),
            $createdAt,
        ));
        $rows = array_merge($rows, self::calculatorGeneration(
            'AAPL',
            $expirationDates[0],
            '2026-03-18 20:50:00',
            $spots['AAPL'],
            range(170, 190),
            $createdAt,
        ));

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('option_snapshots')->insert($chunk);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function calculatorGeneration(
        string $symbol,
        string $expirationDate,
        string $fetchedAt,
        float $spot,
        array $strikes,
        string $createdAt,
    ): array {
        $rows = [];
        foreach ($strikes as $strike) {
            foreach (['call', 'put'] as $type) {
                $distance = abs((float) $strike - $spot);
                $mid = max(0.25, 5.0 - ($distance * 0.2) + ($type === 'call' ? 0.1 : 0.2));
                $rows[] = [
                    'symbol' => $symbol,
                    'ticker' => sprintf(
                        'O:%s%s%s%08d',
                        $symbol,
                        str_replace('-', '', $expirationDate),
                        $type === 'call' ? 'C' : 'P',
                        (int) round((float) $strike * 1000)
                    ),
                    'type' => $type,
                    'strike' => $strike,
                    'expiry' => $expirationDate,
                    'bid' => round($mid - 0.10, 2),
                    'ask' => round($mid + 0.10, 2),
                    'mid' => round($mid, 2),
                    'underlying_price' => $spot,
                    'fetched_at' => $fetchedAt,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int,string>  $expirationDates
     * @param  array<int,string>  $symbols
     * @param  array<string,float>  $spots
     */
    private static function seedIntraday(
        array $expirationDates,
        array $symbols,
        array $spots,
        string $createdAt,
    ): void {
        $contractRows = [];
        $counterRows = [];
        $asof = '2026-03-18 20:55:00';

        foreach ($symbols as $symbolIndex => $symbol) {
            $spot = $spots[$symbol];
            $totalVolume = 0;
            $totalPremium = 0.0;

            foreach (['call', 'put'] as $typeIndex => $type) {
                $volume = 100 + ($symbolIndex * 10) + ($typeIndex * 20);
                $openInterest = 500 + ($symbolIndex * 25) + ($typeIndex * 30);
                $premium = 10000.0 + ($symbolIndex * 1000.0) + ($typeIndex * 1500.0);
                $totalVolume += $volume;
                $totalPremium += $premium;

                $contractRows[] = [
                    'symbol' => $symbol,
                    'contract_symbol' => sprintf('O:%s%s%s%08d', $symbol, '20260318', $type === 'call' ? 'C' : 'P', (int) ($spot * 1000)),
                    'contract_type' => $type,
                    'expiration_date' => $expirationDates[0],
                    'strike_price' => $spot,
                    'volume' => $volume,
                    'open_interest' => $openInterest,
                    'implied_volatility' => $type === 'call' ? 0.24 : 0.26,
                    'delta' => $type === 'call' ? 0.52 : -0.48,
                    'gamma' => 0.01,
                    'theta' => -0.02,
                    'vega' => 0.20,
                    'last_price' => $type === 'call' ? 4.8 : 5.1,
                    'change' => 0.1,
                    'change_percent' => 2.0,
                    'request_id' => 'regression-'.$symbol,
                    'captured_at' => $asof,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                foreach ([$spot - 5, $spot] as $strike) {
                    $counterRows[] = [
                        'symbol' => $symbol,
                        'trade_date' => self::DATE,
                        'exp_date' => $expirationDates[0],
                        'strike' => $strike,
                        'option_type' => $type,
                        'volume' => intdiv($volume, 2),
                        'premium_usd' => $premium / 2,
                        'asof' => $asof,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }
            }

            $counterRows[] = [
                'symbol' => $symbol,
                'trade_date' => self::DATE,
                'exp_date' => null,
                'strike' => null,
                'option_type' => null,
                'volume' => $totalVolume,
                'premium_usd' => $totalPremium,
                'asof' => $asof,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('intraday_option_volumes')->insert($contractRows);
        DB::table('option_live_counters')->insert($counterRows);
    }

    /**
     * @param  array<int,string>  $expirationDates
     * @param  array<int,string>  $symbols
     * @param  array<string,float>  $spots
     */
    private static function seedDerived(
        array $expirationDates,
        array $symbols,
        array $spots,
        string $createdAt,
    ): void {
        $uaRows = [];
        $pressureRows = [];
        $dexRows = [];
        $quoteRows = [];
        $priceRows = [];

        foreach ($symbols as $symbolIndex => $symbol) {
            $spot = $spots[$symbol];
            $uaRows[] = [
                'symbol' => $symbol,
                'data_date' => self::DATE,
                'exp_date' => $expirationDates[0],
                'strike' => $spot,
                'z_score' => 4.0 + ($symbolIndex * 0.1),
                'vol_oi' => 2.0,
                'meta' => json_encode([
                    'call_vol' => 120,
                    'put_vol' => 80,
                    'history_samples' => 20,
                    'confidence' => 'normal',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            foreach (array_slice($expirationDates, 0, 2) as $expiryIndex => $expirationDate) {
                $pressureRows[] = [
                    'symbol' => $symbol,
                    'data_date' => self::DATE,
                    'exp_date' => $expirationDate,
                    'pin_score' => 70 + $expiryIndex,
                    'clusters_json' => json_encode([[
                        'strike' => $spot,
                        'width' => 1,
                        'density' => 3,
                        'distance' => 0.0,
                        'score' => 0.70,
                    ]], JSON_THROW_ON_ERROR),
                    'max_pain' => $spot,
                    'source_chain_date' => self::DATE,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            foreach (array_slice($expirationDates, 0, 3) as $expiryIndex => $expirationDate) {
                $dexRows[] = [
                    'symbol' => $symbol,
                    'data_date' => self::DATE,
                    'exp_date' => $expirationDate,
                    'dex_total' => 100000.0 + ($symbolIndex * 10000.0) + ($expiryIndex * 1000.0),
                    'source_chain_date' => self::DATE,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            $quoteRows[] = [
                'symbol' => $symbol,
                'source' => 'regression',
                'last_price' => $spot,
                'prev_close' => $spot - 1,
                'asof' => '2026-03-18 20:55:00',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $priceRows[] = [
                'symbol' => $symbol,
                'trade_date' => self::DATE,
                'open' => $spot - 1,
                'high' => $spot + 2,
                'low' => $spot - 2,
                'close' => $spot,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('unusual_activity')->insert($uaRows);
        DB::table('expiry_pressure')->insert($pressureRows);
        DB::table('dex_by_expiry')->insert($dexRows);
        DB::table('underlying_quotes')->insert($quoteRows);
        DB::table('prices_daily')->insert($priceRows);
    }
}
