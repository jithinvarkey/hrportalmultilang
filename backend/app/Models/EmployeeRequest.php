<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EmployeeRequest extends Model {
    protected $table = 'employee_requests';
    protected $appends = ['has_completion_file'];
    protected $fillable = [
        'reference','employee_id','leave_request_id','linked_service','request_type_id','status','details','hr_notes',
        'rejection_reason','required_by','copies_needed','attachment_path',
        'manager_approved_by','manager_approved_at','assigned_to',
        'completed_by','completed_at','rejected_by','rejected_at',
        'due_date','is_overdue','completion_file','completion_notes'
    ];
    protected $casts = [
        'required_by'=>'date','due_date'=>'date',
        'manager_approved_at'=>'datetime','completed_at'=>'datetime','rejected_at'=>'datetime',
        'is_overdue'=>'boolean',
    ];
    public function employee()        { return $this->belongsTo(Employee::class); }
    public function leaveRequest()    { return $this->belongsTo(LeaveRequest::class); }
    public function requestType()     { return $this->belongsTo(RequestType::class); }
    public function managerApprover() { return $this->belongsTo(User::class,'manager_approved_by'); }
    public function assignedTo()      { return $this->belongsTo(User::class,'assigned_to'); }
    public function completedBy()     { return $this->belongsTo(User::class,'completed_by'); }
    public function rejectedBy()      { return $this->belongsTo(User::class,'rejected_by'); }
    public function comments()        { return $this->hasMany(RequestComment::class,'request_id')->orderBy('created_at'); }

    public function getHasCompletionFileAttribute(): bool
    {
        return (bool) $this->completion_file && Storage::disk('public')->exists($this->completion_file);
    }
}
