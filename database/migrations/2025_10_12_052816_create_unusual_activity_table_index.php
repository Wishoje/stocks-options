<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // make sure table exists
        if (!Schema::hasTable('unusual_activity')) return;

        Schema::table('unusual_activity', function (Blueprint $t) {
            // add columns only if they don't exist (defensive)
            if (!Schema::hasColumn('unusual_activity', 'vol_oi')) {
                $t->decimal('vol_oi', 12, 4)->nullable()->after('z_score');
            }
            if (!Schema::hasColumn('unusual_activity', 'meta')) {
                $t->json('meta')->nullable()->after('vol_oi');
            }
        });

        // add unique + indexes if missing (MySQL)
        $this->ensureIndex('unusual_activity', 'ua_unique', "
            ALTER TABLE unusual_activity
            ADD CONSTRAINT ua_unique
            UNIQUE KEY (symbol, data_date, exp_date, strike)
        ");

        $this->ensureIndex('unusual_activity', 'ua_symbol_date', "
            CREATE INDEX ua_symbol_date ON unusual_activity (symbol, data_date)
        ");

        $this->ensureIndex('unusual_activity', 'ua_symbol_date_exp', "
            CREATE INDEX ua_symbol_date_exp ON unusual_activity (symbol, data_date, exp_date)
        ");

        $this->ensureIndex('unusual_activity', 'ua_symbol_date_z', "
            CREATE INDEX ua_symbol_date_z ON unusual_activity (symbol, data_date, z_score)
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('unusual_activity')) return;

        // drop only the extra indexes we added
        $this->dropIndexIfExists('unusual_activity', 'ua_unique', true);
        $this->dropIndexIfExists('unusual_activity', 'ua_symbol_date');
        $this->dropIndexIfExists('unusual_activity', 'ua_symbol_date_exp');
        $this->dropIndexIfExists('unusual_activity', 'ua_symbol_date_z');
    }

    // helpers
    private function ensureIndex(string $table, string $name, string $sql): void
    {
        // check information_schema to avoid duplicate index errors
        $exists = DB::selectOne("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
        ", [$table, $name]);

        if (!$exists) {
            DB::statement($sql);
        }
    }

    private function dropIndexIfExists(string $table, string $name, bool $isConstraint = false): void
    {
        $exists = DB::selectOne("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
        ", [$table, $name]);

        if ($exists) {
            DB::statement(($isConstraint ? "ALTER TABLE $table DROP INDEX $name" : "DROP INDEX $name ON $table"));
        }
    }
};
