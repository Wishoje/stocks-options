<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('daily_chain_snapshot', function (Blueprint $t) {
            $t->index(['symbol','data_date'], 'dcs_symbol_day_idx');
        });
    }
    public function down(): void {
        Schema::table('daily_chain_snapshot', function (Blueprint $t) {
            $t->dropIndex('dcs_symbol_day_idx');
        });
    }
};
