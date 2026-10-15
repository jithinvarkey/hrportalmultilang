<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyRead;
use App\Models\PolicyCategory;
use App\Services\Communications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR policies: HR/Admin manage; all employees view and acknowledge.
 */
class PolicyController extends Controller
{
    private const MANAGER_ROLES = ['super_admin', 'hr_manager', 'hr_staff'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

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
        $user = auth()->user();

        return $user && (
            $user->hasAnyRole(self::MANAGER_ROLES)
            || $user->can('policies.manage')
        );
    }

    private function ensureManager(): ?JsonResponse
    {
        return $this->isManager()
            ? null
            : response()->json(['message' => 'You are not allowed to manage policies.'], 403);
    }

    private function employeeId(): ?int
    {
        return auth()->user()->employee?->id;
    }

    private function policyVisibleToCurrentUser(Policy $policy): bool
    {
        if ($this->isManager()) {
            return true;
        }

        $departmentId = auth()->user()->employee?->department_id;

        return $this->audienceIncludesDepartment(
            $policy->audience_type,
            $policy->target_department_ids,
            $departmentId,
        ) && (!$policy->category || $this->audienceIncludesDepartment(
            $policy->category->audience_type,
            $policy->category->target_department_ids,
            $departmentId,
        ));
    }

    private function audienceIncludesDepartment(?string $audienceType, ?array $departmentIds, ?int $departmentId): bool
    {
        if (($audienceType ?: 'all') === 'all') return true;

        return $departmentId
            && in_array((int) $departmentId, array_map('intval', $departmentIds ?? []), true);
    }

    /** Null means unrestricted; an array is the effective intersection of policy and category departments. */
    private function effectiveDepartmentIds(Policy $policy): ?array
    {
        $policyIds = ($policy->audience_type ?? 'all') === 'departments'
            ? array_map('intval', $policy->target_department_ids ?? [])
            : null;
        $category = $policy->category;
        $categoryIds = $category && ($category->audience_type ?? 'all') === 'departments'
            ? array_map('intval', $category->target_department_ids ?? [])
            : null;

        if ($policyIds === null) return $categoryIds;
        if ($categoryIds === null) return $policyIds;

        return array_values(array_intersect($policyIds, $categoryIds));
    }

    private function applyCategoryAudience($query, ?int $departmentId)
    {
        return $query->where(function ($w) use ($departmentId) {
            $w->where('audience_type', 'all')->orWhereNull('audience_type');
            if ($departmentId) {
                $w->orWhere(function ($d) use ($departmentId) {
                    $d->where('audience_type', 'departments')
                        ->where(function ($ids) use ($departmentId) {
                            $ids->whereJsonContains('target_department_ids', $departmentId)
                                ->orWhereJsonContains('target_department_ids', (string) $departmentId);
                        });
                });
            }
        });
    }

    private function applyPolicyAudience($query, Request $request)
    {
        if ($this->isManager() && !$request->department_id) {
            return $query;
        }

        $departmentId = $this->isManager()
            ? (int) $request->department_id
            : (auth()->user()->employee?->department_id ? (int) auth()->user()->employee->department_id : null);

        return $query->where(function ($w) use ($departmentId) {
            $w->where('audience_type', 'all')
                ->orWhereNull('audience_type');

            if ($departmentId) {
                $w->orWhere(function ($d) use ($departmentId) {
                    $d->where('audience_type', 'departments')
                        ->where(function ($dd) use ($departmentId) {
                            $dd->whereJsonContains('target_department_ids', (int) $departmentId)
                                ->orWhereJsonContains('target_department_ids', (string) $departmentId);
                        });
                });
            }
        });
    }

    private function normalizeAudience(Request $request): array
    {
        $audienceType = $request->input('audience_type', 'all');
        if ($audienceType !== 'departments') {
            return ['audience_type' => 'all', 'target_department_ids' => null];
        }

        $departmentIds = collect($request->input('target_department_ids', []))
            ->filter(fn($id) => $id !== null && $id !== '')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return ['audience_type' => 'departments', 'target_department_ids' => $departmentIds];
    }

