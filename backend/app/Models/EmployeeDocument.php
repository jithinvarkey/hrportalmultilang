<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model {
    protected $fillable = ['employee_id','title','type','file_path','file_name','mime_type','file_size','expiry_date','is_verified','uploaded_by','verified_by','verified_at'];
    protected $casts = ['expiry_date'=>'date:Y-m-d','is_verified'=>'boolean','verified_at'=>'datetime'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
