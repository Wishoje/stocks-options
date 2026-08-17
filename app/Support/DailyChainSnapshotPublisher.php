<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DailyChainSnapshotPublisher
{
    /** @var list<string> */
    private const SNAPSHOT_COLUMNS = [
        'symbol',
        'data_date',
        'expiration_id',
        'call_oi',
        'put_oi',
        'call_vol',
        'put_vol',
        'sum_gamma',
        'sum_delta',
        'sum_vega',
    ];

    /**
     * Build and atomically publish one complete trading-day snapshot.
     *
     * The aggregate query runs before the transaction. The existing published
     * rows remain visible until the delete and all inserts commit together.
     *
     * @param  null|callable(string,array<string,mixed>):void  $checkpoint
     * @return array{date:string,row_count:int,checksum:string,symbols:list<string>,published_at:string}
     */
    public function publish(string $date, ?callable $checkpoint = null): array
    {
        $date = $this->normalizeDate($date);
        $lockSeconds = max(60, (int) config('daily_chain_snapshot.lock_seconds', 7200));
        $waitSeconds = max(0, (int) config('daily_chain_snapshot.lock_wait_seconds', 5));

        return Cache::lock($this->lockName($date), $lockSeconds)
            ->block($waitSeconds, fn (): array => $this->publishWhileLocked($date, $checkpoint));
    }

    public function lockName(string $date): string
    {
        return 'daily-chain-snapshot:publish:'.$this->normalizeDate($date);
    }

    /**
     * @param  null|callable(string,array<string,mixed>):void  $checkpoint
     * @return array{date:string,row_count:int,checksum:string,symbols:list<string>,published_at:string}
     */
    private function publishWhileLocked(string $date, ?callable $checkpoint): array
    {
        $payload = $this->aggregate($date);
        $rowCount = $payload->count();
        $checksum = $this->checksum($payload);
        $symbols = $payload
            ->pluck('symbol')
            ->map(static fn ($symbol): string => (string) $symbol)
            ->unique()
            ->values()
            ->all();

        $this->checkpoint($checkpoint, 'after_aggregate', [
            'date' => $date,
            'row_count' => $rowCount,
            'checksum' => $checksum,
        ]);

        if ($payload->isEmpty()) {
            throw new RuntimeException(
                "Refusing to replace the daily chain snapshot for {$date} with an empty publication."
            );
        }

        $publishedAt = now('UTC');
        $chunkSize = max(1, (int) config('daily_chain_snapshot.insert_chunk_size', 1000));

        DB::transaction(function () use (
            $checkpoint,
            $checksum,
            $chunkSize,
            $date,
            $payload,
            $publishedAt,
            $rowCount
        ): void {
            $this->checkpoint($checkpoint, 'before_delete', [
                'date' => $date,
                'row_count' => $rowCount,
                'checksum' => $checksum,
            ]);

            DB::table('daily_chain_snapshot')
                ->where('data_date', $date)
                ->delete();

            $this->checkpoint($checkpoint, 'after_delete', [
                'date' => $date,
                'row_count' => $rowCount,
                'checksum' => $checksum,
            ]);

            foreach ($payload->chunk($chunkSize) as $chunkIndex => $chunk) {
                DB::table('daily_chain_snapshot')->insert(
                    $chunk->map(fn (array $row): array => array_merge($row, [
                        'created_at' => $publishedAt,
                        'updated_at' => $publishedAt,
                    ]))->all()
                );

                $this->checkpoint($checkpoint, 'after_insert_chunk', [
                    'date' => $date,
                    'chunk_index' => $chunkIndex,
                    'chunks_total' => (int) ceil($rowCount / $chunkSize),
                ]);
            }

            $this->checkpoint($checkpoint, 'after_insert', [
                'date' => $date,
                'row_count' => $rowCount,
                'checksum' => $checksum,
            ]);

            $visible = $this->visibleRows($date);
            $visibleChecksum = $this->checksum($visible);

            if ($visible->count() !== $rowCount || ! hash_equals($checksum, $visibleChecksum)) {
                throw new RuntimeException(
                    "Daily chain snapshot verification failed for {$date}; the transaction will be rolled back."
                );
            }

            $this->checkpoint($checkpoint, 'after_verify', [
                'date' => $date,
                'row_count' => $rowCount,
                'checksum' => $checksum,
            ]);
        }, 3);

        $this->checkpoint($checkpoint, 'after_commit', [
            'date' => $date,
            'row_count' => $rowCount,
            'checksum' => $checksum,
        ]);

        return [
            'date' => $date,
            'row_count' => $rowCount,
            'checksum' => $checksum,
            'symbols' => $symbols,
            'published_at' => $publishedAt->toIso8601String(),
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function aggregate(string $date): Collection
    {
        return DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->selectRaw("
                e.symbol,
                o.data_date,
                o.expiration_id,
                SUM(CASE WHEN o.option_type='call' THEN o.open_interest ELSE 0 END) as call_oi,
                SUM(CASE WHEN o.option_type='put'  THEN o.open_interest ELSE 0 END) as put_oi,
                SUM(CASE WHEN o.option_type='call' THEN o.volume       ELSE 0 END) as call_vol,
                SUM(CASE WHEN o.option_type='put'  THEN o.volume       ELSE 0 END) as put_vol,
                SUM(o.gamma*COALESCE(o.open_interest,0)*100) as sum_gamma,
                SUM(COALESCE(o.delta,0)*COALESCE(o.open_interest,0)*100) as sum_delta,
                SUM(COALESCE(o.vega,0) *COALESCE(o.open_interest,0)*100) as sum_vega
            ")
            ->where('o.data_date', $date)
            ->groupBy('e.symbol', 'o.data_date', 'o.expiration_id')
            ->orderBy('e.symbol')
            ->orderBy('o.data_date')
            ->orderBy('o.expiration_id')
            ->get()
            ->map(static fn (object $row): array => [
                'symbol' => $row->symbol,
                'data_date' => $row->data_date,
                'expiration_id' => $row->expiration_id,
                'call_oi' => $row->call_oi,
                'put_oi' => $row->put_oi,
                'call_vol' => $row->call_vol,
                'put_vol' => $row->put_vol,
                'sum_gamma' => $row->sum_gamma,
                'sum_delta' => $row->sum_delta,
                'sum_vega' => $row->sum_vega,
            ]);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function visibleRows(string $date): Collection
    {
        return DB::table('daily_chain_snapshot')
            ->where('data_date', $date)
            ->orderBy('symbol')
            ->orderBy('data_date')
            ->orderBy('expiration_id')
            ->get(self::SNAPSHOT_COLUMNS)
            ->map(static fn (object $row): array => (array) $row);
    }

    /** @param Collection<int,array<string,mixed>> $rows */
    private function checksum(Collection $rows): string
    {
        $canonical = $rows
            ->map(fn (array $row): array => [
                'symbol' => (string) $row['symbol'],
                'data_date' => (string) $row['data_date'],
                'expiration_id' => (int) $row['expiration_id'],
                'call_oi' => (int) $row['call_oi'],
                'put_oi' => (int) $row['put_oi'],
                'call_vol' => (int) $row['call_vol'],
                'put_vol' => (int) $row['put_vol'],
                'sum_gamma' => $this->canonicalFloat($row['sum_gamma']),
                'sum_delta' => $this->canonicalFloat($row['sum_delta']),
                'sum_vega' => $this->canonicalFloat($row['sum_vega']),
            ])
            ->sortBy(static fn (array $row): string => implode('|', [
                $row['symbol'],
                $row['data_date'],
                str_pad((string) $row['expiration_id'], 20, '0', STR_PAD_LEFT),
            ]))
            ->values()
            ->all();

        return hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalFloat(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return sprintf('%.15g', (float) $value);
    }

    /** @param null|callable(string,array<string,mixed>):void $checkpoint */
    private function checkpoint(?callable $checkpoint, string $name, array $context): void
    {
        if ($checkpoint !== null) {
            $checkpoint($name, $context);
        }
    }

    private function normalizeDate(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Daily chain snapshot date must use YYYY-MM-DD.');
        }

        return $date;
    }
}
