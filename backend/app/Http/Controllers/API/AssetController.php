<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetMaintenance;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REST API for the Asset Management module.
 *
 * System Admin and the configured IT department manager can manage assets.
 * HR Manager has global read-only access. Other employees see only their own.
 */
class AssetController extends Controller
{
    /** @var AssetService */
    private $service;

    public function __construct(AssetService $service)
    {
        $this->service = $service;
    }

    // ── Role helpers ──────────────────────────────────────────────────────

    /**
     * Raw-DB role lookup — avoids the Spatie/Sanctum guard mismatch.
     * @return string[]
     */
    private function userRoles(): array
    {
        $user = auth()->user();
        return rescue(function () use ($user) {
            $roles = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->id)
                ->where('model_has_roles.model_type', get_class($user))
                ->pluck('roles.name')
                ->toArray();

            if (in_array('ceo', $roles, true) && !in_array('hr_manager', $roles, true)) {
                $roles[] = 'hr_manager';
            }

            return $roles;
        }, [], false);
    }

    private function isItDepartmentManager(): bool
    {
        $user = auth()->user();
        $employeeId = (int) optional(optional($user)->employee)->id;

        return $employeeId > 0 && DB::table('departments')
            ->where('manager_id', $employeeId)
            ->where(function ($query) {
                $query->whereRaw('UPPER(code) = ?', ['IT'])
                    ->orWhereRaw('LOWER(name) IN (?, ?)', ['information technology', 'it']);
            })
            ->exists();
    }

    private function canViewAllAssets(): bool
    {
        return count(array_intersect($this->userRoles(), ['super_admin', 'hr_manager'])) > 0
            || $this->isItDepartmentManager();
    }

    private function canManageAssets(): bool
    {
        return in_array('super_admin', $this->userRoles(), true)
            || $this->isItDepartmentManager();
    }

    private function denyIfCannotManage(): ?JsonResponse
    {
        return $this->canManageAssets()
            ? null
            : response()->json(['message' => 'Insufficient permissions.'], 403);
    }

    private function denyIfCannotViewAll(): ?JsonResponse
    {
        return $this->canViewAllAssets()
            ? null
            : response()->json(['message' => 'Insufficient permissions.'], 403);
    }

    private function currentEmployeeId(): ?int
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $employee = $user->employee;
        return $employee ? (int) $employee->id : null;
    }

    private function scopedEmployeeId(): ?int
    {
        if ($this->canViewAllAssets()) {
            return null;
        }

        return $this->currentEmployeeId() ?: 0;
    }

    // ── Categories ────────────────────────────────────────────────────────

    /** @return JsonResponse */
    public function categories(): JsonResponse
    {
        $employeeId = $this->scopedEmployeeId();

        return response()->json([
            'categories' => AssetCategory::withCount(['assets' => function ($query) use ($employeeId) {
                if ($employeeId !== null) {
                    $query->where('custodian_employee_id', $employeeId)
                        ->where('status', 'assigned');
                }
            }])
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    /** @return JsonResponse */
    public function storeCategory(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $data['slug']      = $this->uniqueSlug($data['name']);
        $data['is_active'] = true;

        return response()->json(['category' => AssetCategory::create($data)], 201);
    }

    /** @return JsonResponse */
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;
        $cat = AssetCategory::findOrFail($id);
        $cat->update($request->validate([
            'name'       => 'sometimes|string|max:100',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_active'  => 'sometimes|boolean',
        ]));
        return response()->json(['category' => $cat]);
    }

    /** @return JsonResponse */
    public function deleteCategory(int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;
        AssetCategory::findOrFail($id)->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    // ── Assets CRUD ───────────────────────────────────────────────────────

    /** Paginated + filtered asset list (HR sees all; employees see only own). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'category_id', 'per_page']);
        $page    = $this->service->list($filters, $this->scopedEmployeeId());
        return response()->json($page->setCollection(
            AssetResource::collection($page->getCollection())->toBase()
        ));
    }

    /** @return JsonResponse */
    public function show(int $id): JsonResponse
    {
        $asset = $this->service->find($id, $this->scopedEmployeeId());
        return response()->json(['asset' => new AssetResource($asset)]);
    }

    /** @return JsonResponse */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $data = $request->validate([
            'category_id'    => 'nullable|exists:asset_categories,id',
            'name'           => 'required|string|max:200',
            'asset_code'     => 'required|string|max:100|unique:assets,asset_code',
            'brand'          => 'nullable|string|max:100',
            'model'          => 'nullable|string|max:100',
            'serial_number'  => 'nullable|string|max:150|unique:assets,serial_number',
            'description'    => 'nullable|string',
            'condition'      => 'nullable|in:new,good,fair,poor',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date'  => 'nullable|date',
            'vendor'         => 'nullable|string|max:150',
            'warranty_expiry'=> 'nullable|string|max:20',
            'location'       => 'nullable|string|max:150',
            'attachment'     => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $asset = $this->service->create($data, $request->file('attachment'), auth()->id());

        return response()->json([
            'message' => 'Asset created.',
            'asset'   => new AssetResource($asset),
        ], 201);
    }

    /** @return JsonResponse */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $asset = Asset::findOrFail($id);
        $data  = $request->validate([
            'category_id'    => 'nullable|exists:asset_categories,id',
            'name'           => 'sometimes|string|max:200',
            'asset_code'     => "sometimes|string|max:100|unique:assets,asset_code,{$id}",
            'brand'          => 'nullable|string|max:100',
            'model'          => 'nullable|string|max:100',
            'serial_number'  => "nullable|string|max:150|unique:assets,serial_number,{$id}",
            'description'    => 'nullable|string',
            'condition'      => 'nullable|in:new,good,fair,poor',
            'status'         => 'nullable|in:available,assigned,under_maintenance,disposed,lost',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date'  => 'nullable|date',
            'vendor'         => 'nullable|string|max:150',
            'warranty_expiry'=> 'nullable|string|max:20',
            'location'       => 'nullable|string|max:150',
            'attachment'     => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $asset = $this->service->update($asset, $data, $request->file('attachment'));

        return response()->json([
            'message' => 'Asset updated.',
            'asset'   => new AssetResource($asset),
        ]);
    }

    /** @return JsonResponse */
    public function destroy(int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        try {
            $this->service->delete(Asset::findOrFail($id));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Asset deleted.']);
    }

    /** Download the asset's attached document. */
    public function downloadAttachment(int $id): mixed
    {
        $asset = $this->service->find($id, $this->scopedEmployeeId());
        if (!$asset->attachment_path || !\Illuminate\Support\Facades\Storage::disk('public')->exists($asset->attachment_path)) {
            return response()->json(['message' => 'No attachment.'], 404);
        }
        return \Illuminate\Support\Facades\Storage::disk('public')
            ->download($asset->attachment_path, $asset->attachment_name ?: 'asset');
    }

    // ── Assignments ────────────────────────────────────────────────────────

    /** Assign asset to an employee. */
    public function assign(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $asset = Asset::findOrFail($id);
        $data  = $request->validate([
            'employee_id'        => 'required|exists:employees,id',
            'assigned_date'      => 'required|date',
            'condition_at_assign'=> 'nullable|in:new,good,fair,poor',
            'notes'              => 'nullable|string|max:500',
        ]);

        try {
            $assignment = $this->service->assign($asset, (int) $data['employee_id'], $data, auth()->id());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Asset assigned.', 'assignment' => $assignment], 201);
    }

    /** Return an asset from an employee. */
    public function return(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $asset = Asset::findOrFail($id);
        $data  = $request->validate([
            'return_date'         => 'required|date',
            'condition_at_return' => 'nullable|in:new,good,fair,poor',
            'notes'               => 'nullable|string|max:500',
        ]);

        try {
            $assignment = $this->service->return($asset, $data, auth()->id());
        } catch (\Exception $e) {
            return response()->json(['message' => 'No active assignment found for this asset.'], 422);
        }

        return response()->json(['message' => 'Asset returned.', 'assignment' => $assignment]);
    }

    /** All assets currently assigned to a specific employee. */
    public function forEmployee(int $employeeId): JsonResponse
    {
        if (!$this->canViewAllAssets() && $employeeId !== $this->currentEmployeeId()) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        return response()->json([
            'assets' => AssetResource::collection($this->service->forEmployee($employeeId)),
        ]);
    }

    // ── Maintenance ────────────────────────────────────────────────────────

    /** Log a new maintenance event for an asset. */
    public function logMaintenance(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $asset = Asset::findOrFail($id);
        $data  = $request->validate([
            'type'           => 'required|in:repair,service,inspection,upgrade',
            'title'          => 'required|string|max:200',
            'description'    => 'nullable|string',
            'scheduled_date' => 'nullable|date',
            'cost'           => 'nullable|numeric|min:0',
            'vendor'         => 'nullable|string|max:150',
            'status'         => 'nullable|in:scheduled,in_progress,completed,cancelled',
        ]);

        $record = $this->service->logMaintenance($asset, $data, auth()->id());

        return response()->json(['message' => 'Maintenance logged.', 'maintenance' => $record], 201);
    }

    /** Update a maintenance record. */
    public function updateMaintenance(Request $request, int $id, int $maintenanceId): JsonResponse
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $record = AssetMaintenance::where('asset_id', $id)->findOrFail($maintenanceId);
        $data   = $request->validate([
            'title'          => 'sometimes|string|max:200',
            'description'    => 'nullable|string',
            'completed_date' => 'nullable|date',
            'cost'           => 'nullable|numeric|min:0',
            'vendor'         => 'nullable|string|max:150',
            'status'         => 'nullable|in:scheduled,in_progress,completed,cancelled',
            'resolution'     => 'nullable|string',
        ]);

        $record = $this->service->updateMaintenance($record, $data);

        return response()->json(['message' => 'Maintenance updated.', 'maintenance' => $record]);
    }

    // ── Statistics ─────────────────────────────────────────────────────────

    /** Inventory dashboard stats. */
    public function stats(): JsonResponse
    {
        if ($deny = $this->denyIfCannotViewAll()) return $deny;
        return response()->json($this->service->stats());
    }

    public function assetReport()
    {
        if ($deny = $this->denyIfCannotViewAll()) return $deny;

        $rows = Asset::with(['category', 'custodian', 'currentAssignment'])->orderBy('name')->get();
        return $this->csvDownload('asset_report_' . now()->format('Ymd_His') . '.csv', [
            'Asset Name', 'Asset Code', 'Category', 'Brand', 'Model', 'Serial Number',
            'Status', 'Condition', 'Location', 'Assigned To', 'Employee Code',
            'Assign Date', 'Return Date', 'Purchase Date', 'Purchase Price',
            'Vendor', 'Warranty Expiry', 'Description',
        ], $rows, function ($asset) {
            $assignment = $asset->currentAssignment->first();
            return [
                $asset->name,
                $asset->asset_code,
                optional($asset->category)->name,
                $asset->brand,
                $asset->model,
                $asset->serial_number,
                $asset->status,
                $asset->condition,
                $asset->location,
                optional($asset->custodian)->full_name,
                optional($asset->custodian)->employee_code,
                $assignment && $assignment->assigned_date ? $assignment->assigned_date->format('Y-m-d') : '',
                $assignment && $assignment->return_date ? $assignment->return_date->format('Y-m-d') : '',
                $asset->purchase_date ? $asset->purchase_date->format('Y-m-d') : '',
                $asset->purchase_price,
                $asset->vendor,
                $asset->warranty_expiry,
                $asset->description,
            ];
        });
    }

    public function assignmentReport()
    {
        if ($deny = $this->denyIfCannotViewAll()) return $deny;

        $rows = AssetAssignment::with(['asset.category', 'employee', 'assignedBy', 'returnedTo'])
            ->orderByDesc('assigned_date')
            ->get();

        return $this->csvDownload('asset_assignment_report_' . now()->format('Ymd_His') . '.csv', [
            'Asset Name', 'Asset Code', 'Category', 'Employee', 'Employee Code',
            'Assigned Date', 'Return Date', 'Status', 'Condition at Assign',
            'Condition at Return', 'Assigned By', 'Returned To', 'Notes',
        ], $rows, function ($assignment) {
            return [
                optional($assignment->asset)->name,
                optional($assignment->asset)->asset_code,
                optional(optional($assignment->asset)->category)->name,
                optional($assignment->employee)->full_name,
                optional($assignment->employee)->employee_code,
                $assignment->assigned_date ? $assignment->assigned_date->format('Y-m-d') : '',
                $assignment->return_date ? $assignment->return_date->format('Y-m-d') : '',
                $assignment->return_date ? 'Returned' : 'Current',
                $assignment->condition_at_assign,
                $assignment->condition_at_return,
                optional($assignment->assignedBy)->name,
                optional($assignment->returnedTo)->name,
                $assignment->notes,
            ];
        });
    }

    public function maintenanceReport()
    {
        if ($deny = $this->denyIfCannotViewAll()) return $deny;

        $rows = AssetMaintenance::with(['asset.category', 'createdBy'])->orderByDesc('scheduled_date')->get();
        return $this->csvDownload('asset_maintenance_report_' . now()->format('Ymd_His') . '.csv', [
            'Asset Name', 'Asset Code', 'Category', 'Type', 'Title', 'Status',
            'Scheduled Date', 'Completed Date', 'Cost', 'Vendor', 'Created By',
            'Description', 'Resolution',
        ], $rows, function ($record) {
            return [
                optional($record->asset)->name,
                optional($record->asset)->asset_code,
                optional(optional($record->asset)->category)->name,
                $record->type,
                $record->title,
                $record->status,
                $record->scheduled_date ? $record->scheduled_date->format('Y-m-d') : '',
                $record->completed_date ? $record->completed_date->format('Y-m-d') : '',
                $record->cost,
                $record->vendor,
                optional($record->createdBy)->name,
                $record->description,
                $record->resolution,
            ];
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function csvDownload(string $filename, array $headers, $rows, callable $mapper)
    {
        return response()->streamDownload(function () use ($headers, $rows, $mapper) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $mapper($row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i    = 1;
        while (AssetCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
