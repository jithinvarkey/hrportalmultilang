<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manages employee onboarding tasks.
 *
 * All write operations validate and whitelist input fields to prevent
 * mass-assignment vulnerabilities.
 */
class OnboardingController extends Controller
{
    /**
     * Return all onboarding tasks for a given employee, ordered by sort_order.
     *
     * @param  int          $empId
     * @return JsonResponse
     */
    public function tasks(int $empId): JsonResponse
    {
        if (!$this->canAccess($empId, false)) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        return response()->json([
            'tasks' => OnboardingTask::where('employee_id', $empId)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /**
     * Create a new onboarding task for an employee.
     *
     * @param  Request      $request
     * @param  int          $empId
     * @return JsonResponse           201 with created task
     */
    public function createTask(Request $request, int $empId): JsonResponse
    {
        Employee::findOrFail($empId);
        if (!$this->canAccess($empId, true)) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $validated = $request->validate([
            'title'       => 'required|string|max:200',
            'category'    => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'due_date'    => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => 'nullable|in:pending,in_progress,completed,skipped',
        ]);

        $task = OnboardingTask::create(array_merge($validated, [
            'employee_id' => $empId,
            'status'      => $validated['status'] ?? 'pending',
        ]));

        return response()->json(['task' => $task], 201);
    }

    /**
     * Update an existing onboarding task.
     *
     * @param  Request      $request
     * @param  int          $taskId
     * @return JsonResponse
     */
    public function updateTask(Request $request, int $taskId): JsonResponse
    {
        $task = OnboardingTask::findOrFail($taskId);
        // The user a task is assigned to may also update / complete it.
        if (!$this->canAccess((int) $task->employee_id, true) && (int) $task->assigned_to !== (int) auth()->id()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:200',
            'category'    => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:1000',
            'due_date'    => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => 'nullable|in:pending,in_progress,completed,skipped',
            'notes'       => 'nullable|string|max:500',
        ]);

        $task->update($validated);

        return response()->json(['task' => $task]);
    }

    /**
     * Mark a task as completed and record the completion timestamp.
     *
     * @param  int          $taskId
     * @return JsonResponse
     */
    public function completeTask(int $taskId): JsonResponse
    {
        $task = OnboardingTask::findOrFail($taskId);
        // The user a task is assigned to may also update / complete it.
        if (!$this->canAccess((int) $task->employee_id, true) && (int) $task->assigned_to !== (int) auth()->id()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $task->update([
            'status'         => 'completed',
            'completed_date' => now(),
        ]);

        return response()->json(['message' => __('Task completed')]);
    }

    /**
     * Delete an onboarding task.
     *
     * @param  int          $taskId
     * @return JsonResponse
     */
    public function deleteTask(int $taskId): JsonResponse
    {
        $task = OnboardingTask::findOrFail($taskId);
        if (!$this->canAccess((int) $task->employee_id, true)) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $task->delete();

        return response()->json(['message' => __('Task deleted')]);
    }

    /**
     * HR may do anything. Managers (direct manager, department managers, and
     * managers of the responsible departments such as IT) may read and update
     * tasks. The employee may only read their own checklist.
     */
    private function canAccess(int $empId, bool $write): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->pluck('roles.name')
            ->all();

        if (array_intersect($roles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff'])) {
            return true;
        }

        $own = $user->employee;
        if (!$own) {
            return false;
        }

        if ((int) $own->id === $empId) {
            return !$write;
        }

        if (in_array('department_manager', $roles, true)
            || Employee::whereKey($empId)->where('manager_id', $own->id)->exists()
            || DB::table('departments')->where('manager_id', $own->id)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return false;
    }
}
