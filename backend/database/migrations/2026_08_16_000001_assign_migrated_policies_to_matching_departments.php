<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $departmentsByName = DB::table('departments')
            ->select(['id', 'name'])
            ->get()
            ->groupBy(fn ($department) => $this->normalizedName((string) $department->name));

        DB::table('policies')
            ->whereNotNull('legacy_document_id')
            ->update([
                'audience_type' => 'all',
                'target_department_ids' => null,
            ]);

        $categories = DB::table('policy_categories')
            ->whereNotNull('legacy_category_id')
            ->select(['id', 'name'])
            ->get();

        foreach ($categories as $category) {
            $departmentIds = $departmentsByName
                ->get($this->normalizedName((string) $category->name), collect())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($departmentIds === []) {
                continue;
            }

            DB::table('policies')
                ->whereNotNull('legacy_document_id')
                ->where('category_id', $category->id)
                ->update([
                    'audience_type' => 'departments',
                    'target_department_ids' => json_encode($departmentIds),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('policies')
            ->whereNotNull('legacy_document_id')
            ->update([
                'audience_type' => 'all',
                'target_department_ids' => null,
            ]);
    }

    private function normalizedName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s*&\s*/', ' and ', $name) ?? $name;
        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }
};
