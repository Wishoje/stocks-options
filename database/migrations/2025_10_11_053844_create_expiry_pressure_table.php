<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('expiry_pressure', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->index();
            $table->date('data_date')->index();        // trading date of snapshot
            $table->date('exp_date')->index();         // which expiry this row summarizes
            $table->unsignedTinyInteger('pin_score');  // 0..100
            $table->json('clusters_json');             // array of clusters (strike, width, density, dist, score)
            $table->decimal('max_pain', 12, 4)->nullable(); // price that minimizes total option payoff
            $table->timestamps();

            $table->unique(['symbol','data_date','exp_date']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('expiry_pressure');
    }
};
