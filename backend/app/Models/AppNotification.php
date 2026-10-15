<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generic in-app notification delivered to a single employee.
 */
class AppNotification extends Model
{
    protected $fillable = ['employee_id', 'type', 'title', 'body', 'link', 'ref_id', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }

    public function scopeUnread($q) { return $q->whereNull('read_at'); }
}
