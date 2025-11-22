<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) De-duplicate existing rows so we can add a unique index safely.
        //    Keep the lowest id per (symbol, trade_date, exp_date, strike, option_type).
        //    <=> is null-safe equality (for NULL exp_date/strike/option_type).
        DB::statement("
            DELETE c1 FROM option_live_counters c1
            JOIN option_live_counters c2
              ON c1.symbol      = c2.symbol
             AND c1.trade_date  = c2.trade_date
             AND c1.exp_date   <=> c2.exp_date
             AND c1.strike     <=> c2.strike
             AND c1.option_type<=> c2.option_type
             AND c1.id > c2.id
        ");

        // 2) Enforce "one row per symbol+day+exp+strike+type"
        Schema::table('option_live_counters', function (Blueprint $table) {
            $table->unique(
                ['symbol','trade_date','exp_date','strike','option_type'],
                'olc_symbol_day_strike_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('option_live_counters', function (Blueprint $table) {
            $table->dropUnique('olc_symbol_day_strike_type_unique');
        });
    }
};
