<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Interview extends Model {
    protected $fillable = ['application_id','round','scheduled_at','duration_minutes','format','location_or_link','status','feedback','result','interviewers','interviewer_employee_ids'];
    protected $casts = ['scheduled_at'=>'datetime','interviewers'=>'array','interviewer_employee_ids'=>'array'];
    public function application() { return $this->belongsTo(JobApplication::class); }
}
