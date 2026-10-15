<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\LegacyMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyMigrationController extends Controller
{
    public function __construct(private LegacyMigrationService $service)
    {
    }

    public function import(Request $request): JsonResponse
    {
        $this->allowLongRunningImport();

        if (!$this->canMigrate()) {
            return response()->json(['message' => 'Only Super Admin or HR Manager can run legacy data migration.'], 403);
        }

        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:20480',
            'scope' => 'nullable|in:all,departments,job_positions,employees,employee_managers,leave_records,loan_records,documents',
            'dry_run' => 'nullable|boolean',
            'source_folder' => 'nullable|string|max:1000',
            'legacy_category_id' => 'nullable|integer|min:1|max:16',
        ]);

        try {
            $summary = $this->service->migrate(
                $request->file('file'),
                $data['scope'] ?? 'all',
                $request->boolean('dry_run'),
                $data['source_folder'] ?? null,
                isset($data['legacy_category_id']) ? (int) $data['legacy_category_id'] : null
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $totals = $summary['totals'] ?? [];
        $failed = (int) ($totals['failed'] ?? 0);
        $success = (int) ($totals['success'] ?? 0);
        $message = $request->boolean('dry_run')
            ? ($failed > 0 ? 'Validation completed with errors.' : 'Migration file validated.')
            : ($failed > 0
                ? ($success > 0 ? 'Migration completed with some failed rows.' : 'Migration failed. No rows were imported.')
                : 'Migration completed.');

        return response()->json([
            'message' => $message,
            'summary' => $summary,
        ], $failed > 0 && $success === 0 ? 422 : 200);
    }

    private function allowLongRunningImport(): void
    {
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        ignore_user_abort(true);
    }

    private function canMigrate(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'hr_manager'])
            ->exists();
    }
}
