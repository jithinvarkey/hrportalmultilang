<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanInstallment extends Model {
    use HasFactory;
    protected $fillable = ['loan_id','installment_no','due_date','amount','paid_amount','status','paid_date','processed_by','payslip_id','paid_via_payroll','notes'];
    protected $casts    = ['due_date'=>'date','paid_date'=>'date','amount'=>'float','paid_amount'=>'float','paid_via_payroll'=>'boolean'];
    public function loan()        { return $this->belongsTo(Loan::class); }
    public function payslip()     { return $this->belongsTo(Payslip::class); }
    public function processedBy() { return $this->belongsTo(User::class,'processed_by'); }
}
