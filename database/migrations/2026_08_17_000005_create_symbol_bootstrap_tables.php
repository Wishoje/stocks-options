<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbol_bootstrap_runs', function (Blueprint $table): void {
            $table->uuid('work_run_id')->primary();
            $table->string('symbol', 32);
            $table->string('purpose', 64);
            $table->date('session_date');
            $table->unsignedBigInteger('generation');
            $table->string('status', 24)->default('preparing');
            $table->string('current_phase', 32)->nullable();
            $table->unsignedSmallInteger('fast_horizon_days');
            $table->unsignedSmallInteger('fill_horizon_days');
            $table->date('catalog_horizon_start');
            $table->date('catalog_horizon_end');
            $table->string('catalog_source', 64)->nullable();
            $table->dateTime('catalog_source_asof', 6)->nullable();
            $table->char('expected_expirations_hash', 64)->nullable();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('fast_expected_count')->default(0);
            $table->unsignedInteger('fast_ready_count')->default(0);
            $table->unsignedInteger('fill_ready_count')->default(0);
            $table->dateTime('catalog_frozen_at', 6)->nullable();
            $table->dateTime('heartbeat_at', 6);
            $table->dateTime('full_ready_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('work_run_id', 'sym_boot_runs_work_run_fk')
                ->references('id')
                ->on('work_runs')
                ->cascadeOnDelete();
            $table->unique(
                ['symbol', 'purpose', 'session_date', 'generation'],
                'sym_boot_runs_scope_generation_unique'
            );
            $table->index(['symbol', 'session_date', 'status'], 'sym_boot_runs_status_idx');
        });

        Schema::create('symbol_bootstrap_expirations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('work_run_id');
            $table->date('expiration_date');
            $table->boolean('fast_scope')->default(false);
            $table->dateTime('fast_ready_at', 6)->nullable();
            $table->dateTime('fill_ready_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('work_run_id', 'sym_boot_exp_work_run_fk')
                ->references('work_run_id')
                ->on('symbol_bootstrap_runs')
                ->cascadeOnDelete();
            $table->unique(
                ['work_run_id', 'expiration_date'],
                'sym_boot_exp_run_date_unique'
            );
            $table->index(['work_run_id', 'fast_scope'], 'sym_boot_exp_fast_scope_idx');
        });

        Schema::create('symbol_bootstrap_phases', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('work_run_id');
            $table->string('phase', 32);
            $table->string('status', 16);
            $table->string('queue_connection', 32)->default('redis');
            $table->string('queue', 64);
            $table->uuid('delivery_token')->nullable();
            $table->uuid('orchestration_token')->nullable();
            $table->unsignedInteger('orchestration_attempt')->default(0);
            $table->dateTime('orchestration_reserved_at', 6)->nullable();
            $table->dateTime('orchestration_dispatched_at', 6)->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->dateTime('dispatching_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->dateTime('next_dispatch_at', 6)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('heartbeat_at', 6)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('retry_not_before', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->json('outcome')->nullable();
            $table->timestamps(6);

            $table->foreign('work_run_id', 'sym_boot_phases_work_run_fk')
                ->references('work_run_id')
                ->on('symbol_bootstrap_runs')
                ->cascadeOnDelete();
            $table->unique(['work_run_id', 'phase'], 'sym_boot_phases_run_phase_unique');
            $table->index(['status', 'next_dispatch_at'], 'sym_boot_phases_dispatch_idx');
            $table->index(['status', 'lease_expires_at'], 'sym_boot_phases_lease_idx');
        });

        Schema::create('symbol_bootstrap_heads', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('symbol', 32);
            $table->date('session_date');
            $table->string('purpose', 64);
            $table->uuid('current_work_run_id');
            $table->unsignedBigInteger('current_generation');
            $table->dateTime('current_full_ready_at', 6);
            $table->uuid('previous_work_run_id')->nullable();
            $table->unsignedBigInteger('previous_generation')->nullable();
            $table->dateTime('previous_full_ready_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('current_work_run_id', 'sym_boot_heads_current_fk')
                ->references('id')
                ->on('work_runs')
                ->cascadeOnDelete();
            $table->foreign('previous_work_run_id', 'sym_boot_heads_previous_fk')
                ->references('id')
                ->on('work_runs')
                ->nullOnDelete();
            $table->unique(
                ['symbol', 'session_date', 'purpose'],
                'sym_boot_heads_symbol_session_purpose_unique'
            );
            $table->index(['symbol', 'current_generation'], 'sym_boot_heads_generation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_bootstrap_heads');
        Schema::dropIfExists('symbol_bootstrap_phases');
        Schema::dropIfExists('symbol_bootstrap_expirations');
        Schema::dropIfExists('symbol_bootstrap_runs');
    }
};
