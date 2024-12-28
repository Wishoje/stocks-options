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
        Schema::create('option_chain_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expiration_id');  // FK references option_expirations
            $table->date('data_date');                    // date of the data (e.g. today's date)
            $table->string('option_type', 4);             // "call" or "put"
            $table->decimal('strike', 8, 2);
            $table->bigInteger('open_interest')->nullable();
            $table->bigInteger('volume')->nullable();       // if you store volume
            $table->decimal('gamma', 12, 8)->nullable();
            $table->decimal('delta', 12, 8)->nullable();
            $table->decimal('iv', 12, 8)->nullable();       // implied volatility
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->timestamps();
        
            $table->foreign('expiration_id')
                ->references('id')->on('option_expirations')
                ->onDelete('cascade');
        
            // Common indexes
            $table->index(['expiration_id', 'data_date']);
            $table->index(['strike', 'option_type']);
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_chain_data');
    }
};