    // ── Categories ────────────────────────────────────────────────────────

    public function categories(Request $request): JsonResponse
    {
        $departmentId = $this->isManager()
            ? ($request->department_id ? (int) $request->department_id : null)
            : (auth()->user()->employee?->department_id ? (int) auth()->user()->employee->department_id : null);
        $query = PolicyCategory::withCount('policies');
        if (!$this->isManager() || $departmentId) $this->applyCategoryAudience($query, $departmentId);

        return response()->json([
            'categories' => $query->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'audience_type' => 'nullable|in:all,departments',
            'target_department_ids' => 'nullable|array',
            'target_department_ids.*' => 'integer|exists:departments,id',
        ]);
        $audience = $this->normalizeAudience($request);
        if ($audience['audience_type'] === 'departments' && empty($audience['target_department_ids'])) {
            return response()->json(['message' => 'Select at least one department, or choose all departments.'], 422);
        }
        $data = array_merge($data, $audience);
        $data['slug']      = $this->uniqueSlug($data['name']);
        $data['is_active'] = true;

        return response()->json(['category' => PolicyCategory::create($data)], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $category = PolicyCategory::findOrFail($id);
        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_active'  => 'sometimes|boolean',
            'audience_type' => 'nullable|in:all,departments',
            'target_department_ids' => 'nullable|array',
            'target_department_ids.*' => 'integer|exists:departments,id',
        ]);
        if ($request->has('audience_type') || $request->has('target_department_ids')) {
            $audience = $this->normalizeAudience($request);
            if ($audience['audience_type'] === 'departments' && empty($audience['target_department_ids'])) {
                return response()->json(['message' => 'Select at least one department, or choose all departments.'], 422);
            }
            $data = array_merge($data, $audience);
        }
        $category->update($data);

        return response()->json(['category' => $category]);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;
        PolicyCategory::findOrFail($id)->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    // ── Policies ──────────────────────────────────────────────────────────

    /**
     * List policies. Employees see published ones; each carries an
     * `acknowledged` flag for the current employee.
     */
    public function index(Request $request): JsonResponse
    {
        $empId = $this->employeeId();

        // Load the complete category record rather than naming the visibility
        // columns here. This keeps policy listing available during a rolling
        // deployment before the accompanying category migration is applied.
        $query = Policy::with(['category', 'creator:id,name'])->withCount('reads')
            ->when(!$this->isManager(), fn($q) => $q->where('is_published', true))
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"));

        $manager = $this->isManager();
        if ($manager) {
            $this->applyPolicyAudience($query, $request);
        }

        if (!$manager || $request->department_id) {
            $departmentId = $manager
                ? (int) $request->department_id
                : (auth()->user()->employee?->department_id ? (int) auth()->user()->employee->department_id : null);
            $query->where(function ($categoryScope) use ($departmentId) {
                $categoryScope->whereNull('category_id')
                    ->orWhereHas('category', fn ($category) => $this->applyCategoryAudience($category, $departmentId));
            });
        }

        $policies = $query->orderBy('title')->get();
        if (!$this->isManager()) {
            $policies = $policies
                ->filter(fn($policy) => $this->policyVisibleToCurrentUser($policy))
                ->values();
        }

        // Annotate with the current employee's acknowledgement of the CURRENT
        // version (a prior-version ack no longer counts).
        $ackedIds = [];
        $readIds = $empId
            ? PolicyRead::where('employee_id', $empId)->whereIn('policy_id', $policies->pluck('id'))->pluck('policy_id')->all()
            : [];
        if ($empId) {
            foreach ($policies as $p) {
                $hasCurrent = PolicyAcknowledgement::where('policy_id', $p->id)
                    ->where('employee_id', $empId)
                    ->where('policy_version', $p->version)
                    ->exists();
                if ($hasCurrent) $ackedIds[] = $p->id;
            }
        }

        $policies->each(function ($p) use ($ackedIds, $readIds) {
            $p->acknowledged = in_array($p->id, $ackedIds, true);
            $p->is_read = in_array($p->id, $readIds, true);
        });

        return response()->json(['policies' => $policies]);
    }

