<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('underlying_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->index();
            $table->date('price_date')->index();
            $table->decimal('open', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('close', 12, 4)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->timestamps();
            $table->unique(['symbol','price_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('underlying_prices');
    }
};
