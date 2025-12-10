<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('underlying_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index();
            $table->string('source')->nullable(); // e.g. 'massive'
            $table->decimal('last_price', 14, 6);
            $table->decimal('prev_close', 14, 6)->nullable();
            $table->timestamp('asof')->index();   // when quote was valid (UTC)
            $table->timestamps();

            $table->unique('symbol'); // one “current” row per symbol
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('underlying_quotes');
    }
};