    public function show(int $id): JsonResponse
    {
        $policy = Policy::with(['category', 'creator:id,name'])->withCount('reads')->findOrFail($id);

        if ((!$this->isManager() && !$policy->is_published) || !$this->policyVisibleToCurrentUser($policy)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $empId = $this->employeeId();
        if ($empId && !$this->isManager()) {
            PolicyRead::firstOrCreate(
                ['policy_id' => $policy->id, 'employee_id' => $empId],
                ['read_at' => now()],
            );
            $policy->is_read = true;
            $policy->reads_count = PolicyRead::where('policy_id', $policy->id)->count();
        }
        $policy->acknowledged = $empId
            ? PolicyAcknowledgement::where('policy_id', $id)
                ->where('employee_id', $empId)
                ->where('policy_version', $policy->version)
                ->exists()
            : false;

        return response()->json(['policy' => $policy]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $data = $request->validate([
            'category_id'              => 'nullable|exists:policy_categories,id',
            'audience_type'            => 'nullable|in:all,departments',
            'target_department_ids'     => 'nullable|array',
            'target_department_ids.*'   => 'integer|exists:departments,id',
            'title'                    => 'required|string|max:200',
            'content'                  => 'nullable|string',
            'version'                  => 'nullable|string|max:20',
            'effective_date'           => 'nullable|date',
            'review_date'              => 'nullable|date|after_or_equal:today',
            'requires_acknowledgement' => 'nullable|boolean',
            'mandatory'                => 'nullable|boolean',
            'is_published'             => 'nullable|boolean',
            'attachment'               => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $audience = $this->normalizeAudience($request);
        if ($audience['audience_type'] === 'departments' && empty($audience['target_department_ids'])) {
            return response()->json(['message' => 'Select at least one department, or choose all departments.'], 422);
        }

        $policy = new Policy([
            'category_id'              => $data['category_id'] ?? null,
            'audience_type'            => $audience['audience_type'],
            'target_department_ids'     => $audience['target_department_ids'],
            'title'                    => $data['title'],
            'content'                  => $data['content'] ?? null,
            'version'                  => $data['version'] ?? '1.0',
            'effective_date'           => $data['effective_date'] ?? null,
            'review_date'              => $data['review_date'] ?? null,
            'requires_acknowledgement' => $request->has('requires_acknowledgement') ? $request->boolean('requires_acknowledgement') : true,
            'mandatory'                => $request->boolean('mandatory'),
            'is_published'             => $request->has('is_published') ? $request->boolean('is_published') : true,
            'created_by'               => auth()->id(),
        ]);

        if ($request->hasFile('attachment')) {
            $this->attachFile($policy, $request->file('attachment'));
        }

        $policy->save();

        // Notify the related employees when a policy is published.
        if ($policy->is_published) {
            $this->notifyPolicyAudience(
                $policy,
                $policy->requires_acknowledgement ? 'requires your acknowledgement' : 'has been published'
            );
        }

        return response()->json([
            'message' => 'Policy created.',
            'policy'  => $policy->load(['category', 'creator:id,name']),
        ], 201);
    }

    /** Notify every targeted active employee about a policy. */
    private function notifyPolicyAudience(Policy $policy, string $action): void
    {
        $departmentIds = $this->effectiveDepartmentIds($policy->loadMissing('category'));
        $ids = $this->notifications->resolveAudience(
            $departmentIds === null ? 'all' : 'departments',
            $departmentIds,
            null
        );
        $emailMessage = "{$policy->title} {$action}.";
        $this->notifications->notifyMany(
            $ids, 'policy',
            "Policy: {$policy->title}",
            "“{$policy->title}” {$action}.",
            '/policies', $policy->id,
        );
        $this->notifications->emailMany(
            $ids, 'policy',
            $policy->title,
            $emailMessage,
            rtrim((string) config('app.frontend_url'), '/') . '/policies',
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $policy = Policy::findOrFail($id);

        $data = $request->validate([
            'category_id'              => 'nullable|exists:policy_categories,id',
            'audience_type'            => 'nullable|in:all,departments',
            'target_department_ids'     => 'nullable|array',
            'target_department_ids.*'   => 'integer|exists:departments,id',
            'title'                    => 'sometimes|string|max:200',
            'content'                  => 'nullable|string',
            'version'                  => 'nullable|string|max:20',
            'effective_date'           => 'nullable|date',
            'review_date'              => 'nullable|date',
            'requires_acknowledgement' => 'nullable|boolean',
            'mandatory'                => 'nullable|boolean',
            'is_published'             => 'nullable|boolean',
            'attachment'               => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $oldVersion = $policy->version;
        $wasPublished = (bool) $policy->is_published;
        $audience = $this->normalizeAudience($request);
        if ($audience['audience_type'] === 'departments' && empty($audience['target_department_ids'])) {
            return response()->json(['message' => 'Select at least one department, or choose all departments.'], 422);
        }
        $policy->fill($data);
        $policy->audience_type = $audience['audience_type'];
        $policy->target_department_ids = $audience['target_department_ids'];
        if ($request->has('requires_acknowledgement')) $policy->requires_acknowledgement = $request->boolean('requires_acknowledgement');
        if ($request->has('mandatory'))                $policy->mandatory = $request->boolean('mandatory');
        if ($request->has('is_published'))             $policy->is_published = $request->boolean('is_published');

        if ($request->hasFile('attachment')) {
            $this->attachFile($policy, $request->file('attachment'), true);
        }

        $policy->save();

        // A version bump does NOT delete prior acknowledgements (those are a
        // permanent audit record). Because the ack flag is version-specific,
        // employees are automatically prompted to re-acknowledge the new
        // version. Notify them so they know.
        $versionChanged = isset($data['version']) && $data['version'] !== $oldVersion;
        if ($versionChanged) {
            PolicyRead::where('policy_id', $policy->id)->delete();
        }
        $publishedNow = !$wasPublished && $policy->is_published;
        if ($publishedNow) {
            $this->notifyPolicyAudience(
                $policy,
                $policy->requires_acknowledgement ? 'requires your acknowledgement' : 'has been published'
            );
        } elseif ($versionChanged && $policy->is_published && $policy->requires_acknowledgement) {
            $this->notifyPolicyAudience($policy, 'updated to v' . $policy->version . ' - please re-acknowledge');
        }

        return response()->json([
            'message' => 'Policy updated.',
            'policy'  => $policy->load(['category', 'creator:id,name']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $policy = Policy::findOrFail($id);
        if ($policy->attachment_path) {
            Storage::disk('local')->delete($policy->attachment_path);
            Storage::disk('public')->delete($policy->attachment_path);
        }
        $policy->delete();

        return response()->json(['message' => 'Policy deleted.']);
    }

    public function downloadAttachment(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $policy = Policy::findOrFail($id);

        if ((!$this->isManager() && !$policy->is_published) || !$this->policyVisibleToCurrentUser($policy)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $disk = Storage::disk('local')->exists($policy->attachment_path ?? '') ? 'local' : 'public';

        if (!$policy->attachment_path || !Storage::disk($disk)->exists($policy->attachment_path)) {
            return response()->json(['message' => 'No attachment.'], 404);
        }

        if (!$this->isManager()) {
            $isForm = strtolower(trim((string) $policy->document_type)) === 'form';
            $isInlinePreview = $request->boolean('inline')
                || strtolower((string) $request->header('Sec-Fetch-Dest')) === 'empty';

            if (!$isInlinePreview) {
                if (!$isForm) {
                    return response()->json(['message' => 'Only form documents can be downloaded.'], 403);
                }

                return Storage::disk($disk)->download(
                    $policy->attachment_path,
                    $policy->attachment_name ?: 'form'
                );
            }

            if (strtolower((string) $policy->attachment_mime) !== 'application/pdf') {
                return response()->json(['message' => 'This attachment is not available in secure preview format.'], 403);
            }

            return Storage::disk($disk)->response($policy->attachment_path, 'policy.pdf', [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="policy.pdf"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Frame-Options' => 'SAMEORIGIN',
            ]);
        }

        $mime = $policy->attachment_mime
            ?: Storage::disk($disk)->mimeType($policy->attachment_path)
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
            $filename = str_replace(["\r", "\n", '"'], '', basename($policy->attachment_name ?: 'policy'));

            return Storage::disk($disk)->response(
                $policy->attachment_path,
                $filename,
                [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'inline; filename="'.$filename.'"',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        }

        return Storage::disk($disk)->download(
            $policy->attachment_path,
            $policy->attachment_name ?: 'policy'
        );
    }

    // ── Acknowledgement ───────────────────────────────────────────────────

    /**
     * Current employee acknowledges the CURRENT version of a policy.
     *
     * Acknowledgements are append-only and version-specific: a row is kept per
     * employee per policy version, with timestamp + IP + user-agent, so the
     * historical record (who acknowledged which revision, when) survives version
     * bumps — a compliance/audit requirement.
     */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $empId = $this->employeeId();
        if (!$empId) {
            return response()->json(['message' => 'Only employees can acknowledge policies.'], 422);
        }

        $policy = Policy::findOrFail($id);
        if (!$policy->is_published || !$this->policyVisibleToCurrentUser($policy)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $ack = PolicyAcknowledgement::updateOrCreate(
            ['policy_id' => $policy->id, 'employee_id' => $empId, 'policy_version' => $policy->version],
            [
                'ip_address'      => $request->ip(),
                'user_agent'      => Str::limit((string) $request->userAgent(), 250, ''),
                'acknowledged_at' => now(),
            ],
        );

        return response()->json([
            'message'         => 'Policy acknowledged.',
            'acknowledgement' => $ack,
        ]);
    }

    /**
     * HR view: who has / hasn't acknowledged a policy.
     */
    public function acknowledgements(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $policy = Policy::findOrFail($id);
        $data   = $this->buildAckReport($policy);

        return response()->json($data);
    }

    /**
     * Export the acknowledgement report for a policy as a CSV download (for
     * audits). Columns: status, employee, code, department, version, acknowledged_at.
     */
    public function exportAcknowledgements(int $id): StreamedResponse|JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $policy = Policy::findOrFail($id);
        $report = $this->buildAckReport($policy);

        $filename = 'policy-' . $policy->id . '-acknowledgements.csv';
        return response()->streamDownload(function () use ($report, $policy) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Status', 'Employee', 'Code', 'Department', 'Version', 'Acknowledged At']);
            foreach ($report['acknowledged'] as $r) {
                fputcsv($out, ['Acknowledged', $r['employee_name'], $r['employee_code'],
                    $r['department'] ?? '', $r['policy_version'], $r['acknowledged_at']]);
            }
            foreach ($report['pending'] as $r) {
                fputcsv($out, ['Pending', $r['employee_name'], $r['employee_code'],
                    $r['department'] ?? '', $policy->version, '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Send a reminder notification to every employee who hasn't acknowledged
     * the current version of the policy.
     */
    public function remindPending(int $id): JsonResponse
    {
        if ($deny = $this->ensureManager()) return $deny;

        $policy  = Policy::findOrFail($id);
        $report  = $this->buildAckReport($policy);
        $pendIds = collect($report['pending'])->pluck('employee_id')->all();

        $sent = $this->notifications->notifyMany(
            $pendIds, 'policy',
            "Reminder: {$policy->title}",
            "Please acknowledge “{$policy->title}” (v{$policy->version}).",
            '/policies', $policy->id,
        );

        return response()->json(['message' => "Reminder sent to {$sent} employees.", 'count' => $sent]);
    }

    /**
     * Build the version-aware acknowledgement report with department breakdown.
     *
     * @return array{policy:array, acknowledged:array, pending:array, ack_count:int, pending_count:int, by_department:array}
     */
    private function buildAckReport(Policy $policy): array
    {
        $departmentIds = $this->effectiveDepartmentIds($policy->loadMissing('category'));
        // Only acknowledgements of the CURRENT version count as "done".
        $acked = PolicyAcknowledgement::with('employee:id,first_name,last_name,employee_code,department_id')
            ->where('policy_id', $policy->id)
            ->where('policy_version', $policy->version)
            ->when($departmentIds !== null, function ($q) use ($departmentIds) {
                $q->whereHas('employee', fn($eq) => $eq->whereIn('department_id', $departmentIds));
            })
            ->get()
            ->map(fn($a) => [
                'employee_id'     => $a->employee_id,
                'employee_name'   => $a->employee ? trim($a->employee->first_name . ' ' . $a->employee->last_name) : '—',
                'employee_code'   => $a->employee?->employee_code,
                'department_id'   => $a->employee?->department_id,
                'department'      => null,
                'policy_version'  => $a->policy_version,
                'acknowledged_at' => $a->acknowledged_at?->toDateTimeString(),
            ]);

        $ackedIds = $acked->pluck('employee_id')->all();

        $pending = DB::table('employees')
            ->where('status', 'active')
            ->when($departmentIds !== null, function ($q) use ($departmentIds) {
                $q->whereIn('department_id', $departmentIds);
            })
            ->when(!empty($ackedIds), fn($q) => $q->whereNotIn('id', $ackedIds))
            ->select('id', 'first_name', 'last_name', 'employee_code', 'department_id')
            ->get()
            ->map(fn($e) => [
                'employee_id'   => $e->id,
                'employee_name' => trim($e->first_name . ' ' . $e->last_name),
                'employee_code' => $e->employee_code,
                'department_id' => $e->department_id,
                'department'    => null,
            ]);

        // Resolve department names once.
        $deptNames = DB::table('departments')->pluck('name', 'id');
        $acked   = $acked->map(function ($r) use ($deptNames) { $r['department'] = $deptNames[$r['department_id']] ?? null; return $r; });
        $pending = $pending->map(function ($r) use ($deptNames) { $r['department'] = $deptNames[$r['department_id']] ?? null; return $r; });

        // Per-department compliance breakdown.
        $byDept = collect($deptNames)->map(function ($name, $deptId) use ($acked, $pending) {
            $a = $acked->where('department_id', $deptId)->count();
            $p = $pending->where('department_id', $deptId)->count();
            $total = $a + $p;
            return [
                'department'   => $name,
                'acknowledged' => $a,
                'pending'      => $p,
                'rate'         => $total > 0 ? round($a / $total * 100, 1) : 0,
            ];
        })->filter(fn($d) => ($d['acknowledged'] + $d['pending']) > 0)->values();

        return [
            'policy'        => ['id' => $policy->id, 'title' => $policy->title, 'version' => $policy->version],
            'acknowledged'  => $acked->values()->all(),
            'pending'       => $pending->values()->all(),
            'ack_count'     => $acked->count(),
            'pending_count' => $pending->count(),
            'by_department' => $byDept->all(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function attachFile(Policy $p, $file, bool $replace = false): void
    {
        if ($replace && $p->attachment_path) {
            Storage::disk('local')->delete($p->attachment_path);
            Storage::disk('public')->delete($p->attachment_path);
        }
        $p->attachment_path = $file->store('policies', 'local');
        $p->attachment_name = $file->getClientOriginalName();
        $p->attachment_mime = $file->getMimeType();
        $p->attachment_size = $file->getSize();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 1;
        while (PolicyCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
