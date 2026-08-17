<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('slot_key', 64);
            $table->unsignedBigInteger('generation');
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->char('scope_hash', 64);
            $table->string('status', 24);
            $table->string('queue_connection', 32)->default('redis');
            $table->string('queue', 64)->nullable();
            $table->json('parameters')->nullable();
            $table->uuid('delivery_token')->nullable();
            $table->uuid('orchestration_token')->nullable();
            $table->timestamp('orchestration_reserved_at')->nullable();
            $table->timestamp('orchestration_dispatched_at')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('orchestration_attempt')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('dispatching_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('reusable_until')->nullable();
            $table->timestamp('retry_not_before')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamps();

            $table->unique(['slot_key', 'generation']);
            $table->index(['kind', 'symbol', 'created_at']);
            $table->index(['status', 'next_dispatch_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->index(['scope_hash', 'created_at']);
        });

        Schema::create('work_run_slots', function (Blueprint $table): void {
            $table->char('key', 64)->primary();
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->foreignUuid('current_run_id')
                ->nullable()
                ->constrained('work_runs')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_run_slots');
        Schema::dropIfExists('work_runs');
    }
};
