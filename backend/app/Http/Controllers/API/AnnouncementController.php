<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementCategory;
use App\Models\AnnouncementReaction;
use App\Models\AnnouncementRead;
use App\Models\Employee;
use App\Services\Communications\NotificationService;
use App\Services\BirthdayWishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR announcements: HR/Admin create & manage; all authenticated employees read.
 */
class AnnouncementController extends Controller
{
    private const MANAGER_ROLES = ['super_admin', 'hr_manager', 'hr_staff'];

    public function __construct(private readonly NotificationService $notifications, private readonly BirthdayWishService $birthdayWishes)
    {
    }

    public function birthdayWishSettings(): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;
        return response()->json(['settings' => $this->birthdayWishes->settings(), 'birthdays' => $this->birthdayWishes->dashboard()]);
    }

    public function updateBirthdayWishSettings(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'subject_ar' => 'nullable|string|max:200',
            'body_ar' => 'nullable|string|max:5000',
            'background_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'remove_background_image' => 'nullable|boolean',
        ]);
        $current = $this->birthdayWishes->settings();
        $data['background_image_path'] = $current['background_image_path'] ?? null;

        if ($request->boolean('remove_background_image') && $data['background_image_path']) {
            Storage::disk('public')->delete($data['background_image_path']);
            $data['background_image_path'] = null;
        }
        if ($request->hasFile('background_image')) {
            if ($data['background_image_path']) Storage::disk('public')->delete($data['background_image_path']);
            $data['background_image_path'] = $request->file('background_image')->store('birthday-wishes', 'public');
        }
        return response()->json(['settings' => $this->birthdayWishes->updateSettings($data), 'message' => 'Birthday wish settings saved.']);
    }

    public function sendBirthdayWishes(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;
        return response()->json(['result' => $this->birthdayWishes->sendToday(true, $request->boolean('force')), 'birthdays' => $this->birthdayWishes->dashboard()]);
    }

    /** Roles for the current user via raw DB (avoids Spatie/Sanctum guard mismatch). */
    private function userRoles(): array
    {
        $user = auth()->user();
        return rescue(fn () => DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->pluck('roles.name')->toArray(), [], false);
    }

    private function isManager(): bool
    {
        return count(array_intersect($this->userRoles(), self::MANAGER_ROLES)) > 0;
    }

    private function employeeId(): ?int
    {
        return auth()->user()->employee?->id;
    }

    private function ensureManager(): ?JsonResponse
    {
        return $this->isManager()
            ? null
            : response()->json(['message' => 'You are not allowed to manage announcements.'], 403);
    }

    // ── Categories ────────────────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => AnnouncementCategory::withCount('announcements')
                ->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon'  => 'nullable|string|max:50',
        ]);
        $data['slug']      = $this->uniqueSlug($data['name']);
        $data['is_active'] = true;

        return response()->json(['category' => AnnouncementCategory::create($data)], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $category = AnnouncementCategory::findOrFail($id);
        $category->update($request->validate([
            'name'      => 'sometimes|string|max:100',
            'color'     => 'nullable|string|max:20',
            'icon'      => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json(['category' => $category]);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;
        AnnouncementCategory::findOrFail($id)->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    // ── Announcements ─────────────────────────────────────────────────────

    /**
     * List announcements. Managers see everything; employees see only
     * published, non-expired items. Optional ?category_id and ?search filters.
     */
    public function index(Request $request): JsonResponse
    {
        $manager = $this->isManager();
        $empId   = $this->employeeId();
        $employee = auth()->user()->employee;
        $roles = $this->userRoles();
        $perPage = (int) ($request->per_page ?? 15);

        $query = Announcement::with(['category', 'creator:id,name'])
            ->withCount(['reads', 'reactions'])
            ->when(!$manager, fn($q) => $q->visible())
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where(function ($w) use ($request) {
                $w->where('title', 'like', "%{$request->search}%")
                  ->orWhere('body', 'like', "%{$request->search}%")
                  ->orWhere('title_ar', 'like', "%{$request->search}%")
                  ->orWhere('body_ar', 'like', "%{$request->search}%");
            }))
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        if ($manager) {
            $page = $query->paginate($perPage);
        } else {
            $items = $query->get()
                ->filter(fn($announcement) => $this->announcementVisibleToEmployee($announcement, $employee, $roles))
                ->values();
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $page = new LengthAwarePaginator(
                $items->slice(($currentPage - 1) * $perPage, $perPage)->values(),
                $items->count(),
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        // Annotate each item with the current employee's read state.
        $readIds = $empId
            ? AnnouncementRead::where('employee_id', $empId)
                ->whereIn('announcement_id', collect($page->items())->pluck('id'))
                ->pluck('announcement_id')->all()
            : [];

        $page->getCollection()->transform(function ($a) use ($readIds) {
            $a->is_read = in_array($a->id, $readIds, true);
            return $a;
        });

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $announcement = Announcement::with(['category', 'creator:id,name'])
            ->withCount(['reads', 'reactions'])
            ->findOrFail($id);

        if (!$this->isManager() && (
            !$this->isVisible($announcement)
            || !$this->announcementVisibleToEmployee($announcement, auth()->user()->employee, $this->userRoles())
        )) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Opening the detail counts as a read.
        $this->recordRead($announcement->id);

        return response()->json(['announcement' => $announcement]);
    }

    /** Mark an announcement as read by the current employee (idempotent). */
    public function markRead(int $id): JsonResponse
    {
        Announcement::findOrFail($id);
        $this->recordRead($id);
        return response()->json(['message' => 'Marked as read.']);
    }

    private function recordRead(int $announcementId): void
    {
        $empId = $this->employeeId();
        if (!$empId) return;
        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcementId, 'employee_id' => $empId],
            ['read_at' => now()],
        );
    }

    /** Toggle a reaction on an announcement for the current employee. */
    public function react(Request $request, int $id): JsonResponse
    {
        $empId = $this->employeeId();
        if (!$empId) return response()->json(['message' => 'Only employees can react.'], 422);

        Announcement::findOrFail($id);
        $emoji = $request->input('emoji', '👍');

        $existing = AnnouncementReaction::where('announcement_id', $id)->where('employee_id', $empId)->first();
        if ($existing && $existing->emoji === $emoji) {
            $existing->delete();
            $reacted = false;
        } else {
            AnnouncementReaction::updateOrCreate(
                ['announcement_id' => $id, 'employee_id' => $empId],
                ['emoji' => $emoji],
            );
            $reacted = true;
        }

        return response()->json([
            'reacted' => $reacted,
            'count'   => AnnouncementReaction::where('announcement_id', $id)->count(),
        ]);
    }

    /**
     * HR engagement report: reach, read count/rate, and who has / hasn't read.
     */
    public function readStats(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $announcement = Announcement::findOrFail($id);

        $audienceIds = $this->notifications->resolveAudience(
            $announcement->audience_type ?? 'all',
            $announcement->target_department_ids,
            $announcement->target_roles,
            true,
            $announcement->target_employee_ids,
        );
        $reach = $audienceIds->count();

        $readers = AnnouncementRead::with('employee:id,first_name,last_name,employee_code')
            ->where('announcement_id', $id)->get()
            ->map(fn($r) => [
                'employee_id'   => $r->employee_id,
                'employee_name' => $r->employee ? trim($r->employee->first_name . ' ' . $r->employee->last_name) : '—',
                'read_at'       => $r->read_at?->toDateTimeString(),
            ]);

        $readIds   = $readers->pluck('employee_id')->all();
        $unreadIds = $audienceIds->reject(fn($i) => in_array($i, $readIds, true));

        $unread = \App\Models\Employee::whereIn('id', $unreadIds)
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn($e) => [
                'employee_id'   => $e->id,
                'employee_name' => trim($e->first_name . ' ' . $e->last_name),
                'employee_code' => $e->employee_code,
            ]);

        $readCount = $readers->count();
        return response()->json([
            'announcement'   => ['id' => $announcement->id, 'title' => $announcement->title],
            'reach'          => $reach,
            'read_count'     => $readCount,
            'read_rate'      => $reach > 0 ? round($readCount / $reach * 100, 1) : 0,
            'reaction_count' => AnnouncementReaction::where('announcement_id', $id)->count(),
            'readers'        => $readers->values(),
            'unread'         => $unread->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $data = $request->validate([
            'category_id'  => 'nullable|exists:announcement_categories,id',
            'title'        => 'required|string|max:200',
            'title_ar'     => 'nullable|string|max:200',
            'body'         => 'required|string',
            'body_ar'      => 'nullable|string',
            'priority'     => 'nullable|in:normal,high,urgent',
            'audience_type'=> 'nullable|in:all,departments,roles,employees',
            'target_department_ids'   => 'nullable',
            'target_roles'            => 'nullable',
            'target_employee_ids'     => 'nullable',
            'is_pinned'    => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date|after:now',
            'expires_at'   => 'nullable|date|after_or_equal:today',
            'attachment'   => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $scheduled = $data['scheduled_at'] ?? null;
        $publishNow = $request->has('is_published') ? $request->boolean('is_published') : true;
        $targetEmployeeIds = $this->arr($request->input('target_employee_ids'));
        if (($data['audience_type'] ?? 'all') === 'employees' && empty($targetEmployeeIds)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'target_employee_ids' => ['Select at least one employee.'],
            ]);
        }
        // A scheduled announcement starts unpublished; the cron flips it on.
        if ($scheduled) $publishNow = false;

        $announcement = new Announcement([
            'category_id'   => $data['category_id'] ?? null,
            'title'         => $data['title'],
            'title_ar'      => $data['title_ar'] ?? null,
            'body'          => $data['body'],
            'body_ar'       => $data['body_ar'] ?? null,
            'priority'      => $data['priority'] ?? 'normal',
            'audience_type' => $data['audience_type'] ?? 'all',
            'target_department_ids' => $this->arr($request->input('target_department_ids')),
            'target_roles'          => $this->arr($request->input('target_roles')),
            'target_employee_ids'   => $targetEmployeeIds,
            'is_pinned'     => $request->boolean('is_pinned'),
            'is_published'  => $publishNow,
            'scheduled_at'  => $scheduled,
            'expires_at'    => $data['expires_at'] ?? null,
            'created_by'    => auth()->id(),
        ]);
        $announcement->published_at = $publishNow ? now() : null;

        if ($request->hasFile('attachment')) {
            $this->attachFile($announcement, $request->file('attachment'));
        }

        $announcement->save();

        // Notify the audience immediately if published now.
        if ($publishNow) {
            $this->fanOut($announcement);
        }

        return response()->json([
            'message'      => $scheduled ? 'Announcement scheduled.' : 'Announcement published.',
            'announcement' => $announcement->load(['category', 'creator:id,name']),
        ], 201);
    }

    /** Send in-app and email notifications to the announcement's audience. */
    private function fanOut(Announcement $a): void
    {
        $ids = $this->notifications->resolveAudience(
            $a->audience_type ?? 'all',
            $a->target_department_ids,
            $a->target_roles,
            true,
            $a->target_employee_ids,
        );
        $summary = \Illuminate\Support\Str::limit(strip_tags($a->body), 120);
        $this->notifications->notifyMany(
            $ids, 'announcement', $a->title,
            $summary,
            '/announcements', $a->id,
        );
        $this->notifications->emailMany(
            $ids, 'announcement', $a->title,
            $a->body,
            rtrim((string) config('app.frontend_url'), '/') . '/announcements',
            $a->title_ar,
            $a->body_ar,
        );
    }

    /** Normalise a JSON-or-array input into a clean array (or null). */
    private function arr($value): ?array
    {
        if (is_array($value)) return array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return array_values(array_filter($decoded));
        }
        return null;
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $announcement = Announcement::findOrFail($id);

        $data = $request->validate([
            'category_id'  => 'nullable|exists:announcement_categories,id',
            'title'        => 'sometimes|string|max:200',
            'title_ar'     => 'nullable|string|max:200',
            'body'         => 'sometimes|string',
            'body_ar'      => 'nullable|string',
            'priority'     => 'nullable|in:normal,high,urgent',
            'audience_type'=> 'nullable|in:all,departments,roles,employees',
            'target_department_ids' => 'nullable',
            'target_roles'          => 'nullable',
            'target_employee_ids'   => 'nullable',
            'is_pinned'    => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date',
            'expires_at'   => 'nullable|date',
            'attachment'   => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $wasPublished = $announcement->is_published;
        $announcement->fill($data);

        if ($request->has('audience_type'))         $announcement->audience_type = $data['audience_type'] ?? 'all';
        if ($request->has('target_department_ids')) $announcement->target_department_ids = $this->arr($request->input('target_department_ids'));
        if ($request->has('target_roles'))          $announcement->target_roles = $this->arr($request->input('target_roles'));
        if ($request->has('target_employee_ids'))   $announcement->target_employee_ids = $this->arr($request->input('target_employee_ids'));
        if ($request->has('is_pinned'))    $announcement->is_pinned = $request->boolean('is_pinned');
        if ($request->has('is_published')) $announcement->is_published = $request->boolean('is_published');

        if ($announcement->audience_type === 'employees' && empty($announcement->target_employee_ids)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'target_employee_ids' => ['Select at least one employee.'],
            ]);
        }

        // Stamp published_at the first time it goes live.
        if (!$wasPublished && $announcement->is_published && !$announcement->published_at) {
            $announcement->published_at = now();
        }

        if ($request->hasFile('attachment')) {
            $this->attachFile($announcement, $request->file('attachment'), true);
        }

        $announcement->save();

        // Fan out notifications if this update is what first published it.
        if (!$wasPublished && $announcement->is_published) {
            $this->fanOut($announcement);
        }

        return response()->json([
            'message'      => 'Announcement updated.',
            'announcement' => $announcement->load(['category', 'creator:id,name']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $announcement = Announcement::findOrFail($id);
        if ($announcement->attachment_path) {
            Storage::disk('public')->delete($announcement->attachment_path);
        }
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    public function downloadAttachment(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $announcement = Announcement::findOrFail($id);

        if (!$announcement->attachment_path || !Storage::disk('public')->exists($announcement->attachment_path)) {
            return response()->json(['message' => 'No attachment.'], 404);
        }

        $mime = $announcement->attachment_mime
            ?: Storage::disk('public')->mimeType($announcement->attachment_path)
            ?: 'application/octet-stream';
        $previewableMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/avif',
        ];

        if ($request->boolean('inline') && in_array(strtolower($mime), $previewableMimes, true)) {
            $filename = str_replace(["\r", "\n", '"'], '', basename($announcement->attachment_name ?: 'attachment'));

            return Storage::disk('public')->response(
                $announcement->attachment_path,
                $filename,
                [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'inline; filename="'.$filename.'"',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        }

        return Storage::disk('public')->download(
            $announcement->attachment_path,
            $announcement->attachment_name ?: 'attachment'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function attachFile(Announcement $a, $file, bool $replace = false): void
    {
        if ($replace && $a->attachment_path) {
            Storage::disk('public')->delete($a->attachment_path);
        }
        $a->attachment_path = $file->store('announcements', 'public');
        $a->attachment_name = $file->getClientOriginalName();
        $a->attachment_mime = $file->getMimeType();
        $a->attachment_size = $file->getSize();
    }

    private function isVisible(Announcement $a): bool
    {
        return $a->is_published
            && (!$a->expires_at || $a->expires_at->gte(now()->startOfDay()));
    }

    private function announcementVisibleToEmployee(Announcement $announcement, ?Employee $employee, array $roleNames): bool
    {
        $audienceType = $announcement->audience_type ?: 'all';
        if ($audienceType === 'all') {
            return true;
        }

        if ($audienceType === 'departments') {
            $departmentId = $employee?->department_id;
            $targetDepartmentIds = array_map('intval', $announcement->target_department_ids ?? []);
            return $departmentId && in_array((int) $departmentId, $targetDepartmentIds, true);
        }

        if ($audienceType === 'roles') {
            $targetRoles = array_map('strval', $announcement->target_roles ?? []);
            return count(array_intersect(array_map('strval', $roleNames), $targetRoles)) > 0;
        }

        if ($audienceType === 'employees') {
            $targetEmployeeIds = array_map('intval', $announcement->target_employee_ids ?? []);
            return $employee && in_array((int) $employee->id, $targetEmployeeIds, true);
        }

        return false;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 1;
        while (AnnouncementCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
