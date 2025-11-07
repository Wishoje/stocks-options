<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_create_option_snapshots_table.php
    public function up()
    {
        Schema::create('option_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('ticker');
            $table->string('type'); // call or put
            $table->decimal('strike', 12, 2);
            $table->date('expiry');
            $table->decimal('bid', 10, 2);
            $table->decimal('ask', 10, 2);
            $table->decimal('mid', 10, 2);
            $table->decimal('underlying_price', 12, 2);
            $table->timestamp('fetched_at')->useCurrent();
            $table->unique(['ticker', 'fetched_at']);
            $table->index(['symbol', 'expiry']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_snapshots');
    }
};
