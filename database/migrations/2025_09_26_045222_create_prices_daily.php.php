<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('prices_daily', function (Blueprint $t) {
      $t->id();
      $t->string('symbol', 16)->index();
      $t->date('trade_date')->index();
      $t->decimal('open', 12, 4)->nullable();
      $t->decimal('high', 12, 4)->nullable();
      $t->decimal('low',  12, 4)->nullable();
      $t->decimal('close',12, 4)->nullable();
      $t->timestamps();
      $t->unique(['symbol','trade_date']);
    });
  }
  public function down(): void { Schema::dropIfExists('prices_daily'); }
};
