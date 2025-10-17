<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            // If timeframe exists, keep it nullable but unused.
            if (Schema::hasColumn('watchlists', 'timeframe')) {
                $table->string('timeframe')->nullable()->change();
            }
        });

        // Add composite unique index for (user_id, symbol)
        Schema::table('watchlists', function (Blueprint $table) {
            $table->unique(['user_id', 'symbol'], 'watchlists_user_symbol_unique');
        });
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->dropUnique('watchlists_user_symbol_unique');
        });
    }
};
