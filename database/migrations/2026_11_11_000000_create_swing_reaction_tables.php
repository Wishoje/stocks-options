<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swing_reaction_ideas', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('symbol', 16);
            $table->string('horizon', 16);
            $table->string('rule_version', 64);
            $table->date('source_date');
            $table->string('status', 32);
            $table->longText('original_plan');
            $table->longText('current_state');
            $table->timestamps();
            $table->index(['symbol', 'status'], 'swing_reaction_symbol_status');
            $table->index(['rule_version', 'horizon', 'status'], 'swing_reaction_version_horizon');
            $table->index(['source_date', 'symbol'], 'swing_reaction_source');
        });
        Schema::create('swing_reaction_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('idea_id', 64);
            $table->dateTime('observed_at');
            $table->dateTime('recorded_at');
            $table->longText('state_json');
            $table->foreign('idea_id')->references('id')->on('swing_reaction_ideas');
            $table->index(['idea_id', 'recorded_at'], 'swing_reaction_review_time');
        });
        Schema::create('swing_reaction_prices', function (Blueprint $table) {
            $table->string('symbol', 16);
            $table->dateTime('bar_at');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->dateTime('first_recorded_at');
            $table->string('evidence_hash', 64);
            $table->primary(['symbol', 'bar_at']);
            $table->index('bar_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_reaction_prices');
        Schema::dropIfExists('swing_reaction_reviews');
        Schema::dropIfExists('swing_reaction_ideas');
    }
};
