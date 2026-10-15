<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Employee extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['first_name','last_name','email','status','department_id','salary'])->logOnlyDirty();
    }

    protected $fillable = [
        'user_id','department_id','unit_id','designation_id','manager_id',
        'employee_code','prefix','first_name','last_name','arabic_name','email',
        'phone','work_phone','extension',
        'dob','gender','marital_status','nationality','hire_date','confirmation_date',
        'joining_announcement_sent_at',
        'termination_date','employment_type','mode_of_employment','role',
        'status','probation_period','years_of_experience',
        'salary','housing_allowance','transport_allowance','other_allowances','mobile_allowance','food_allowance',
        'avatar','address','city','country',
        'national_id','id_expiry_date','passport_number','passport_expiry_date','bank_name','bank_account',
        'emergency_contact_name','emergency_contact_phone','emergency_contact_relation',
        'notes',
    ];

    protected $hidden = ['national_id','bank_account'];

    protected $casts = [
        'dob'               => 'date:Y-m-d',
        'hire_date'         => 'date:Y-m-d',
        'joining_announcement_sent_at' => 'datetime',
        'confirmation_date' => 'date:Y-m-d',
        'termination_date'  => 'date:Y-m-d',
        'id_expiry_date'    => 'date:Y-m-d',
        'passport_expiry_date' => 'date:Y-m-d',
        'salary'             => 'decimal:2',
        'housing_allowance'  => 'decimal:2',
        'transport_allowance'=> 'decimal:2',
        'other_allowances'   => 'decimal:2',
        'mobile_allowance'   => 'decimal:2',
        'food_allowance'     => 'decimal:2',
        'probation_period'  => 'integer',
        'years_of_experience' => 'integer',
    ];

    protected $appends = ['full_name','avatar_url'];

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }

    public function user()          { return $this->belongsTo(User::class); }
    public function department()    { return $this->belongsTo(Department::class); }
    public function unit()          { return $this->belongsTo(Unit::class); }
    public function designation()   { return $this->belongsTo(Designation::class); }
    public function manager()       { return $this->belongsTo(Employee::class, 'manager_id'); }
    public function subordinates()  { return $this->hasMany(Employee::class, 'manager_id'); }
    public function directReports() { return $this->hasMany(Employee::class, 'manager_id'); }
    public function documents()     { return $this->hasMany(EmployeeDocument::class); }
    public function payslips()      { return $this->hasMany(Payslip::class); }
    public function leaveRequests() { return $this->hasMany(LeaveRequest::class); }
    public function leaveAllocations() { return $this->hasMany(LeaveAllocation::class); }
    public function attendanceLogs()   { return $this->hasMany(AttendanceLog::class); }
    public function dependents()       { return $this->hasMany(EmployeeDependent::class); }
    public function onboardingTasks()  { return $this->hasMany(OnboardingTask::class); }
    public function performanceReviews() { return $this->hasMany(PerformanceReview::class); }
    public function kpis()          { return $this->hasMany(Kpi::class); }

    /** Assets currently assigned to this employee. */
    public function assets()        { return $this->hasMany(\App\Models\Asset::class, 'custodian_employee_id'); }

    public function scopeActive($q) { return $q->where('status','active'); }
}
