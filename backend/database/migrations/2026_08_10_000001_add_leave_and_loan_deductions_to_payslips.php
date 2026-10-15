<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('unpaid_leave_days', 6, 2)->default(0)->after('leave_days');
            $table->decimal('leave_deduction', 12, 2)->default(0)->after('unpaid_leave_days');
            $table->decimal('loan_deduction', 12, 2)->default(0)->after('leave_deduction');
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            $table->unsignedBigInteger('payslip_id')->nullable()->after('processed_by');
            $table->boolean('paid_via_payroll')->default(false)->after('payslip_id');
            $table->foreign('payslip_id')->references('id')->on('payslips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropForeign(['payslip_id']);
            $table->dropColumn(['payslip_id', 'paid_via_payroll']);
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['unpaid_leave_days', 'leave_deduction', 'loan_deduction']);
        });
    }
};
