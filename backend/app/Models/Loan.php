<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model {
    use HasFactory;
    protected $fillable = [
        'reference','employee_id','loan_type_id',
        'requested_amount','approved_amount','installments','monthly_installment',
        'purpose','notes','status',
        'manager_approved_by','manager_approved_at',
        'hr_approved_by','hr_approved_at',
        'finance_approved_by','finance_approved_at',
        'rejection_reason','rejected_by','rejected_at','rejected_stage',
        'disbursed_date','first_installment_date',
        'total_paid','balance_remaining','installments_paid','installments_skipped',
    ];
    protected $casts = [
        'requested_amount'     => 'float',
        'approved_amount'      => 'float',
        'monthly_installment'  => 'float',
        'total_paid'           => 'float',
        'balance_remaining'    => 'float',
        'manager_approved_at'  => 'datetime',
        'hr_approved_at'       => 'datetime',
        'finance_approved_at'  => 'datetime',
        'rejected_at'          => 'datetime',
        'disbursed_date'       => 'date',
        'first_installment_date' => 'date',
    ];
    public function employee()        { return $this->belongsTo(Employee::class); }
    public function loanType()        { return $this->belongsTo(LoanType::class); }
    public function installments()    { return $this->hasMany(LoanInstallment::class)->orderBy('due_date')->orderBy('installment_no'); }
    public function managerApprover() { return $this->belongsTo(User::class,'manager_approved_by'); }
    public function hrApprover()      { return $this->belongsTo(User::class,'hr_approved_by'); }
    public function financeApprover() { return $this->belongsTo(User::class,'finance_approved_by'); }
    public function rejectedBy()      { return $this->belongsTo(User::class,'rejected_by'); }
    public function canDeleteRequest(): bool {
        return in_array($this->status, ['pending_manager', 'pending_hr'], true)
            && $this->manager_approved_by === null && $this->manager_approved_at === null
            && $this->hr_approved_by === null && $this->hr_approved_at === null
            && $this->finance_approved_by === null && $this->finance_approved_at === null;
    }
    public function nextPendingInstallment() {
        return $this->installments()->where('status','pending')->first();
    }
}
