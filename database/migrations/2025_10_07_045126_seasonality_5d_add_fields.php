<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('seasonality_5d', function (Blueprint $t) {
            $t->unsignedSmallInteger('lookback_years')->nullable()->after('z');
            $t->unsignedInteger('lookback_days')->nullable()->after('lookback_years');
            $t->unsignedTinyInteger('window_days')->nullable()->after('lookback_days');

            // helpful composite index for querying depth by symbol
            $t->index(['symbol','lookback_years','lookback_days','data_date'], 'season5d_depth_idx');
        });

        // Backfill from JSON meta. Keep the historical MySQL statement in
        // production, but use decoded values for SQLite test databases where
        // JSON_UNQUOTE is unavailable.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::table('seasonality_5d')
                ->select(['id', 'meta'])
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $meta = json_decode((string) ($row->meta ?? ''), true);
                        if (!is_array($meta)) {
                            continue;
                        }

                        DB::table('seasonality_5d')
                            ->where('id', $row->id)
                            ->update([
                                'lookback_years' => $this->nullableInteger($meta['lookback_years'] ?? null),
                                'lookback_days' => $this->nullableInteger($meta['lookback_days'] ?? null),
                                'window_days' => $this->nullableInteger($meta['window_days'] ?? null),
                            ]);
                    }
                });
        } else {
            DB::statement("
                UPDATE seasonality_5d
                SET
                  lookback_years = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.lookback_years')), ''),
                  lookback_days  = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.lookback_days')),  ''),
                  window_days    = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.window_days')),   '')
            ");
        }
    }

    public function down(): void {
        Schema::table('seasonality_5d', function (Blueprint $t) {
            $t->dropIndex('season5d_depth_idx');
            $t->dropColumn(['lookback_years','lookback_days','window_days']);
        });
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value)
            ? null
            : (int) $value;
    }
};
