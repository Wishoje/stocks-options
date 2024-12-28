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
        Schema::create('option_expirations', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);               // e.g. "SPY", "IWM", "QQQ"
            $table->date('expiration_date');            // e.g. "2024-01-19"
            $table->timestamps();
        
            // If you want to ensure uniqueness, you can do:
            $table->unique(['symbol', 'expiration_date'], 'symbol_expiration_unique');
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_expirations');
    }
};
