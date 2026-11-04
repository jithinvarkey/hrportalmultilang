<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An HR policy document, visible to all employees and optionally requiring
 * acknowledgement.
 */
class Policy extends Model
{
    protected $hidden = ['attachment_path'];

    protected $fillable = [
        'category_id', 'legacy_document_id', 'legacy_category_id', 'legacy_subcategory_id',
        'audience_type', 'target_department_ids', 'title', 'content', 'version', 'document_type', 'effective_date', 'review_date',
        'requires_acknowledgement', 'mandatory', 'is_published', 'status',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'created_by', 'legacy_created_by', 'legacy_modified_by', 'approved_by', 'approved_at',
        // QMS hand-off + per-employee audience
        'source', 'external_id', 'document_number', 'owner_department', 'expiry_date',
        'target_employee_ids', 'received_at',
    ];

    protected $casts = [
        'requires_acknowledgement' => 'boolean',
        'mandatory'                => 'boolean',
        'is_published'             => 'boolean',
        'target_department_ids'     => 'array',
        'target_employee_ids'      => 'array',
        'expiry_date'              => 'date:Y-m-d',
        'received_at'              => 'datetime',
        'effective_date'           => 'date:Y-m-d',
        'review_date'              => 'date:Y-m-d',
        'approved_at'              => 'datetime',
    ];

    protected $appends = ['has_attachment'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PolicyCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class, 'policy_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PolicyRead::class);
    }

    /** Every version received from the QMS (newest first) */
    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class)->orderByDesc('id');
    }

    /** A received version waiting for HR to publish it (the live version stays on this row) */
    public function pendingVersion(): HasOne
    {
        return $this->hasOne(PolicyVersion::class)->where('status', PolicyVersion::PENDING)->latestOfMany();
    }

    public function getHasAttachmentAttribute(): bool
    {
        return (bool) $this->attachment_path;
    }
}
