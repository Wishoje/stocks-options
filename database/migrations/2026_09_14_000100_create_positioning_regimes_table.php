<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positioning_regimes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('data_date');
            $table->unsignedSmallInteger('scope_days');
            $table->double('strength')->nullable();
            $table->tinyInteger('gamma_sign')->nullable();
            $table->double('net_gamma')->nullable();
            $table->double('absolute_gamma')->nullable();
            $table->json('source_meta_json')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'data_date', 'scope_days'], 'positioning_regimes_scope_unique');
            $table->index(['data_date', 'scope_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positioning_regimes');
    }
};
