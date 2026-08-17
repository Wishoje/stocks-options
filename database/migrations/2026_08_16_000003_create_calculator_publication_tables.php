<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculator_symbol_generations', function (Blueprint $table): void {
            $table->string('symbol', 32)->primary();
            $table->unsignedBigInteger('last_generation')->default(0);
            $table->timestamps(6);
        });

        Schema::create('calculator_publication_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 32);
            $table->unsignedBigInteger('generation');
            $table->string('scope', 32);
            $table->string('purpose', 64);
            $table->char('owner_key', 64);
            $table->string('owner_reference', 191);
            $table->uuid('work_run_id')->nullable();
            $table->date('requested_expiry')->nullable();
            $table->string('status', 32);
            $table->boolean('discovery_terminal')->default(false);
            $table->boolean('discovery_capped')->default(false);
            $table->string('catalog_source', 64)->nullable();
            $table->dateTime('catalog_source_asof', 6)->nullable();
            $table->date('discovery_horizon')->nullable();
            $table->char('expected_expirations_hash', 64)->nullable();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('expected_frozen_at', 6)->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('heartbeat_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['symbol', 'generation'], 'calc_runs_symbol_generation_unique');
            $table->unique(['symbol', 'scope', 'owner_key'], 'calc_runs_owner_unique');
            $table->unique('work_run_id', 'calc_runs_work_run_unique');
            $table->index(['symbol', 'status'], 'calc_runs_symbol_status_idx');
        });

        Schema::create('calculator_expiry_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->string('symbol', 32);
            $table->unsignedBigInteger('generation');
            $table->date('expiration');
            $table->string('chain_source', 64);
            $table->dateTime('source_asof', 6);
            $table->dateTime('snapshot_at', 6);
            $table->unsignedInteger('row_count');
            $table->char('content_hash', 64);
            $table->dateTime('created_at', 6);

            $table->unique(['run_id', 'expiration'], 'calc_expiry_pubs_run_exp_unique');
            $table->index(
                ['symbol', 'expiration', 'generation'],
                'calc_expiry_pubs_lookup_idx'
            );
        });

        Schema::create('calculator_expiry_publication_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('publication_id');
            $table->char('contract_key', 64);
            $table->string('ticker', 128)->nullable();
            $table->string('type', 8);
            $table->decimal('strike', 18, 6);
            $table->decimal('bid', 18, 6)->nullable();
            $table->decimal('ask', 18, 6)->nullable();
            $table->decimal('mid', 18, 6)->nullable();
            $table->decimal('implied_volatility', 18, 10)->nullable();

            $table->unique(
                ['publication_id', 'contract_key'],
                'calc_expiry_pub_rows_contract_unique'
            );
            $table->index('publication_id', 'calc_expiry_pub_rows_pub_idx');
        });

        Schema::create('calculator_run_expirations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('run_id');
            $table->string('symbol', 32);
            $table->date('expiration');
            $table->string('catalog_source', 64);
            $table->unsignedSmallInteger('catalog_precedence')->default(100);
            $table->string('readiness', 16)->default('pending');
            $table->uuid('publication_id')->nullable();
            $table->dateTime('source_asof', 6)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('discovered_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('ready_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['run_id', 'expiration'], 'calc_run_expirations_unique');
            $table->index(['symbol', 'expiration'], 'calc_run_expirations_lookup_idx');
            $table->index('publication_id', 'calc_run_expirations_pub_idx');
        });

        Schema::create('calculator_catalog_heads', function (Blueprint $table): void {
            $table->string('symbol', 32)->primary();
            $table->uuid('current_run_id');
            $table->unsignedBigInteger('current_generation');
            $table->dateTime('current_source_asof', 6);
            $table->uuid('previous_run_id')->nullable();
            $table->unsignedBigInteger('previous_generation')->nullable();
            $table->dateTime('previous_source_asof', 6)->nullable();
            $table->unsignedBigInteger('max_generation');
            $table->dateTime('max_source_asof', 6);
            $table->timestamps(6);
        });

        Schema::create('calculator_expiry_heads', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('symbol', 32);
            $table->date('expiration');
            $table->uuid('current_publication_id');
            $table->unsignedBigInteger('current_generation');
            $table->dateTime('current_source_asof', 6);
            $table->uuid('previous_publication_id')->nullable();
            $table->unsignedBigInteger('previous_generation')->nullable();
            $table->dateTime('previous_source_asof', 6)->nullable();
            $table->unsignedBigInteger('max_generation');
            $table->dateTime('max_source_asof', 6);
            $table->timestamps(6);

            $table->unique(['symbol', 'expiration'], 'calc_expiry_heads_symbol_exp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_expiry_heads');
        Schema::dropIfExists('calculator_catalog_heads');
        Schema::dropIfExists('calculator_run_expirations');
        Schema::dropIfExists('calculator_expiry_publication_rows');
        Schema::dropIfExists('calculator_expiry_publications');
        Schema::dropIfExists('calculator_publication_runs');
        Schema::dropIfExists('calculator_symbol_generations');
    }
};
