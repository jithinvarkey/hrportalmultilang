<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * An HR announcement, filed under a category, optionally with an attachment.
 */
class Announcement extends Model
{
    protected $fillable = [
        'category_id', 'title', 'title_ar', 'body', 'body_ar', 'priority',
        'audience_type', 'target_department_ids', 'target_roles', 'target_employee_ids',
        'is_pinned', 'is_published', 'published_at', 'scheduled_at', 'expires_at',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'created_by',
    ];

    protected $casts = [
        'is_pinned'             => 'boolean',
        'is_published'          => 'boolean',
        'published_at'          => 'datetime',
        'scheduled_at'          => 'datetime',
        'expires_at'            => 'date',
        'target_department_ids' => 'array',
        'target_roles'          => 'array',
        'target_employee_ids'   => 'array',
    ];

    protected $appends = ['attachment_url', 'has_attachment'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnnouncementCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(AnnouncementReaction::class);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? Storage::disk('public')->url($this->attachment_path)
            : null;
    }

    public function getHasAttachmentAttribute(): bool
    {
        return (bool) $this->attachment_path;
    }

    /** Published, not expired, and not scheduled for the future. */
    public function scopeVisible($q)
    {
        return $q->where('is_published', true)
            ->where(function ($w) {
                $w->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->where(function ($w) {
                $w->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            });
    }

    /**
     * Limit to announcements an employee should see based on audience targeting.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  \App\Models\Employee|null $employee
     * @param  array $roleNames  the employee's user role names
     */
    public function scopeForAudience($q, ?Employee $employee, array $roleNames = [])
    {
        $deptId = $employee?->department_id;

        return $q->where(function ($w) use ($deptId, $roleNames) {
            $w->where('audience_type', 'all')
              ->orWhereNull('audience_type');

            if ($deptId) {
                $w->orWhere(function ($d) use ($deptId) {
                    $d->where('audience_type', 'departments')
                      ->where(function ($dd) use ($deptId) {
                          $dd->whereJsonContains('target_department_ids', (int) $deptId)
                             ->orWhereJsonContains('target_department_ids', (string) $deptId);
                      });
                });
            }

            if (!empty($roleNames)) {
                $w->orWhere(function ($r) use ($roleNames) {
                    $r->where('audience_type', 'roles')
                      ->where(function ($rr) use ($roleNames) {
                          foreach ($roleNames as $role) {
                              $rr->orWhereJsonContains('target_roles', $role);
                          }
                      });
                });
            }

            if ($employee) {
                $w->orWhere(function ($e) use ($employee) {
                    $e->where('audience_type', 'employees')
                      ->whereJsonContains('target_employee_ids', (int) $employee->id);
                });
            }
        });
    }
}
