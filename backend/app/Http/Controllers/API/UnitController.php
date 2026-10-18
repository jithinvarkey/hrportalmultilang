<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Unit::withCount(['employees'])->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:150',
            'code' => 'required|string|max:50|unique:units,code',
            'legacy_unitid' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        return response()->json(['unit' => Unit::create($data)], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['unit' => Employee::stripPayFields(Unit::with(['employees'])->findOrFail($id)->toArray())]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        $unit = Unit::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'name_ar' => 'nullable|string|max:150',
            'code' => "sometimes|required|string|max:50|unique:units,code,{$id}",
            'legacy_unitid' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);
        $unit->update($data);

        return response()->json(['unit' => Employee::stripPayFields($unit->fresh(['employees'])->toArray())]);
    }

    public function destroy(int $id): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        Unit::findOrFail($id)->delete();
        return response()->json(['message' => __('Unit deleted.')]);
    }

    /** Master data is maintained by HR / system admins only. */
    private function canManage(): bool
    {
        return auth()->check() && DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager', 'hr_staff'])
            ->exists();
    }
}
