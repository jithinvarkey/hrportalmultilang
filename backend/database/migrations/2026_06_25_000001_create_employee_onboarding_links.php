<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'id_expiry_date')) {
                $table->date('id_expiry_date')->nullable()->after('national_id');
            }
            if (!Schema::hasColumn('employees', 'passport_number')) {
                $table->string('passport_number', 50)->nullable()->after('id_expiry_date');
            }
            if (!Schema::hasColumn('employees', 'passport_expiry_date')) {
                $table->date('passport_expiry_date')->nullable()->after('passport_number');
            }
        });

        Schema::create('employee_onboarding_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_onboarding_links');

        Schema::table('employees', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('employees', 'id_expiry_date') ? 'id_expiry_date' : null,
                Schema::hasColumn('employees', 'passport_number') ? 'passport_number' : null,
                Schema::hasColumn('employees', 'passport_expiry_date') ? 'passport_expiry_date' : null,
            ]);

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
