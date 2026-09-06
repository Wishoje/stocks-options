<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['work_runs', 'symbol_bootstrap_phases'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedInteger('provider_deferrals')->default(0);
                // Only a reserved delivery that made zero physical HTTP
                // requests may receive a credit against the failure budget.
                $table->unsignedInteger('provider_admission_deferrals')->default(0);
                $table->timestamp('provider_deferral_deadline_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['work_runs', 'symbol_bootstrap_phases'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['provider_deferrals', 'provider_admission_deferrals', 'provider_deferral_deadline_at']);
            });
        }
    }
};
