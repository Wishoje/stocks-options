<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('positions_cache', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('payload_hash', 64)->index();
            $t->longText('payload');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('positions_cache'); }
};
