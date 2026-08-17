<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_snapshots', function (Blueprint $table): void {
            $table->decimal('underlying_price', 12, 2)->nullable()->change();
            $table->decimal('implied_volatility', 10, 6)->nullable()->after('mid');
        });
    }

    public function down(): void
    {
        Schema::table('option_snapshots', function (Blueprint $table): void {
            $table->dropColumn('implied_volatility');
            // Keep the column nullable. A rollback may contain legitimate rows
            // captured while the underlying quote was unavailable, so restoring
            // the old NOT NULL constraint would either fail or require inventing
            // a price.
        });
    }
};
