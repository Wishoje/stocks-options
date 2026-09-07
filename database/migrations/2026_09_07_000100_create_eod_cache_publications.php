<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eod_cache_publication_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('epoch', 64);
            $table->unsignedBigInteger('cutover_microseconds');
            $table->timestamp('prepared_at', 6);
        });

        Schema::create('eod_cache_publications', function (Blueprint $table): void {
            $table->string('domain', 32);
            $table->string('symbol', 32)->collation('utf8mb4_bin');
            $table->text('version');
            $table->unsignedBigInteger('issued_at_microseconds');
            $table->timestamp('published_at', 6);
            $table->primary(['domain', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eod_cache_publications');
        Schema::dropIfExists('eod_cache_publication_state');
    }
};
