<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read receipt: records that an employee viewed an announcement.
 */
class AnnouncementRead extends Model
{
    protected $fillable = ['announcement_id', 'employee_id', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    public function announcement(): BelongsTo { return $this->belongsTo(Announcement::class); }
    public function employee(): BelongsTo     { return $this->belongsTo(Employee::class); }
}
