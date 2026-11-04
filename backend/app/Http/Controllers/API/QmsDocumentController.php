<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Models\PolicyCategory;
use App\Models\PolicyVersion;
use App\Services\Communications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Inbound QMS document hand-off (server-to-server, X-Integration-Key).
 *
 * POST /integrations/qms/documents                    receive an approved document / new version
 * GET  /integrations/qms/documents/{externalId}       HR status (pending / published / live version)
 * POST /integrations/qms/documents/{externalId}/obsolete   withdraw it
 *
 * A first-time document becomes an unpublished policy waiting for HR. A new version of a
 * document that is already live is stored as a pending PolicyVersion — employees keep
 * seeing the current version until HR publishes the new one (PolicyController::publish).
 */
class QmsDocumentController extends Controller
{
    private const SOURCE = 'qms';
    private const HR_ROLES = ['super_admin', 'hr_manager', 'hr_staff'];

    /** QMS document type → HR document_type label (matches existing values; 'Form' enables downloads) */
    private const TYPES = [
        'policy' => 'Policy', 'procedure' => 'Procedure', 'work_instruction' => 'Work Instruction',
        'form' => 'Form', 'template' => 'Template', 'manual' => 'Manual',
        'specification' => 'Specification', 'report' => 'Report', 'other' => 'Others',
    ];

