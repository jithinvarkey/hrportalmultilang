<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications for the current employee (bell menu).
 */
class NotificationController extends Controller
{
    private function employeeId(): ?int
    {
        return auth()->user()->employee?->id;
    }

    /** Recent notifications + unread count for the current employee. */
    public function index(Request $request): JsonResponse
    {
        $empId = $this->employeeId();
        if (!$empId) {
            return response()->json(['notifications' => [], 'unread_count' => 0]);
        }

        $notifications = AppNotification::where('employee_id', $empId)
            ->orderByDesc('created_at')
            ->limit((int) ($request->limit ?? 20))
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => AppNotification::where('employee_id', $empId)->unread()->count(),
        ]);
    }

    /** Mark a single notification as read. */
    public function markRead(int $id): JsonResponse
    {
        $empId = $this->employeeId();
        AppNotification::where('id', $id)->where('employee_id', $empId)
            ->update(['read_at' => now()]);
        return response()->json(['message' => 'Marked as read.']);
    }

    /** Mark all of the current employee's notifications as read. */
    public function markAllRead(): JsonResponse
    {
        $empId = $this->employeeId();
        AppNotification::where('employee_id', $empId)->unread()
            ->update(['read_at' => now()]);
        return response()->json(['message' => 'All marked as read.']);
    }
}
