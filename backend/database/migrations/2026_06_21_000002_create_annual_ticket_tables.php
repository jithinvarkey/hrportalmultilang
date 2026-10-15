<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('relationship', 30);
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('passport_number', 50)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('ticket_year')->nullable()->after('requires_ticket');
            $table->unsignedTinyInteger('ticket_count')->default(0)->after('ticket_year');
        });

        Schema::create('leave_ticket_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('passenger_type', 20);
            $table->foreignId('dependent_id')->nullable()->constrained('employee_dependents')->nullOnDelete();
            $table->string('passenger_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ticket_passengers');
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['ticket_year', 'ticket_count']);
        });
        Schema::dropIfExists('employee_dependents');
    }
};
