<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swing_research_groups', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('horizon', 16);
            $table->date('source_date');
            $table->string('research_version', 64);
            $table->char('input_hash', 64);
            $table->json('context');
            $table->timestamps();
            $table->unique(['owner_id', 'symbol', 'horizon', 'source_date', 'research_version'], 'swing_research_cohort');
            $table->index(['owner_id', 'horizon', 'source_date'], 'swing_research_report');
        });
        Schema::create('swing_research_cases', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('group_id', 64);
            $table->foreign('group_id')->references('id')->on('swing_research_groups')->cascadeOnDelete();
            $table->string('variant', 24);
            $table->json('original_plan');
            $table->json('current_state');
            $table->string('status', 24);
            $table->date('reviewed_through');
            $table->timestamp('signal_recorded_at')->nullable();
            $table->timestamp('alert_read_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'variant']);
            $table->index(['signal_recorded_at', 'alert_read_at'], 'swing_research_alerts');
        });
        Schema::create('swing_research_daily_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->date('trade_date');
            $table->json('ohlc');
            $table->char('evidence_hash', 64);
            $table->timestamp('first_recorded_at');
            $table->json('revision')->nullable();
            $table->unique(['owner_id', 'symbol', 'trade_date'], 'swing_research_daily_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_research_cases');
        Schema::dropIfExists('swing_research_groups');
        Schema::dropIfExists('swing_research_daily_bars');
    }
};
