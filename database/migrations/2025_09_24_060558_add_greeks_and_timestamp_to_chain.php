<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('option_chain_data', function (Blueprint $t) {
            if (!Schema::hasColumn('option_chain_data','delta')) {
                $t->float('delta')->nullable()->after('gamma');
            }
            if (!Schema::hasColumn('option_chain_data','vega')) {
                $t->float('vega')->nullable()->after('delta');
            }
            if (!Schema::hasColumn('option_chain_data','data_timestamp')) {
                $t->timestamp('data_timestamp')->nullable()->after('underlying_price');
            }
        });
    }
    public function down(): void {
        Schema::table('option_chain_data', function (Blueprint $t) {
            if (Schema::hasColumn('option_chain_data','vega')) $t->dropColumn('vega');
            if (Schema::hasColumn('option_chain_data','data_timestamp')) $t->dropColumn('data_timestamp');
            // keep delta if you were already using it
        });
    }
};
