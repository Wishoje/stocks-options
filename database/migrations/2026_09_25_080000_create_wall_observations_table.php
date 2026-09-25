<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wall_observations', function (Blueprint $table) {
            $table->id();
            $table->char('content_hash', 64)->unique();
            $table->char('scope_key', 64)->index();
            $table->string('symbol', 16);
            $table->string('dataset', 40);
            $table->string('schema_version', 48);
            $table->string('model_version', 48);
            $table->string('observation_kind', 24)->default('audit_capture');
            $table->date('analysis_session')->nullable();
            $table->date('source_date')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('recorded_at');
            $table->string('quality_state', 24);
            $table->boolean('historical_outcome_eligible')->default(false);
            $table->unsignedInteger('payload_bytes');
            $table->longText('payload_json');
            $table->index(['symbol', 'dataset', 'source_date'], 'wall_obs_symbol_dataset_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wall_observations');
    }
};
