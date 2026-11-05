<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mobile punching becomes opt-in: every user starts disabled and an admin enables them in Admin > Users.
 * SQLite (the test suite) cannot change a column default, so there the column is recreated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault(0);

        DB::table('users')->update(['mobile_punch_enabled' => false]);
    }

    public function down(): void
    {
        $this->setDefault(1);
    }

    private function setDefault(int $default): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE users DROP COLUMN mobile_punch_enabled');
            DB::statement("ALTER TABLE users ADD COLUMN mobile_punch_enabled TINYINT(1) NOT NULL DEFAULT {$default}");

            return;
        }

        DB::statement("ALTER TABLE users ALTER COLUMN mobile_punch_enabled SET DEFAULT {$default}");
    }
};
