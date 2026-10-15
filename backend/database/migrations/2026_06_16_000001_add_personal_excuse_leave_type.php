<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')->updateOrInsert(
            ['code' => 'PE'],
            [
                'name' => 'Personal Excuse',
                'days_allowed' => 0,
                'is_paid' => true,
                'carry_forward' => false,
                'max_carry_forward' => 0,
                'requires_document' => false,
                'is_hourly' => true,
                'monthly_hours_limit' => 12.0,
                'description' => 'Hourly personal excuse with department-wise monthly limits. Default cap: 12h/month per employee.',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('leave_types')->where('code', 'PE')->delete();
    }
};
