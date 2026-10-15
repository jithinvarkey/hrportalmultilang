<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model {
    protected $fillable = [
        'employee_id','leave_type_id',
        'start_date','start_time','end_date','end_time',
        'total_days','total_hours',
        'is_half_day','half_day_period',
        'requires_exit_reentry','requires_ticket','ticket_year','ticket_count','destination_country',
        'status','reason','rejection_reason',
        'approved_by','approved_at','document_path',
        'manager_approved_by','manager_approved_at','manager_notes','hr_notes','rejected_stage',
        'cancelled_at',
    ];
    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'approved_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_days'  => 'decimal:1',
        'is_half_day'           => 'boolean',
        'requires_exit_reentry' => 'boolean',
        'requires_ticket'       => 'boolean',
        'ticket_year'           => 'integer',
        'ticket_count'          => 'integer',
        'total_hours' => 'decimal:2',
    ];
    /** is_annual, or the AL code, or an "Annual ..." name - matched the same way everywhere. */
    private static function annualLeaveType(): \Closure
    {
        return fn ($type) => $type->where('is_annual', true)
            ->orWhere('code', 'AL')
            ->orWhere('name', 'like', '%Annual%');
    }

    public function scopeApprovedForBalance($query) {
        return $query->where(function ($status) {
            $status->where('status', 'approved')->orWhere(function ($manager) {
                $manager->where('status', 'manager_approved')
                    ->whereNotNull('manager_approved_at')
                    ->whereHas('leaveType', self::annualLeaveType());
            });
        });
    }

    public function scopePendingForBalance($query) {
        return $query->where(function ($status) {
            $status->where('status', 'pending')->orWhere(function ($manager) {
                $manager->where('status', 'manager_approved')->where(function ($unapproved) {
                    $unapproved->whereNull('manager_approved_at')
                        ->orWhereDoesntHave('leaveType', self::annualLeaveType());
                });
            });
        });
    }

    public function employee()  { return $this->belongsTo(Employee::class); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
    public function approver()  { return $this->belongsTo(User::class,'approved_by'); }
    public function managerApprover()  { return $this->belongsTo(User::class,'manager_approved_by'); }
    public function ticketPassengers() { return $this->hasMany(LeaveTicketPassenger::class); }
    public function scopePending($q) { return $q->where('status','pending'); }
}
