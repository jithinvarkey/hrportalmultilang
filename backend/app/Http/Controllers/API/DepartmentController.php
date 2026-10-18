<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages department CRUD and headcount reporting.
 *
 * All write operations use explicit `only()` instead of `$request->all()`
 * to prevent mass-assignment attacks.
 */
class DepartmentController extends Controller
{
    /**
     * Return all departments (active and inactive) for admin management.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return response()->json(Employee::stripPayFields(
            Department::with(['manager', 'parent'])->orderBy('name')->get()->toArray()
        ));
    }

    /**
     * Create a new department.
     *
     * @param  Request      $request
     * @return JsonResponse           201 with the created department
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'name_ar'          => 'nullable|string|max:150',
            'code'             => 'required|string|max:20|unique:departments',
            'description'      => 'nullable|string|max:500',
            'parent_id'        => 'nullable|exists:departments,id',
            'manager_id'       => 'nullable|exists:employees,id',
            'headcount_budget' => 'nullable|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        return response()->json(
            ['department' => Department::create($validated)],
            201
        );
    }

    /**
     * Return a single department with manager, employees, and child departments.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return response()->json([
            'department' => Employee::stripPayFields(Department::with([
                'manager', 'employees.designation', 'children',
            ])->findOrFail($id)->toArray()),
        ]);
    }

    /**
     * Update an existing department.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $dept = Department::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'sometimes|required|string|max:100',
            'name_ar'          => 'nullable|string|max:150',
            'code'             => "sometimes|required|string|max:20|unique:departments,code,{$id}",
            'description'      => 'nullable|string|max:500',
            'parent_id'        => ['nullable', 'exists:departments,id', \Illuminate\Validation\Rule::notIn($this->selfAndDescendantIds($dept->id))],
            'manager_id'       => 'nullable|exists:employees,id',
            'headcount_budget' => 'nullable|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        $dept->update($validated);

        return response()->json(['department' => Employee::stripPayFields($dept->fresh(['manager', 'parent'])->toArray())]);
    }

    /**
     * Soft-delete a department.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        Department::findOrFail($id)->delete();

        return response()->json(['message' => __('Department deleted')]);
    }

    /**
     * Return headcount budget vs actual employee count for a department.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function headcount(int $id): JsonResponse
    {
        $dept = Department::withCount('employees')->findOrFail($id);

        return response()->json([
            'department_id' => $id,
            'budget'        => $dept->headcount_budget,
            'actual'        => $dept->employees_count,
            'variance'      => ($dept->headcount_budget ?? 0) - $dept->employees_count,
        ]);
    }

    /** Department master data is maintained by HR / system admins only. */
    private function canManage(): bool
    {
        return auth()->check() && DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager', 'hr_staff'])
            ->exists();
    }

    /** The department itself plus all of its descendants — none of them may become its parent. */
    private function selfAndDescendantIds(int $id): array
    {
        $ids = [$id];
        $frontier = [$id];
        while ($frontier) {
            $frontier = array_values(array_diff(
                DB::table('departments')->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($v) => (int) $v)->all(),
                $ids
            ));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
