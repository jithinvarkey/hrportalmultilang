<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model {
    public function isAnnual(): bool
    {
        return (bool) $this->is_annual || strtoupper((string) $this->code) === 'AL'
            || str_contains(strtolower((string) $this->name), 'annual');
    }

    public static function annualPolicyType(): ?self
    {
        return static::where(function ($query) {
            $query->where('is_annual', true)->orWhere('code', 'AL')->orWhere('name', 'like', '%Annual%');
        })->orderByRaw("CASE WHEN UPPER(code) = 'AL' THEN 0 ELSE 1 END")
            ->orderByDesc('is_annual')->orderBy('name')->orderBy('id')->first();
    }

    protected $fillable = [
        'name','code','days_allowed','is_paid','carry_forward',
        'max_carry_forward','carry_forward_all','requires_document','is_active','description',
        'is_hourly','monthly_hours_limit','exempt_department_codes',
        'skip_manager_approval','is_annual',
    ];
    protected $casts = [
        'is_paid'                  => 'boolean',
        'carry_forward'            => 'boolean',
        'carry_forward_all'        => 'boolean',
        'requires_document'        => 'boolean',
        'is_active'                => 'boolean',
        'skip_manager_approval'    => 'boolean',
        'is_hourly'                => 'boolean',
        'is_annual'                => 'boolean',
        'exempt_department_codes'  => 'array',
    ];
    public function allocations() { return $this->hasMany(LeaveAllocation::class); }
    public function requests()    { return $this->hasMany(LeaveRequest::class); }
}
