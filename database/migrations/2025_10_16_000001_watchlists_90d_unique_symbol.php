<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Normalize existing rows to 90d timeframe since UI no longer selects it
        try {
            DB::table('watchlists')->update(['timeframe' => '90d']);
        } catch (\Throwable $e) {
            // ignore if table missing in some envs
        }

        // Cleanup duplicates by (user_id, symbol), keep the lowest id per group
        try {
            $dupes = DB::table('watchlists')
                ->select('user_id','symbol', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
                ->groupBy('user_id','symbol')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($dupes as $d) {
                DB::table('watchlists')
                    ->where('user_id', $d->user_id)
                    ->where('symbol', $d->symbol)
                    ->where('id', '!=', $d->keep_id)
                    ->delete();
            }
        } catch (\Throwable $e) {}

        Schema::table('watchlists', function (Blueprint $table) {
            // Add unique index on (user_id, symbol)
            if (! $this->hasIndex('watchlists', 'watchlists_user_id_symbol_unique')) {
                $table->unique(['user_id','symbol']);
            }
        });

        // Best-effort: set default to '90d' without requiring doctrine/dbal
        try {
            DB::statement("ALTER TABLE watchlists MODIFY timeframe VARCHAR(255) NOT NULL DEFAULT '90d'");
        } catch (\Throwable $e) {
            // ignore if not supported in the current driver
        }
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            // Drop unique index if exists (name may vary by driver)
            try { $table->dropUnique('watchlists_user_id_symbol_unique'); } catch (\Throwable $e) {}
        });

        // revert default best-effort
        try {
            DB::statement("ALTER TABLE watchlists MODIFY timeframe VARCHAR(255) NOT NULL DEFAULT '14d'");
        } catch (\Throwable $e) {}
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $conn = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $conn->listTableIndexes($table);
            return array_key_exists($indexName, $indexes);
        } catch (\Throwable $e) {
            // Fallback: assume index missing so we attempt to add
            return false;
        }
    }
};

