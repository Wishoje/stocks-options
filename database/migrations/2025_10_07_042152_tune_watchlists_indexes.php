<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('watchlists', function (Blueprint $t) {
            // if you didn’t add these earlier:
            $t->index('user_id', 'watchlists_user_idx');
            $t->index('symbol',  'watchlists_symbol_idx');

            // prevent duplicates per user
            $t->unique(['user_id','symbol','timeframe'], 'watchlists_user_symbol_tf_unique');
        });
    }
    public function down(): void {
        Schema::table('watchlists', function (Blueprint $t) {
            $t->dropUnique('watchlists_user_symbol_tf_unique');
            $t->dropIndex('watchlists_user_idx');
            $t->dropIndex('watchlists_symbol_idx');
        });
    }
};
