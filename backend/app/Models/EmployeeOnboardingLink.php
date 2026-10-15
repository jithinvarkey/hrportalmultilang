<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeOnboardingLink extends Model
{
    protected $fillable = [
        'employee_id',
        'token_hash',
        'expires_at',
        'submitted_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
