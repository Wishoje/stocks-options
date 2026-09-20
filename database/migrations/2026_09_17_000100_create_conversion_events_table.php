<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->char('event_key', 64)->unique();
            $table->string('authority', 64);
            $table->timestamp('occurred_at');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_type']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_events');
    }
};
