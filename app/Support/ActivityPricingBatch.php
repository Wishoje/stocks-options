<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Request-local pricing inputs; no cross-request cache or pricing-rule changes. */
final class ActivityPricingBatch
{
    private array $rows = [];

    private array $fallbackSpots = [];

    private function __construct(public readonly string $source, public readonly array $directColumns) {}

    /** @param array<int, object> $selectedRows Rows requiring estimated premium only. */
    public static function load(string $symbol, array $selectedRows): self
    {
        if (Schema::hasTable('option_quotes')) {
            $batch = new self('option_quotes', []);
        } else {
            // Schema::hasColumn is case-insensitive; retain that capability
            // rule while resolving the catalog only once per response.
            $available = array_map('strtolower', Schema::getColumnListing('option_chain_data'));
            $columns = array_values(array_map(static fn (string $column): string => 'o.'.$column,
                array_intersect(['mid_price', 'last_price', 'close', 'bid', 'ask'], $available)));
            $batch = new self($columns === [] ? 'theoretical' : 'chain_quotes', $columns);
        }

        $keys = [];
        foreach ($selectedRows as $row) {
            $keys[self::key($row->exp_date, (float) $row->strike)] = [$row->exp_date, (float) $row->strike];
        }
        $chunkSize = max(1, min(250, (int) config('activity_performance.pricing_batch_size', 100)));
        foreach (array_chunk($keys, $chunkSize, true) as $chunk) {
            $query = null;
            $lookup = [];
            foreach ($chunk as $key => [$expiration, $strike]) {
                $ordinal = count($lookup);
                $lookup[$ordinal] = $key;
                $batch->rows[$key] = [];
                $branch = $batch->query($symbol, $expiration, $strike)->selectRaw('? as activity_pricing_key', [$ordinal]);
                $query = $query === null ? $branch : $query->unionAll($branch);
            }
            // Retain each legacy exact-key SELECT, including its all-history
            // predicates. A broad join could change duplicate-row precedence.
            foreach ($query->get() as $row) {
                $batch->rows[$lookup[(int) $row->activity_pricing_key]][] = $row;
            }
        }

        return $batch;
    }

    public function rows(string $expiration, float $strike): array
    {
        return $this->rows[self::key($expiration, $strike)] ?? [];
    }

    public function fallbackSpot(string $date, Closure $load): ?float
    {
        if (! array_key_exists($date, $this->fallbackSpots)) {
            $this->fallbackSpots[$date] = $load();
        }

        return $this->fallbackSpots[$date];
    }

    private function query(string $symbol, string $expiration, float $strike): Builder
    {
        if ($this->source === 'option_quotes') {
            return DB::table('option_quotes')->where('symbol', $symbol)
                ->whereDate('expiration_date', $expiration)->where('strike', $strike)
                ->whereIn('option_type', ['call', 'put'])->select('option_type', 'bid', 'ask', 'mark', 'last');
        }

        $query = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)->whereDate('e.expiration_date', $expiration)
            ->where('o.strike', $strike)->whereIn('o.option_type', ['call', 'put']);

        return $this->source === 'chain_quotes'
            ? $query->selectRaw('o.option_type, '.implode(',', $this->directColumns))
            : $query->select('o.option_type', 'o.iv', 'o.underlying_price');
    }

    private static function key(string $expiration, float $strike): string
    {
        // JSON float keys depend on serialize_precision and can collapse two
        // valid fractional strikes. Use the exact requested float bits instead.
        return $expiration.':'.bin2hex(pack('E', $strike));
    }
}
