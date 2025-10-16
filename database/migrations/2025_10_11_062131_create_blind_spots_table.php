<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('blind_spots', function (Blueprint $t) {
            $t->id();
            $t->string('symbol', 16)->index();
            $t->date('data_date')->index();
            $t->date('exp_date')->index();
            $t->json('corridors_json'); // [{from:float,to:float,strength:float,width_n:int}]
            $t->timestamps();
            $t->unique(['symbol','data_date','exp_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('blind_spots'); }
};
