<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('option_chain_data', function (Blueprint $t) {
            // already have index(['expiration_id','data_date']) in your base migration — keep it.

            // Cover “per day per strike per type” reads (groupBy/aggregates on exact date):
            $t->index(['expiration_id','data_date','option_type','strike'], 'ocd_exp_date_type_strike_idx');

            // Helpful for strike-based scans irrespective of date (used in walls/HVL build):
            $t->index(['expiration_id','strike','option_type'], 'ocd_exp_strike_type_idx');

            // If you frequently filter by data_date only for a set of expiries, this helps the planner:
            $t->index(['data_date','expiration_id'], 'ocd_date_exp_idx');
        });
    }
    public function down(): void {
        Schema::table('option_chain_data', function (Blueprint $t) {
            $t->dropIndex('ocd_exp_date_type_strike_idx');
            $t->dropIndex('ocd_exp_strike_type_idx');
            $t->dropIndex('ocd_date_exp_idx');
        });
    }
};
