<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work From Home and Salary Certificate requests need the direct manager's approval first;
 * HR (hr_manager / hr_staff) then gives the final approval.
 */
return new class extends Migration
{
    private array $codes = ['HR_WFH', 'DOC_SALARY'];

    public function up(): void
    {
        DB::table('request_types')
            ->whereIn('code', $this->codes)
            ->update(['requires_manager_approval' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Salary Certificate went straight to HR before; Work From Home already required approval.
        DB::table('request_types')
            ->where('code', 'DOC_SALARY')
            ->update(['requires_manager_approval' => 0, 'updated_at' => now()]);
    }
};
