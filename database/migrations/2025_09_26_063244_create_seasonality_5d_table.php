<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('seasonality_5d', function (Blueprint $t) {
            $t->id();
            $t->string('symbol', 16)->index();
            $t->date('data_date')->index();   // trading date you computed on
            // avg forward returns (fractions, e.g. 0.004 = 0.4%)
            $t->float('d1')->nullable();
            $t->float('d2')->nullable();
            $t->float('d3')->nullable();
            $t->float('d4')->nullable();
            $t->float('d5')->nullable();
            $t->float('cum5')->nullable();    // cumulative 5d return
            $t->float('z')->nullable();       // z vs unconditional 5d dist
            $t->json('meta')->nullable();     // counts, window, lookback years, etc.
            $t->timestamps();

            $t->unique(['symbol','data_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('seasonality_5d');
    }
};
