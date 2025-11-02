<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('intraday_option_volumes', function (Blueprint $table) {

            $table->id();

            // underlying symbol (SPY, QQQ, etc.)
            $table->string('symbol', 16)->index();

            // option ticker from API e.g. "O:SPY251031C00370000"
            $table->string('contract_symbol', 32)->index();

            // call / put
            $table->enum('contract_type', ['call', 'put'])->index();

            // yyyy-mm-dd
            $table->date('expiration_date')->index();

            // strike as decimal, 3-4 decimal places is enough
            $table->decimal('strike_price', 12, 4)->index();

            // per-contract live stats at this capture moment
            $table->unsignedBigInteger('volume')->nullable();         // today's traded volume so far
            $table->unsignedBigInteger('open_interest')->nullable();  // OI from snapshot (EOD-ish)

            $table->decimal('implied_volatility', 12, 6)->nullable();

            // greeks
            $table->decimal('delta', 16, 10)->nullable();
            $table->decimal('gamma', 16, 10)->nullable();
            $table->decimal('theta', 16, 10)->nullable();
            $table->decimal('vega', 16, 10)->nullable();

            // last trade / mark info (optional but useful for UI)
            $table->decimal('last_price', 16, 6)->nullable();     // close in that "day" block
            $table->decimal('change', 16, 6)->nullable();
            $table->decimal('change_percent', 16, 6)->nullable();

            // raw polygon request id so we can debug / dedupe
            $table->string('request_id', 64)->nullable()->index();

            // capture timestamp (when we ingested this row)
            $table->timestamp('captured_at')->index();

            $table->timestamps();

            // fast uniqueness for "what do we already have logged for this moment?"
            // You usually don't want duplicate rows for same contract in same capture run.
            $table->unique(
                ['contract_symbol', 'captured_at'],
                'u_contract_at'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_option_volumes');
    }
};
