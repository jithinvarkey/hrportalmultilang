<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_dependents', function (Blueprint $table) {
            // Nullable at database level so existing dependent records remain valid.
            $table->string('id_number', 50)->nullable()->after('nationality');
            $table->date('id_expiry')->nullable()->after('id_number');
            $table->string('passport_file_path')->nullable()->after('passport_expiry');
            $table->string('passport_file_name')->nullable()->after('passport_file_path');
            $table->string('id_file_path')->nullable()->after('passport_file_name');
            $table->string('id_file_name')->nullable()->after('id_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('employee_dependents', function (Blueprint $table) {
            $table->dropColumn([
                'id_number', 'id_expiry', 'passport_file_path', 'passport_file_name',
                'id_file_path', 'id_file_name',
            ]);
        });
    }
};