    /** QMS document type → HR policy category (created on first use) */
    private const CATEGORIES = [
        'policy' => 'QMS Policies', 'procedure' => 'QMS Procedures', 'work_instruction' => 'QMS Work Instructions',
        'form' => 'QMS Forms', 'template' => 'QMS Templates', 'manual' => 'QMS Manuals',
        'specification' => 'QMS Specifications', 'report' => 'QMS Reports', 'other' => 'QMS Other Documents',
    ];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id'     => 'required|string|max:64',
            'document_number' => 'nullable|string|max:50',
            'title'           => 'required|string|max:200',
            'description'     => 'nullable|string',
            'type'            => 'required|string|max:50',
            'department'      => 'nullable|string|max:150',
            'version'         => 'required|string|max:20',
            'change_summary'  => 'nullable|string',
            'effective_date'  => 'nullable|date',
            'review_date'     => 'nullable|date',
            'expiry_date'     => 'nullable|date',
            'sent_by'         => 'nullable|string|max:150',
            // PDF, Word, Excel, images
            'file'            => 'nullable|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp',
        ]);

        $type = strtolower(trim($data['type']));
        $fields = [
            'title'            => $data['title'],
            'content'          => $data['description'] ?? null,
            'document_type'    => self::TYPES[$type] ?? 'Others',
            'document_number'  => $data['document_number'] ?? null,
            'owner_department' => $data['department'] ?? null,
            'effective_date'   => $data['effective_date'] ?? null,
            'review_date'      => $data['review_date'] ?? null,
            'expiry_date'      => $data['expiry_date'] ?? null,
        ];
        $file = $request->file('file');

        return DB::transaction(function () use ($data, $type, $fields, $file) {
            $policy = Policy::where('source', self::SOURCE)->where('external_id', $data['external_id'])->lockForUpdate()->first();
            $isNew = !$policy;

            if ($isNew) {
                // Brand-new document: unpublished until HR publishes it
                $policy = new Policy($fields + [
                    'source'                   => self::SOURCE,
                    'external_id'              => $data['external_id'],
                    'category_id'              => $this->categoryFor($type)->id,
                    'version'                  => $data['version'],
                    'audience_type'            => 'all',
                    'requires_acknowledgement' => true,
                    'is_published'             => false,
                    'status'                   => 'pending_publication',
                    'received_at'              => now(),
                ]);
                $policy->save();
            } elseif (!$policy->is_published) {
                // Never published yet: HR hasn't acted, so just refresh what's waiting
                $policy->fill($fields + ['version' => $data['version'], 'status' => 'pending_publication', 'received_at' => now()]);
                $policy->save();
            } elseif ($policy->version === $data['version']) {
                // Same version as the live one — only metadata can change; nothing to publish
                return response()->json($this->statusPayload($policy->fresh('pendingVersion'), 'Version ' . $data['version'] . ' is already published.'));
            }

            // Any older pending version is replaced by this one
            $policy->versions()->where('status', PolicyVersion::PENDING)->get()
                ->each(fn(PolicyVersion $v) => $this->discard($v));

            $version = new PolicyVersion($fields + [
                'version'        => $data['version'],
                'status'         => PolicyVersion::PENDING,
                'change_summary' => $data['change_summary'] ?? null,
                'received_from'  => $data['sent_by'] ?? 'QMS',
                'received_at'    => now(),
            ]);
            if ($file) $this->storeFile($version, $file);
            $policy->versions()->save($version);

            // First version: the policy row shows it to HR straight away (still unpublished)
            if (!$policy->is_published) {
                $oldPath = $policy->attachment_path;
                $policy->forceFill([
                    'attachment_path' => $version->attachment_path, 'attachment_name' => $version->attachment_name,
                    'attachment_mime' => $version->attachment_mime, 'attachment_size' => $version->attachment_size,
                ])->save();
                // The replaced, never-published file is no longer referenced anywhere
                if ($oldPath && $oldPath !== $version->attachment_path
                    && !PolicyVersion::where('attachment_path', $oldPath)->where('status', '!=', PolicyVersion::DISCARDED)->exists()) {
                    Storage::disk('local')->delete($oldPath);
                }
            }

            $this->notifyHr($policy, $data['version'], $isNew || !$policy->is_published);

            return response()->json($this->statusPayload($policy->fresh('pendingVersion'),
                $isNew ? 'Received — waiting for HR to publish.' : 'New version received — waiting for HR to publish.'), $isNew ? 201 : 200);
        });
    }

    public function show(string $externalId): JsonResponse
    {
        $policy = Policy::with('pendingVersion')->where('source', self::SOURCE)->where('external_id', $externalId)->first();
        return $policy
            ? response()->json($this->statusPayload($policy))
            : response()->json(['message' => 'Not found in HR portal.', 'hr_status' => 'not_sent'], 404);
    }

    public function obsolete(string $externalId): JsonResponse
    {
        $policy = Policy::where('source', self::SOURCE)->where('external_id', $externalId)->first();
        if (!$policy) return response()->json(['message' => 'Not found in HR portal.'], 404);

        DB::transaction(function () use ($policy) {
            $policy->versions()->where('status', PolicyVersion::PENDING)->get()->each(fn($v) => $this->discard($v));
            $policy->update(['is_published' => false, 'status' => 'obsolete']);
        });

        return response()->json($this->statusPayload($policy->fresh('pendingVersion'), 'Withdrawn from the HR portal.'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function statusPayload(Policy $policy, ?string $message = null): array
    {
        $pending = $policy->pendingVersion;
        $hrStatus = match (true) {
            $policy->status === 'obsolete' => 'withdrawn',
            (bool) $pending                => 'pending',
            (bool) $policy->is_published   => 'published',
            default                        => 'pending',
        };

        return array_filter([
            'message'         => $message,
            'hr_policy_id'    => $policy->id,
            'hr_status'       => $hrStatus,
            'live_version'    => $policy->is_published ? $policy->version : null,
            'pending_version' => $pending?->version,
            'received_at'     => optional($pending?->received_at ?? $policy->received_at)->toIso8601String(),
        ], fn($v) => $v !== null);
    }

    private function categoryFor(string $type): PolicyCategory
    {
        $name = self::CATEGORIES[$type] ?? self::CATEGORIES['other'];
        $slug = 'qms-' . str_replace('_', '-', array_key_exists($type, self::CATEGORIES) ? $type : 'other');

        return PolicyCategory::firstOrCreate(['slug' => $slug], [
            'name' => $name, 'icon' => 'verified', 'audience_type' => 'all',
            'sort_order' => 100, 'is_active' => true,
        ]);
    }

    private function storeFile(PolicyVersion $v, UploadedFile $file): void
    {
        $v->attachment_path = $file->store('policies', 'local');
        $v->attachment_name = $file->getClientOriginalName();
        $v->attachment_mime = $file->getMimeType();
        $v->attachment_size = $file->getSize();
    }

    /** Drop a pending version and its file — unless the policy row still points at that file */
    private function discard(PolicyVersion $v): void
    {
        $inUse = $v->attachment_path && Policy::where('id', $v->policy_id)->where('attachment_path', $v->attachment_path)->exists();
        if ($v->attachment_path && !$inUse) Storage::disk('local')->delete($v->attachment_path);
        $v->update(['status' => PolicyVersion::DISCARDED, 'attachment_path' => $inUse ? $v->attachment_path : null]);
    }

    private function notifyHr(Policy $policy, string $version, bool $firstVersion): void
    {
        rescue(function () use ($policy, $version, $firstVersion) {
            $ids = $this->notifications->resolveAudience('roles', null, self::HR_ROLES);
            $title = $firstVersion
                ? __('QMS document ready to publish')
                : __('New QMS version ready to publish');
            $body = __(':title (v:version) was approved in the QMS and is waiting for you to publish it.', [
                'title' => $policy->title, 'version' => $version,
            ]);
            $this->notifications->notifyMany($ids, 'policy', $title, $body, '/policies', $policy->id);
        }, null, true);
    }
}
