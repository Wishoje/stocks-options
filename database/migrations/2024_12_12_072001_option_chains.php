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
        Schema::create('option_chains', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);              // e.g. "SPY", "IWM", "QQQ"
            $table->date('data_date');                 // date for which data is pulled (e.g. today's date)
            $table->string('option_symbol');           // the option's unique identifier, if provided by API
            $table->string('option_type', 4);          // "call" or "put"
            $table->decimal('strike', 8, 2);
            $table->date('expiration_date');
            $table->bigInteger('open_interest')->nullable();
            $table->decimal('gamma', 12, 8)->nullable();  // If gamma is available directly
            $table->decimal('delta', 12, 8)->nullable();  // If needed for computation
            $table->decimal('iv', 12, 8)->nullable();     // Implied volatility
            $table->decimal('underlying_price', 12, 4)->nullable(); // Price of the underlying at fetch time
            $table->timestamps();
            $table->index(['symbol', 'data_date', 'expiration_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
