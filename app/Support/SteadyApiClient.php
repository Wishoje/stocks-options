<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class SteadyApiClient
{
    protected string $baseUri;
    protected ?string $token;

    public function __construct()
    {
        $this->baseUri = rtrim(env('STEADY_API_BASE_URI'), '/');
        $this->token   = env('STEADY_API_KEY') ?: null;
    }

    /**
     * Fetch the most-active option underlyings for today.
     *
     * @param  int    $limit  Total symbols to fetch (across pages).
     * @param  string $type   STOCKS | ETFS | INDICES
     * @return array<array<string,mixed>>
     */
    public function mostActiveOptions(int $limit = 200, string $type = 'STOCKS'): array
    {
        if (!$this->token) {
            throw new \RuntimeException('STEADY_API_KEY is not configured.');
        }

        $results = [];
        $page    = 1;

        while (count($results) < $limit) {
            $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept'        => 'application/json',
                ])
                ->get($this->baseUri.'/v1/markets/options/most-active', [
                    'type' => $type,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'SteadyAPI most-active request failed: '.$response->body()
                );
            }

            $data = $response->json();

            $body = $data['body'] ?? $data['data'] ?? [];
            if (!is_array($body) || empty($body)) {
                break; // no more pages
            }

            foreach ($body as $row) {
                $results[] = $this->normalizeMostActiveRow($row);
                if (count($results) >= $limit) {
                    break 2;
                }
            }

            // pagination
            $meta  = $data['meta'] ?? [];
            $total = (int)($meta['total'] ?? 0);
            $count = (int)($meta['count'] ?? count($body));
            $pageNo = (int)($meta['page'] ?? $page);

            if ($count === 0 || ($pageNo * $count) >= $total) {
                break;
            }

            $page++;
        }

        return $results;
    }

    protected function normalizeMostActiveRow(array $row): array
    {
        $symbol    = strtoupper((string)($row['symbol'] ?? ''));
        $lastPrice = $this->toFloat($row['lastPrice'] ?? null);

        // "2,493,798" -> 2493798
        $totalVol  = $this->toInt($row['optionsTotalVolume'] ?? null);

        // "61.1%" / "38.9%" => fractional percentages
        $callPct   = $this->toPercent($row['optionsCallVolumePercent'] ?? null);
        $putPct    = $this->toPercent($row['optionsPutVolumePercent'] ?? null);

        $callVol   = $callPct !== null ? (int) round($totalVol * $callPct) : null;
        $putVol    = $putPct  !== null ? (int) round($totalVol * $putPct)  : null;

        $pcr       = $this->toFloat($row['optionsPutCallVolumeRatio'] ?? null);

        return [
            'symbol'        => $symbol,
            'last_price'    => $lastPrice,
            'total_volume'  => $totalVol,
            'call_volume'   => $callVol,
            'put_volume'    => $putVol,
            'put_call_ratio'=> $pcr,
            'raw'           => $row,
        ];
    }

    protected function toInt($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        $clean = preg_replace('/[^\d\-]/', '', (string) $value);
        return (int) $clean;
    }

    protected function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        // handle things like "+110.66" or "0.64"
        $clean = preg_replace('/[^0-9\.\-]/', '', (string) $value);
        return $clean === '' ? null : (float) $clean;
    }

    protected function toPercent($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        $s = rtrim($s, '%');
        $f = $this->toFloat($s);
        return $f === null ? null : $f / 100.0;
    }
}
