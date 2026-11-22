<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work From Home is the only request type that needs the direct manager's approval before HR.
 * Every other type (Salary Certificate included) now goes straight to HR / the handling department.
 */
return new class extends Migration
{
    // Types that required manager approval before this migration (restored on rollback).
    private array $previous = ['DOC_SALARY', 'DOC_NOC', 'HR_ADVANCE', 'HR_TRAIN', 'IT_EQUIP', 'IT_ACCESS'];

    public function up(): void
    {
        DB::table('request_types')
            ->where('code', '<>', 'HR_WFH')
            ->where('requires_manager_approval', 1)
            ->update(['requires_manager_approval' => 0, 'updated_at' => now()]);

        DB::table('request_types')
            ->where('code', 'HR_WFH')
            ->update(['requires_manager_approval' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('request_types')
            ->whereIn('code', $this->previous)
            ->update(['requires_manager_approval' => 1, 'updated_at' => now()]);
    }
};
