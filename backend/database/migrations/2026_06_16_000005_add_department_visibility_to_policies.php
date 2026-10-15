<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            if (!Schema::hasColumn('policies', 'audience_type')) {
                $table->string('audience_type', 20)->default('all')->after('category_id');
            }

            if (!Schema::hasColumn('policies', 'target_department_ids')) {
                $table->json('target_department_ids')->nullable()->after('audience_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            foreach (['target_department_ids', 'audience_type'] as $column) {
                if (Schema::hasColumn('policies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
