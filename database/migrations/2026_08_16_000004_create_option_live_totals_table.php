<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_live_totals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('symbol', 12);
            $table->date('trade_date')->index('option_live_totals_trade_date_idx');
            $table->unsignedBigInteger('call_volume')->default(0);
            $table->unsignedBigInteger('put_volume')->default(0);
            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('premium_usd', 18, 4)->nullable();
            $table->dateTime('asof', 6)->nullable();
            $table->dateTime('source_updated_at', 6)->nullable();
            $table->unsignedBigInteger('source_row_id');
            $table->char('freshness_key', 64);
            $table->timestamps(6);

            $table->unique(
                ['symbol', 'trade_date'],
                'option_live_totals_symbol_trade_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_live_totals');
    }
};
