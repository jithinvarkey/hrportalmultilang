<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('is_verified')->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['uploaded_by', 'verified_by', 'verified_at']);
        });
    }
};
