<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasColumn('job_applications', 'department_id')) {
            return;
        }

        Schema::table('job_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('is_cv_bank');
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void {
        if (!Schema::hasColumn('job_applications', 'department_id')) {
            return;
        }

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
