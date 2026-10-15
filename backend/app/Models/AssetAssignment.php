<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records who held an asset during a specific period.
 * return_date = null means currently assigned.
 */
class AssetAssignment extends Model
{
    protected $fillable = [
        'asset_id', 'employee_id', 'assigned_date', 'return_date',
        'condition_at_assign', 'condition_at_return', 'notes',
        'assigned_by', 'returned_to',
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'return_date'   => 'date',
    ];

    public function asset(): BelongsTo    { return $this->belongsTo(Asset::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function returnedTo(): BelongsTo { return $this->belongsTo(User::class, 'returned_to'); }
}
