<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetMaintenance;
use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Business logic for the Asset Management module.
 *
 * Handles:
 *   - Asset CRUD with attachment management
 *   - Assignment (check-out) and return (check-in)
 *   - Maintenance record lifecycle
 *   - Inventory statistics
 */
class AssetService
{
    // ── Inventory ─────────────────────────────────────────────────────────

    /**
     * Paginated, filterable asset list.
     *
     * @param  array{search?:string, status?:string, category_id?:int, per_page?:int} $filters
     * @param  int|null $employeeId
     * @return LengthAwarePaginator
     */
    public function list(array $filters, ?int $employeeId = null): LengthAwarePaginator
    {
        return Asset::with(['category', 'custodian', 'currentAssignment'])
            ->when($employeeId, function ($q, $id) {
                return $q->where('custodian_employee_id', $id)
                    ->where('status', 'assigned');
            })
            ->when($filters['search'] ?? null, function ($q, $s) {
                return $q->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%{$s}%")
                        ->orWhere('asset_code', 'like', "%{$s}%")
                        ->orWhere('serial_number', 'like', "%{$s}%")
                        ->orWhereHas('custodian', function ($employee) use ($s) {
                            $employee->where('first_name', 'like', "%{$s}%")
                                ->orWhere('last_name', 'like', "%{$s}%")
                                ->orWhere('employee_code', 'like', "%{$s}%")
                                ->orWhereRaw("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) LIKE ?", ["%{$s}%"]);
                        });
                });
            })
            ->when($filters['status'] ?? null, function ($q, $s) {
                return $q->where('status', $s);
            })
            ->when($filters['category_id'] ?? null, function ($q, $c) {
                return $q->where('category_id', $c);
            })
            ->orderByRaw("FIELD(status,'available','assigned','under_maintenance','disposed','lost')")
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Full asset details including assignment history and maintenance log.
     *
     * @param  int $id
     * @param  int|null $employeeId
     * @return Asset
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(int $id, ?int $employeeId = null): Asset
    {
        return Asset::with([
            'category',
            'custodian',
            'currentAssignment',
            'assignments' => function ($q) use ($employeeId) {
                if ($employeeId) {
                    $q->where('employee_id', $employeeId);
                }

                return $q->orderByDesc('assigned_date');
            },
            'assignments.employee',
            'assignments.assignedBy',
            'maintenance' => function ($q) {
                return $q->orderByDesc('scheduled_date');
            },
        ])
            ->when($employeeId, function ($q, $employeeId) {
                return $q->where('custodian_employee_id', $employeeId)
                    ->where('status', 'assigned');
            })
            ->findOrFail($id);
    }

    /**
     * Create a new asset, optionally storing an attachment.
     *
     * @param  array<string, mixed>     $data
     * @param  \Illuminate\Http\UploadedFile|null $file
     * @param  int $createdBy
     * @return Asset
     */
    public function create(array $data, $file, int $createdBy): Asset
    {
        $asset = new Asset($data);
        $asset->created_by = $createdBy;
        $asset->status     = 'available';

        if ($file) {
            $this->storeAttachment($asset, $file);
        }

        $asset->save();
        return $asset->load('category');
    }

    /**
     * Update asset fields and optionally replace the attachment.
     *
     * @param  Asset $asset
     * @param  array<string, mixed> $data
     * @param  \Illuminate\Http\UploadedFile|null $file
     * @return Asset
     */
    public function update(Asset $asset, array $data, $file): Asset
    {
        $asset->fill($data);

        if ($file) {
            $this->deleteAttachment($asset);
            $this->storeAttachment($asset, $file);
        }

        $asset->save();
        return $asset->load('category');
    }

    /**
     * Soft-delete an asset. Prevents deletion if currently assigned.
     *
     * @param  Asset $asset
     * @throws \RuntimeException
     */
    public function delete(Asset $asset): void
    {
        if ($asset->status === 'assigned') {
            throw new \RuntimeException('Cannot delete an asset that is currently assigned.');
        }

        $this->deleteAttachment($asset);
        $asset->delete();
    }

    // ── Assignments ────────────────────────────────────────────────────────

    /**
     * Assign an asset to an employee (check-out).
     *
     * @param  Asset $asset
     * @param  int   $employeeId
     * @param  array{assigned_date:string, condition_at_assign?:string, notes?:string} $data
     * @param  int   $assignedBy
     * @return AssetAssignment
     * @throws \RuntimeException if the asset is not available
     */
    public function assign(Asset $asset, int $employeeId, array $data, int $assignedBy): AssetAssignment
    {
        if (!in_array($asset->status, ['available'], true)) {
            throw new \RuntimeException(
                "Asset \"{$asset->name}\" is currently {$asset->status} and cannot be assigned."
            );
        }

        return DB::transaction(function () use ($asset, $employeeId, $data, $assignedBy): AssetAssignment {
            $assignment = AssetAssignment::create([
                'asset_id'           => $asset->id,
                'employee_id'        => $employeeId,
                'assigned_date'      => $data['assigned_date'],
                'condition_at_assign'=> $data['condition_at_assign'] ?? $asset->condition,
                'notes'              => $data['notes'] ?? null,
                'assigned_by'        => $assignedBy,
            ]);

            $asset->update([
                'status'                  => 'assigned',
                'custodian_employee_id'   => $employeeId,
                'condition'               => $data['condition_at_assign'] ?? $asset->condition,
            ]);

            return $assignment->load(['employee', 'assignedBy']);
        });
    }

    /**
     * Return an asset from an employee (check-in).
     *
     * @param  Asset $asset
     * @param  array{return_date:string, condition_at_return?:string, notes?:string} $data
     * @param  int   $returnedTo
     * @return AssetAssignment
     * @throws \RuntimeException if the asset has no active assignment
     */
    public function return(Asset $asset, array $data, int $returnedTo): AssetAssignment
    {
        $assignment = AssetAssignment::where('asset_id', $asset->id)
            ->whereNull('return_date')
            ->latest()
            ->firstOrFail();

        return DB::transaction(function () use ($asset, $assignment, $data, $returnedTo): AssetAssignment {
            $condition = $data['condition_at_return'] ?? $asset->condition;

            $assignment->update([
                'return_date'          => $data['return_date'],
                'condition_at_return'  => $condition,
                'notes'                => ($assignment->notes ? $assignment->notes . "\n" : '') . ($data['notes'] ?? ''),
                'returned_to'          => $returnedTo,
            ]);

            $asset->update([
                'status'                  => 'available',
                'custodian_employee_id'   => null,
                'condition'               => $condition,
            ]);

            return $assignment->fresh(['employee', 'assignedBy']);
        });
    }

    /**
     * Assets currently assigned to a given employee.
     *
     * @param  int $employeeId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function forEmployee(int $employeeId)
    {
        return Asset::with('category')
            ->assignedTo($employeeId)
            ->orderBy('name')
            ->get();
    }

    // ── Maintenance ────────────────────────────────────────────────────────

    /**
     * Log a maintenance event and, when the asset is sent for repair, mark
     * its status as under_maintenance.
     *
     * @param  Asset $asset
     * @param  array<string, mixed> $data
     * @param  int   $createdBy
     * @return AssetMaintenance
     */
    public function logMaintenance(Asset $asset, array $data, int $createdBy): AssetMaintenance
    {
        return DB::transaction(function () use ($asset, $data, $createdBy): AssetMaintenance {
            $record = AssetMaintenance::create(array_merge($data, [
                'asset_id'   => $asset->id,
                'created_by' => $createdBy,
                'status'     => $data['status'] ?? 'scheduled',
            ]));

            // Reflect the asset status when it physically leaves the inventory.
            if (($data['status'] ?? '') === 'in_progress' && $asset->status === 'available') {
                $asset->update(['status' => 'under_maintenance']);
            }
            if (($data['status'] ?? '') === 'completed' && $asset->status === 'under_maintenance') {
                $asset->update([
                    'status'    => 'available',
                    'condition' => $data['condition_after'] ?? $asset->condition,
                ]);
            }

            return $record;
        });
    }

    /**
     * Update a maintenance record status/resolution.
     *
     * @param  AssetMaintenance $record
     * @param  array<string, mixed> $data
     * @return AssetMaintenance
     */
    public function updateMaintenance(AssetMaintenance $record, array $data): AssetMaintenance
    {
        return DB::transaction(function () use ($record, $data): AssetMaintenance {
            $record->update($data);

            $asset = $record->asset;
            if ($record->status === 'completed' && $asset->status === 'under_maintenance') {
                $asset->update(['status' => 'available']);
            }

            return $record;
        });
    }

    // ── Statistics ─────────────────────────────────────────────────────────

    /**
     * Dashboard-level inventory counters.
     *
     * @return array{total:int, available:int, assigned:int, under_maintenance:int, disposed:int, lost:int}
     */
    public function stats(): array
    {
        $counts = Asset::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        return [
            'total'             => array_sum($counts),
            'available'         => $counts['available']         ?? 0,
            'assigned'          => $counts['assigned']          ?? 0,
            'under_maintenance' => $counts['under_maintenance'] ?? 0,
            'disposed'          => $counts['disposed']          ?? 0,
            'lost'              => $counts['lost']              ?? 0,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function storeAttachment(Asset $asset, $file): void
    {
        $asset->attachment_path = $file->store('assets', 'public');
        $asset->attachment_name = $file->getClientOriginalName();
    }

    private function deleteAttachment(Asset $asset): void
    {
        if ($asset->attachment_path) {
            Storage::disk('public')->delete($asset->attachment_path);
            $asset->attachment_path = null;
            $asset->attachment_name = null;
        }
    }
}
