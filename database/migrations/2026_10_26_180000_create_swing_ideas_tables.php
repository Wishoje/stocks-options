<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swing_ideas', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('symbol', 16);
            $table->string('horizon', 16);
            $table->string('rule_version', 64);
            $table->date('source_date');
            $table->string('status', 24);
            $table->date('reviewed_through');
            $table->char('source_hash', 64);
            $table->json('original_plan');
            $table->json('current_state');
            $table->timestamps();
            $table->index(['symbol', 'horizon', 'rule_version', 'source_date'], 'swing_symbol_horizon_date');
            $table->index(['symbol', 'status'], 'swing_symbol_status');
        });
        Schema::create('swing_idea_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('idea_id', 64);
            $table->date('data_date');
            $table->char('evidence_hash', 64);
            $table->json('assessment');
            $table->timestamp('recorded_at');
            $table->unique(['idea_id', 'data_date']);
            $table->foreign('idea_id')->references('id')->on('swing_ideas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_idea_reviews');
        Schema::dropIfExists('swing_ideas');
    }
};
