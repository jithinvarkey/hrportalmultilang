<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the work sites employees may punch from on their phone.
 */
class AttendanceLocationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AttendanceLocation::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $location = AttendanceLocation::create($this->validated($request));

        return response()->json(['message' => __('Attendance location saved.'), 'location' => $location], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $location = AttendanceLocation::findOrFail($id);
        $location->update($this->validated($request));

        return response()->json(['message' => __('Attendance location saved.'), 'location' => $location]);
    }

    public function destroy(int $id): JsonResponse
    {
        AttendanceLocation::findOrFail($id)->delete();

        return response()->json(['message' => __('Attendance location deleted.')]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'          => 'required|string|max:100',
            'latitude'      => 'required|numeric|between:-90,90',
            'longitude'     => 'required|numeric|between:-180,180',
            'radius_meters' => 'required|integer|min:50|max:5000',
            'is_active'     => 'required|boolean',
        ]);
    }
}
