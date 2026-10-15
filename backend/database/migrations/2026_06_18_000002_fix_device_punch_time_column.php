<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('device_attendance_logs')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE device_attendance_logs MODIFY punch_time DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('device_attendance_logs')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE device_attendance_logs MODIFY punch_time TIMESTAMP NOT NULL');
        }
    }
};
