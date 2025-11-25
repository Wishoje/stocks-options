<?php

// database/migrations/2025_01_01_000000_create_hot_option_symbols_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hot_option_symbols', function (Blueprint $table) {
            $table->id();
            $table->date('trade_date')->index();
            $table->string('symbol', 16)->index();
            $table->unsignedInteger('rank')->index(); // 1 = hottest

            $table->unsignedBigInteger('total_volume')->nullable();
            $table->unsignedBigInteger('call_volume')->nullable();
            $table->unsignedBigInteger('put_volume')->nullable();
            $table->decimal('put_call_ratio', 8, 4)->nullable();
            $table->decimal('last_price', 16, 4)->nullable();

            $table->string('source', 32)->default('steadyapi');
            $table->json('payload')->nullable(); // raw vendor row if you want

            $table->timestamps();

            $table->unique(['trade_date', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hot_option_symbols');
    }
};
