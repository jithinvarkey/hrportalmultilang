<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * English / Arabic support.
 *
 * - users.preferred_language remembers the screen (UI) language across devices.
 * - employees.preferred_communication_language (default 'en') decides the language of every email sent to the
 *   employee. It is independent of the UI language and is changed on the profile page (or by HR).
 * - Admin-managed master data gets an optional Arabic name; the UI falls back to the English name when empty.
 *
 * Status values are NOT translated here: they stay as stable codes in the database and are translated in the
 * language files (frontend assets/i18n/<lang>/common.json -> STATUS, backend lang/<lang>/status.php).
 */
return new class extends Migration
{
    private array $arabicNameColumns = [
        'departments'   => ['name', 'name_ar'],
        'designations'  => ['title', 'title_ar'],
        'leave_types'   => ['name', 'name_ar'],
        'loan_types'    => ['name', 'name_ar'],
        'request_types' => ['name', 'name_ar'],
        'units'         => ['name', 'name_ar'],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'preferred_language')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('preferred_language', 5)->default('en')->after('email');
            });
        }

        if (!Schema::hasColumn('employees', 'preferred_communication_language')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('preferred_communication_language', 5)->default('en')->after('email');
            });
        }

        foreach ($this->arabicNameColumns as $tableName => [$after, $column]) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($after, $column) {
                    $table->string($column, 150)->nullable()->after($after);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->arabicNameColumns as $tableName => [, $column]) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasColumn('employees', 'preferred_communication_language')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('preferred_communication_language'));
        }

        if (Schema::hasColumn('users', 'preferred_language')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferred_language'));
        }
    }
};
