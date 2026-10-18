<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OnboardingTask extends Model {
    protected $fillable = ['employee_id','title','description','category','status','due_date','completed_date','assigned_to','sort_order'];
    protected $casts = ['due_date'=>'date:Y-m-d','completed_date'=>'date:Y-m-d'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
