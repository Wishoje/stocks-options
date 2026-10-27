<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_study_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->char('sample_id', 64);
            $table->string('cohort', 24);
            $table->string('symbol', 16);
            $table->date('session');
            $table->string('rule_version', 64);
            $table->longText('payload');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['owner_id', 'sample_id']);
            $table->index(['owner_id', 'cohort', 'session'], 'setup_study_owner_cohort_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_study_records');
    }
};
