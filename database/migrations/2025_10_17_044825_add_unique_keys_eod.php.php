<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {

        Schema::table('option_expirations', function (Blueprint $table) {
            $table->unique(['symbol','expiration_date'], 'option_expirations_symbol_date_unique');
        });

        Schema::table('option_chain_data', function (Blueprint $table) {
            $table->unique(['expiration_id','data_date','option_type','strike'], 'ocd_exp_date_type_strike_unique');
        });
    }

    public function down(): void
    {
        Schema::table('option_expirations', function (Blueprint $table) {
            $table->dropUnique('option_expirations_symbol_date_unique');
        });
        Schema::table('option_chain_data', function (Blueprint $table) {
            $table->dropUnique('ocd_exp_date_type_strike_unique');
        });
    }
};
