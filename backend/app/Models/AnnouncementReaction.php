<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single emoji reaction by an employee on an announcement.
 */
class AnnouncementReaction extends Model
{
    protected $fillable = ['announcement_id', 'employee_id', 'emoji'];

    public function announcement(): BelongsTo { return $this->belongsTo(Announcement::class); }
    public function employee(): BelongsTo     { return $this->belongsTo(Employee::class); }
}
