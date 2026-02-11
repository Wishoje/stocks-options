<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 190);
            $table->timestamp('sent_at');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
            $table->index(['event_key', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_email_logs');
    }
};

