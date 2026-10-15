<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('policy_categories', 'audience_type')) {
                $table->string('audience_type', 20)->default('all')->after('slug');
            }
            if (!Schema::hasColumn('policy_categories', 'target_department_ids')) {
                $table->json('target_department_ids')->nullable()->after('audience_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('policy_categories', function (Blueprint $table) {
            if (Schema::hasColumn('policy_categories', 'target_department_ids')) {
                $table->dropColumn('target_department_ids');
            }
            if (Schema::hasColumn('policy_categories', 'audience_type')) {
                $table->dropColumn('audience_type');
            }
        });
    }
};
