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

        // Backfill from JSON meta (MySQL JSON_EXTRACT)
        DB::statement("
            UPDATE seasonality_5d
            SET
              lookback_years = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.lookback_years')), ''),
              lookback_days  = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.lookback_days')),  ''),
              window_days    = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.window_days')),   '')
        ");
    }

    public function down(): void {
        Schema::table('seasonality_5d', function (Blueprint $t) {
            $t->dropIndex('season5d_depth_idx');
            $t->dropColumn(['lookback_years','lookback_days','window_days']);
        });
    }
};
