<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVolumeToOptionChainsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('option_chains', function (Blueprint $table) {
            $table->bigInteger('volume')->nullable()->after('open_interest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('option_chains', function (Blueprint $table) {
            $table->dropColumn('volume');
        });
    }
}
