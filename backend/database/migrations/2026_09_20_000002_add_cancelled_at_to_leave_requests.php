<?php

use App\Models\LeaveRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });

        if (!Schema::hasTable('activity_log')) {
            return;
        }

        // The activity log is the only record of when past cancellations actually happened.
        // Rows it cannot date stay null and keep restoring the balance in full, rather than
        // guessing from updated_at and silently charging somebody for leave they never took.
        DB::statement(
            'update leave_requests set cancelled_at = ('
            .' select max(created_at) from activity_log'
            ." where activity_log.subject_type = ? and activity_log.subject_id = leave_requests.id"
            ." and activity_log.event = 'cancelled'"
            .") where status = 'cancelled'",
            [LeaveRequest::class]
        );
    }

    public function down(): void
    {
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
    }
};
