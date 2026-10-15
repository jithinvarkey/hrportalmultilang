<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'requires_acknowledgement' => 'boolean',
        'mandatory'                => 'boolean',
        'is_published'             => 'boolean',
        'target_department_ids'     => 'array',
        'effective_date'           => 'date',
        'review_date'              => 'date',
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

    public function getHasAttachmentAttribute(): bool
    {
        return (bool) $this->attachment_path;
    }
}
