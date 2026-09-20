<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_settings', function (Blueprint $table) {
            $table->id();
            $table->string('second_symbol', 8)->default('QQQ');
            $table->boolean('paused')->default(false);
            $table->timestamps();
        });
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->date('session_date');
            $table->string('slot', 12);
            $table->string('symbol', 8);
            $table->string('status', 24)->default('draft');
            $table->json('snapshot')->nullable();
            $table->text('body')->nullable();
            $table->text('alt_text')->nullable();
            $table->text('issue')->nullable();
            $table->string('image_path')->nullable();
            $table->longText('image_base64')->nullable();
            $table->string('image_sha256', 64)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('x_post_id')->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['session_date', 'slot']);
            $table->index(['status', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_settings');
    }
};
