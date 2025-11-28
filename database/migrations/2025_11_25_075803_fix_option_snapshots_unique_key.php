<?php

// database/migrations/2025_11_25_000000_fix_option_snapshots_unique_key.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('option_snapshots', function (Blueprint $table) {
            // drop old unique on (ticker, fetched_at)
            $table->dropUnique(['ticker', 'fetched_at']);

            // add new composite unique per contract snapshot
            $table->unique(
                ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
                'option_snapshots_contract_fetch_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('option_snapshots', function (Blueprint $table) {
            $table->dropUnique('option_snapshots_contract_fetch_unique');
            $table->unique(['ticker', 'fetched_at']);
        });
    }
};
