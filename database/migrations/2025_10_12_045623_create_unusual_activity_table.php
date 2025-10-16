<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('unusual_activity', function (Blueprint $t) {
            $t->id();
            $t->string('symbol', 16)->index();
            $t->date('data_date')->index();           // date we computed from (EOD or intraday date)
            $t->date('exp_date')->index();
            $t->decimal('strike', 14, 4)->index();
            $t->decimal('z_score', 8, 3);             // volume z-score vs 30d baseline
            $t->decimal('vol_oi', 10, 4);             // today's volume / current OI
            $t->json('meta')->nullable();             // optional: {call_vol, put_vol, total_vol, baseline_mu, baseline_sigma}
            $t->timestamps();

            $t->unique(['symbol','data_date','exp_date','strike']); // no duplicates for the day
        });
    }
    public function down(): void { Schema::dropIfExists('unusual_activity'); }
};
