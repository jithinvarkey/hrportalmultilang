<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores where each mobile punch was made and lets admins switch mobile punching off per user.
 * The source enum gains 'mobile'. SQLite (the test suite) enforces the enum with a CHECK that cannot be altered,
 * so there the column is replaced by a plain string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            foreach (['check_in', 'check_out'] as $punch) {
                $table->foreignId("{$punch}_location_id")->nullable()->constrained('attendance_locations')->nullOnDelete();
                $table->decimal("{$punch}_latitude", 10, 7)->nullable();
                $table->decimal("{$punch}_longitude", 10, 7)->nullable();
                $table->unsignedInteger("{$punch}_distance_meters")->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mobile_punch_enabled')->default(true);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN source
                ENUM('manual','api','biometric','import','mobile') NOT NULL DEFAULT 'api'");
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE attendance_logs ADD COLUMN source_text VARCHAR NOT NULL DEFAULT 'api'");
            DB::statement('UPDATE attendance_logs SET source_text = source');
            DB::statement('ALTER TABLE attendance_logs DROP COLUMN source');
            DB::statement('ALTER TABLE attendance_logs RENAME COLUMN source_text TO source');
        }
    }

    public function down(): void
    {
        // Only narrow back when no row uses the newer value, so rollback never destroys data.
        if (DB::getDriverName() === 'mysql' && !DB::table('attendance_logs')->where('source', 'mobile')->exists()) {
            DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN source
                ENUM('manual','api','biometric','import') NOT NULL DEFAULT 'api'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mobile_punch_enabled');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            foreach (['check_in', 'check_out'] as $punch) {
                $table->dropConstrainedForeignId("{$punch}_location_id");
                $table->dropColumn(["{$punch}_latitude", "{$punch}_longitude", "{$punch}_distance_meters"]);
            }
        });
    }
};
