<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('symbol_wall_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->string('timeframe', 16)->default('30d')->index(); // e.g. 14d/30d/monthly

            $table->decimal('spot', 12, 4)->nullable();

            // EOD GEX walls
            $table->decimal('eod_put_wall', 12, 4)->nullable();
            $table->decimal('eod_call_wall', 12, 4)->nullable();
            $table->decimal('eod_put_dist_pct', 8, 4)->nullable();
            $table->decimal('eod_call_dist_pct', 8, 4)->nullable();

            // Intraday walls (for now only call wall, but leave room)
            $table->decimal('intraday_put_wall', 12, 4)->nullable();
            $table->decimal('intraday_call_wall', 12, 4)->nullable();
            $table->decimal('intraday_put_dist_pct', 8, 4)->nullable();
            $table->decimal('intraday_call_dist_pct', 8, 4)->nullable();

            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'timeframe'], 'wall_snapshots_sym_date_tf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_wall_snapshots');
    }
};
