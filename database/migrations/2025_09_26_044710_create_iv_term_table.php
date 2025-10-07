<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('iv_term', function (Blueprint $t) {
      $t->id();
      $t->string('symbol', 16)->index();
      $t->date('data_date')->index();
      $t->date('exp_date')->index();
      $t->float('iv')->nullable(); // store as decimal (0.25 = 25%)
      $t->timestamps();
      $t->unique(['symbol','data_date','exp_date']);
    });
  }
  public function down(): void { Schema::dropIfExists('iv_term'); }
};
