<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_category_id')->nullable()->unique()->after('id');
        });

        $categories = [
            1 => 'IT and Cyber Security',
            2 => 'HR',
            3 => 'Cyber security',
            4 => 'Circulars',
            5 => 'Quality and Development',
            6 => 'Sales and Marketing',
            7 => 'Technical',
            8 => 'Finance',
            9 => 'Operation',
            10 => 'Compliance',
            11 => 'Shahin',
            12 => 'Business Services',
            13 => 'Operation Enrollment',
            14 => 'Operation Medical approval',
            15 => 'Executive Management',
            16 => 'Violations and Penalties',
        ];

        foreach ($categories as $legacyId => $name) {
            DB::table('policy_categories')->updateOrInsert(
                ['slug' => Str::slug($name)],
                [
                    'legacy_category_id' => $legacyId,
                    'name' => $name,
                    'icon' => 'folder',
                    'sort_order' => $legacyId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        Schema::table('policies', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_document_id')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('legacy_category_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('legacy_subcategory_id')->nullable()->after('legacy_category_id');
            $table->string('document_type', 50)->nullable()->after('version');
            $table->unsignedBigInteger('legacy_created_by')->nullable()->after('created_by');
            $table->unsignedBigInteger('legacy_modified_by')->nullable()->after('legacy_created_by');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropUnique(['legacy_document_id']);
            $table->dropColumn([
                'legacy_document_id', 'legacy_category_id', 'legacy_subcategory_id',
                'document_type', 'legacy_created_by', 'legacy_modified_by',
            ]);
        });

        Schema::table('policy_categories', function (Blueprint $table) {
            $table->dropUnique(['legacy_category_id']);
            $table->dropColumn('legacy_category_id');
        });
    }
};
