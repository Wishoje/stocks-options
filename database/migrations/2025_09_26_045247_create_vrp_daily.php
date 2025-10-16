<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('vrp_daily', function (Blueprint $t) {
      $t->id();
      $t->string('symbol', 16)->index();
      $t->date('data_date')->index();
      $t->float('iv1m')->nullable();
      $t->float('rv20')->nullable();
      $t->float('vrp')->nullable();
      $t->float('z')->nullable();
      $t->timestamps();
      $t->unique(['symbol','data_date']);
    });
  }
  public function down(): void { Schema::dropIfExists('vrp_daily'); }
};

