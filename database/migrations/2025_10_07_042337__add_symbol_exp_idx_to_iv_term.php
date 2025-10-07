<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('iv_term', function (Blueprint $t) {
            $t->index(['symbol','exp_date'], 'ivterm_symbol_exp_idx');
        });
    }
    public function down(): void {
        Schema::table('iv_term', function (Blueprint $t) {
            $t->dropIndex('ivterm_symbol_exp_idx');
        });
    }
};
