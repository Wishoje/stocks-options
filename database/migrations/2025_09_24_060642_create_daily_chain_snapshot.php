<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('daily_chain_snapshot', function (Blueprint $t) {
            $t->id();
            $t->string('symbol', 16)->index();
            $t->date('data_date')->index();
            $t->foreignId('expiration_id')->index();
            // aggregated totals per expiry for fast dashboards
            $t->bigInteger('call_oi')->default(0);
            $t->bigInteger('put_oi')->default(0);
            $t->bigInteger('call_vol')->default(0);
            $t->bigInteger('put_vol')->default(0);
            $t->double('sum_gamma')->default(0);
            $t->double('sum_delta')->default(0);
            $t->double('sum_vega')->default(0);
            $t->timestamps();

            $t->unique(['symbol','data_date','expiration_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('daily_chain_snapshot');
    }
};
