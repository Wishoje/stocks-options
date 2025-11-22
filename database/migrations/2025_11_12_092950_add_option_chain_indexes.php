<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // option_chain_data
        Schema::table('option_chain_data', function (Blueprint $table) {
            // Unique key used by upsert:
            // (Make sure these columns exist with matching types)
            $table->unique(['expiration_id', 'data_date', 'option_type', 'strike'], 'u_chain_exp_date_type_strike');

            // Helpful lookups for your queries
            $table->index(['data_date', 'expiration_id'], 'idx_chain_date_exp');
        });

        // option_expirations
        Schema::table('option_expirations', function (Blueprint $table) {
            $table->index(['symbol', 'expiration_date'], 'idx_exp_symbol_date');
        });
    }

    public function down(): void
    {
        Schema::table('option_chain_data', function (Blueprint $table) {
            $table->dropUnique('u_chain_exp_date_type_strike');
            $table->dropIndex('idx_chain_date_exp');
        });

        Schema::table('option_expirations', function (Blueprint $table) {
            $table->dropIndex('idx_exp_symbol_date');
        });
    }
};
