<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_allocations', function (Blueprint $table) {
            // Marks a carry-forward figure entered by hand, so a bulk reset leaves it alone.
            $table->timestamp('carry_forward_overridden_at')->nullable();
            $table->unsignedBigInteger('carry_forward_overridden_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_allocations', function (Blueprint $table) {
            $table->dropColumn(['carry_forward_overridden_at', 'carry_forward_overridden_by']);
        });
    }
};
