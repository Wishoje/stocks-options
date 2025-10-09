<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('iv_skew', function (Blueprint $t) {
            $t->double('iv_put_25d')->nullable()->change();
            $t->double('iv_call_25d')->nullable()->change();
            $t->double('skew_pc')->nullable()->change();
            $t->double('curvature')->nullable()->change();
            // if you added these:
            if (Schema::hasColumn('iv_skew','skew_pc_dod')) {
                $t->double('skew_pc_dod')->nullable()->change();
            }
            if (Schema::hasColumn('iv_skew','curvature_dod')) {
                $t->double('curvature_dod')->nullable()->change();
            }
        });
    }
    public function down(): void {
        // No-op or revert to previous types if you had them recorded
    }
};
