<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('option_live_counters')) {
        Schema::create('option_live_counters', function (Blueprint $t) {
                $t->id();
                $t->string('symbol', 12)->index();          // underlying, e.g. SPY
                $t->date('trade_date')->index();            // session date (ET)
                $t->string('exp_date', 10)->nullable();     // optional: for by-exp aggregations
                $t->decimal('strike', 12, 4)->nullable();   // null for totals rows
                $t->enum('option_type', ['call','put'])->nullable(); // null for totals rows
                $t->bigInteger('volume')->default(0);       // cumulative day volume
                $t->decimal('premium_usd', 18, 4)->nullable(); // optional estimator
                $t->timestamp('asof')->nullable();          // last update from Polygon (delayed)
                $t->timestamps();

                $t->unique(['symbol','trade_date','exp_date','strike','option_type']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_live_counters');
    }
};
