<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * request_types.handling_department_id is used by the request module (type form, request routing,
 * department notifications) but was never created by a migration. Guarded so databases where it
 * was added by hand are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('request_types', 'handling_department_id')) {
            Schema::table('request_types', function (Blueprint $table) {
                $table->foreignId('handling_department_id')->nullable()->after('category')
                    ->constrained('departments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request_types', 'handling_department_id')) {
            Schema::table('request_types', function (Blueprint $table) {
                $table->dropConstrainedForeignId('handling_department_id');
            });
        }
    }
};
