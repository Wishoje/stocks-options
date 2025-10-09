<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('iv_skew', function (Blueprint $t) {
            $t->id();
            $t->string('symbol', 12)->index();
            $t->date('data_date')->index();
            $t->date('exp_date')->index();

            $t->decimal('iv_put_25d', 8, 6)->nullable();   // e.g. 0.285000
            $t->decimal('iv_call_25d',8, 6)->nullable();   // e.g. 0.225000
            $t->decimal('skew_pc',    8, 6)->nullable();   // iv_put_25d - iv_call_25d
            $t->decimal('curvature',  10, 8)->nullable();  // quadratic coeff 'a' in IV ~ a*k^2 + b*k + c

            $t->decimal('skew_pc_dod',   8, 6)->nullable(); // day-over-day change
            $t->decimal('curvature_dod',10, 8)->nullable();

            $t->timestamps();

            $t->unique(['symbol','data_date','exp_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('iv_skew'); }
};
