<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('option_expirations', function (Blueprint $t) {
            $t->index('symbol', 'option_expirations_symbol_idx');
        });
    }
    public function down(): void {
        Schema::table('option_expirations', function (Blueprint $t) {
            $t->dropIndex('option_expirations_symbol_idx');
        });
    }
};
