<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that an employee has read/acknowledged a policy (one per employee
 * per policy).
 */
class PolicyAcknowledgement extends Model
{
    protected $fillable = ['policy_id', 'employee_id', 'policy_version', 'ip_address', 'user_agent', 'acknowledged_at'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
