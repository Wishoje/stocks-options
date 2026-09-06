<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intraday_refresh_states', function (Blueprint $table): void {
            $table->string('symbol', 16);
            $table->date('trade_date');
            $table->timestamp('source_asof', 6)->nullable();
            $table->timestamp('captured_at', 6)->nullable();
            $table->timestamp('received_at', 6)->nullable();
            $table->timestamp('ingestion_completed_at', 6)->nullable();
            $table->uuid('work_run_id')->nullable();
            $table->unsignedSmallInteger('expiry_count')->default(0);
            $table->primary(['symbol', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_refresh_states');
    }
};
