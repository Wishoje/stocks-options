<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intraday_research_groups', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->char('sample_id', 64);
            $table->string('symbol', 16);
            $table->date('session');
            $table->string('rule_version', 64);
            $table->char('source_hash', 64);
            $table->json('original_sample');
            $table->timestamps();
            $table->unique(['owner_id', 'sample_id', 'rule_version'], 'intraday_research_episode');
            $table->index(['owner_id', 'session', 'symbol'], 'intraday_research_report');
        });
        Schema::create('intraday_research_cases', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('group_id', 64);
            $table->foreign('group_id')->references('id')->on('intraday_research_groups')->cascadeOnDelete();
            $table->string('variant', 32);
            $table->json('original_plan');
            $table->json('current_state');
            $table->string('status', 32);
            $table->timestamp('notification_recorded_at')->nullable();
            $table->json('notification_event')->nullable();
            $table->timestamp('alert_read_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'variant']);
            $table->index(['notification_recorded_at', 'alert_read_at'], 'intraday_research_alerts');
        });
        Schema::create('intraday_research_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->date('session');
            $table->timestamp('bar_at');
            $table->json('ohlc');
            $table->timestamp('first_recorded_at');
            $table->char('evidence_hash', 64);
            $table->json('revision')->nullable();
            $table->unique(['owner_id', 'symbol', 'session', 'bar_at'], 'intraday_research_bar_identity');
        });
        Schema::create('intraday_research_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->date('session');
            $table->unsignedBigInteger('last_checkpoint_id')->default(0);
            $table->timestamps();
            $table->unique(['owner_id', 'symbol', 'session'], 'intraday_research_cursor_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_research_cases');
        Schema::dropIfExists('intraday_research_groups');
        Schema::dropIfExists('intraday_research_bars');
        Schema::dropIfExists('intraday_research_cursors');
    }
};
