<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 50)->unique();
            $table->string('legacy_unitid', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('department_id')->constrained('units')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'unit_id')) {
                $table->dropConstrainedForeignId('unit_id');
            }
        });

        Schema::dropIfExists('units');
    }
};
