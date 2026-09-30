<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wall_observations', fn (Blueprint $table) => $table->index(['symbol', 'dataset', 'analysis_session', 'observed_at'], 'wall_obs_intraday_session'));
    }

    public function down(): void
    {
        Schema::table('wall_observations', fn (Blueprint $table) => $table->dropIndex('wall_obs_intraday_session'));
    }
};
