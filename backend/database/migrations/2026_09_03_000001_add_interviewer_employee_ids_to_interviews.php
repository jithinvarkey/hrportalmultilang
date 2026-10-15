<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->json('interviewer_employee_ids')->nullable()->after('interviewers');
        });

        // Preserve access to interviews scheduled before employee IDs were stored.
        $employeesByName = DB::table('employees')->get(['id', 'first_name', 'last_name'])
            ->keyBy(fn ($employee) => strtolower(trim($employee->first_name . ' ' . $employee->last_name)));
        DB::table('interviews')->orderBy('id')->each(function ($interview) use ($employeesByName) {
            $names = json_decode((string) $interview->interviewers, true) ?: [];
            $ids = collect($names)
                ->map(fn ($name) => $employeesByName->get(strtolower(trim((string) $name)))?->id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if ($ids) DB::table('interviews')->where('id', $interview->id)->update([
                'interviewer_employee_ids' => json_encode($ids),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn('interviewer_employee_ids');
        });
    }
};
