<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * performance_cycles was created by the early 2024_01_01_000007 migration, whose enums do not include the values
 * the application validates and stores today (PerformanceController: type mid_year/probation, status closed).
 * On MySQL those saves fail with "Data truncated". This widens both enums to a superset, so every existing
 * value stays valid. (The test suite on SQLite stores these columns as strings and needs no change.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE performance_cycles MODIFY COLUMN type
            ENUM('annual','semi_annual','mid_year','quarterly','probation','pip') NOT NULL");

        DB::statement("ALTER TABLE performance_cycles MODIFY COLUMN status
            ENUM('draft','active','closed','completed','archived') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Only narrow back when no row uses the newer values, so rollback never destroys data.
        $inUse = DB::table('performance_cycles')
            ->whereIn('type', ['mid_year', 'probation', 'pip'])
            ->orWhere('status', 'closed')
            ->exists();

        if ($inUse) {
            return;
        }

        DB::statement("ALTER TABLE performance_cycles MODIFY COLUMN type
            ENUM('annual','semi_annual','quarterly') NOT NULL");

        DB::statement("ALTER TABLE performance_cycles MODIFY COLUMN status
            ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft'");
    }
};
