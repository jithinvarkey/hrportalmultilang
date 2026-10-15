<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_requests', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->string('linked_service', 30)->nullable()->after('leave_request_id');
            $table->unique(['leave_request_id', 'linked_service'], 'employee_requests_leave_service_unique');
        });

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'non_saudi_max_dependent_tickets'],
            ['value' => '3', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'non_saudi_max_dependent_tickets')->update([
            'value' => '2',
            'updated_at' => now(),
        ]);

        Schema::table('employee_requests', function (Blueprint $table) {
            $table->dropUnique('employee_requests_leave_service_unique');
            $table->dropForeign(['leave_request_id']);
            $table->dropColumn(['leave_request_id', 'linked_service']);
        });
    }
};
