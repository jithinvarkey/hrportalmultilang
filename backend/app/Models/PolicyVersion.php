<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received version of a policy (currently only from the QMS).
 * status: pending (waiting for HR) → published (live) → superseded; or discarded.
 */
class PolicyVersion extends Model
{
    public const PENDING = 'pending';
    public const PUBLISHED = 'published';
    public const SUPERSEDED = 'superseded';
    public const DISCARDED = 'discarded';

    protected $hidden = ['attachment_path'];

    protected $fillable = [
        'policy_id', 'version', 'status', 'title', 'content', 'document_type', 'document_number',
        'owner_department', 'effective_date', 'review_date', 'expiry_date', 'change_summary',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'received_from', 'received_at', 'published_at', 'published_by',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'review_date'    => 'date:Y-m-d',
        'expiry_date'    => 'date:Y-m-d',
        'received_at'    => 'datetime',
        'published_at'   => 'datetime',
    ];

    protected $appends = ['has_attachment'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function getHasAttachmentAttribute(): bool
    {
        return (bool) $this->attachment_path;
    }
}
