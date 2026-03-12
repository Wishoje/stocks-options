<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_exports', function (Blueprint $table) {
            $table->longText('payload_json')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('ai_exports', function (Blueprint $table) {
            $table->dropColumn('payload_json');
        });
    }
};
