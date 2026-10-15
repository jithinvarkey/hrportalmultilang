<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('carry_forward_all')->default(false);
        });
        // Preserve the effective legacy annual limit until an administrator changes it.
        DB::table('leave_types')->where(function ($query) {
            $query->where('is_annual', true)->orWhere('code', 'AL')->orWhere('name', 'like', '%Annual%');
        })->where(function ($query) {
            $query->whereNull('max_carry_forward')->orWhere('max_carry_forward', '<=', 0)->orWhere('max_carry_forward', '>', 10);
        })->update(['max_carry_forward' => 10]);
    }

    public function down(): void
    {
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('carry_forward_all'));
    }
};
