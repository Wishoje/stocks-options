<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eod_snapshot_states', function (Blueprint $table): void {
            $table->string('symbol', 16)->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('certified_revision')->nullable();
            $table->string('certified_version', 128)->nullable();
            $table->unsignedBigInteger('certified_issued_at_microseconds')->nullable();
            $table->timestamp('certified_at', 6)->nullable();
            $table->timestamp('mutated_at', 6)->nullable();
        });
        Schema::create('eod_snapshot_mutations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 16);
            $table->unsignedBigInteger('revision');
            $table->char('scope_hash', 64);
            $table->char('recovery_key', 64)->nullable()->unique();
            $table->string('status', 16);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->unique(['symbol', 'revision'], 'eod_mutations_symbol_revision_unique');
            $table->index(['symbol', 'status', 'scope_hash'], 'eod_mutations_pending_scope_index');
        });
        Schema::create('eod_snapshot_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 16);
            $table->unsignedBigInteger('revision');
            $table->string('cache_version', 128);
            $table->char('policy_hash', 64);
            $table->date('anchor_date');
            $table->json('policy');
            $table->json('facts');
            $table->char('facts_sha256', 64);
            $table->timestamp('built_at', 6);
            $table->unique(['symbol', 'revision', 'cache_version', 'policy_hash'], 'eod_manifests_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eod_snapshot_manifests');
        Schema::dropIfExists('eod_snapshot_mutations');
        Schema::dropIfExists('eod_snapshot_states');
    }
};
