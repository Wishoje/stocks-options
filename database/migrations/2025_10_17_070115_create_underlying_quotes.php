<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('underlying_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->index();
            $table->timestamp('asof')->index();
            $table->decimal('last', 12, 4)->nullable();
            $table->decimal('bid', 12, 4)->nullable();
            $table->decimal('ask', 12, 4)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('underlying_quotes');
    }
};
