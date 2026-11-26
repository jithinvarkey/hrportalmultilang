<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work From Home is now applied for as a leave type, capped at 4 working days per calendar month.
 * The old HR_WFH request type is switched off; its existing requests are kept as they are and
 * still count towards the cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leave_types', 'monthly_days_limit')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->decimal('monthly_days_limit', 4, 1)->nullable()->after('days_allowed')
                    ->comment('Maximum working days per calendar month. NULL = no monthly limit');
            });
        }

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'WFH'],
            [
                'name' => 'Work From Home',
                'name_ar' => 'العمل عن بُعد',
                'days_allowed' => 0,
                'monthly_days_limit' => 4,
                'is_paid' => true,
                'carry_forward' => false,
                'max_carry_forward' => 0,
                'requires_document' => false,
                'is_hourly' => false,
                'skip_manager_approval' => false,
                'description' => 'Working day spent working from home. Limited to 4 days per month.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('request_types')->where('code', 'HR_WFH')->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('request_types')->where('code', 'HR_WFH')->update(['is_active' => true, 'updated_at' => now()]);

        // Keep the type (inactive) when leave requests already point at it.
        $typeId = DB::table('leave_types')->where('code', 'WFH')->value('id');
        if ($typeId && DB::table('leave_requests')->where('leave_type_id', $typeId)->exists()) {
            DB::table('leave_types')->where('id', $typeId)->update(['is_active' => false, 'updated_at' => now()]);
        } elseif ($typeId) {
            DB::table('leave_types')->where('id', $typeId)->delete();
        }

        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('monthly_days_limit'));
    }
};
