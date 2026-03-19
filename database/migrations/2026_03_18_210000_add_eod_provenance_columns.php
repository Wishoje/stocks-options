<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('dex_by_expiry', 'source_chain_date', fn (Blueprint $table) => $table->date('source_chain_date')->nullable()->after('dex_total'));
        $this->addColumnIfMissing('expiry_pressure', 'source_chain_date', fn (Blueprint $table) => $table->date('source_chain_date')->nullable()->after('max_pain'));
        $this->addColumnIfMissing('iv_term', 'source_chain_date', fn (Blueprint $table) => $table->date('source_chain_date')->nullable()->after('iv'));
        $this->addColumnIfMissing('iv_skew', 'source_chain_date', fn (Blueprint $table) => $table->date('source_chain_date')->nullable()->after('curvature_dod'));
        $this->addColumnIfMissing('vrp_daily', 'source_meta_json', fn (Blueprint $table) => $table->json('source_meta_json')->nullable()->after('z'));

        if (Schema::hasTable('unusual_activity') && Schema::hasColumn('unusual_activity', 'z_score')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $this->recreateUnusualActivityTable(nullableZScore: true);
            } else {
                DB::statement('ALTER TABLE unusual_activity MODIFY z_score DECIMAL(8, 3) NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('unusual_activity') && Schema::hasColumn('unusual_activity', 'z_score')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $this->recreateUnusualActivityTable(nullableZScore: false);
            } else {
                DB::statement('ALTER TABLE unusual_activity MODIFY z_score DECIMAL(8, 3) NOT NULL');
            }
        }

        $this->dropColumnIfExists('vrp_daily', 'source_meta_json');
        $this->dropColumnIfExists('iv_skew', 'source_chain_date');
        $this->dropColumnIfExists('iv_term', 'source_chain_date');
        $this->dropColumnIfExists('expiry_pressure', 'source_chain_date');
        $this->dropColumnIfExists('dex_by_expiry', 'source_chain_date');
    }

    protected function addColumnIfMissing(string $table, string $column, callable $callback): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($callback) {
            $callback($blueprint);
        });
    }

    protected function dropColumnIfExists(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->dropColumn($column);
        });
    }

    protected function recreateUnusualActivityTable(bool $nullableZScore): void
    {
        Schema::dropIfExists('unusual_activity_tmp');

        Schema::create('unusual_activity_tmp', function (Blueprint $table) use ($nullableZScore) {
            $table->id();
            $table->string('symbol', 16)->index();
            $table->date('data_date')->index();
            $table->date('exp_date')->index();
            $table->decimal('strike', 14, 4)->index();
            $column = $table->decimal('z_score', 8, 3);
            if ($nullableZScore) {
                $column->nullable();
            }
            $table->decimal('vol_oi', 10, 4);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'data_date', 'exp_date', 'strike']);
        });

        DB::statement(
            'INSERT INTO unusual_activity_tmp (id, symbol, data_date, exp_date, strike, z_score, vol_oi, meta, created_at, updated_at)
             SELECT id, symbol, data_date, exp_date, strike, z_score, vol_oi, meta, created_at, updated_at
             FROM unusual_activity'
        );

        Schema::drop('unusual_activity');
        Schema::rename('unusual_activity_tmp', 'unusual_activity');
    }
};
