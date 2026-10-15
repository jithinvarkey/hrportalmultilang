<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Unit::withCount(['employees'])->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:50|unique:units,code',
            'legacy_unitid' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        return response()->json(['unit' => Unit::create($data)], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['unit' => Unit::with(['employees'])->findOrFail($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => "sometimes|required|string|max:50|unique:units,code,{$id}",
            'legacy_unitid' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);
        $unit->update($data);

        return response()->json(['unit' => $unit->fresh(['employees'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        Unit::findOrFail($id)->delete();
        return response()->json(['message' => 'Unit deleted.']);
    }
}
