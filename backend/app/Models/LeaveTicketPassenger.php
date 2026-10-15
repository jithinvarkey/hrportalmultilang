<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveTicketPassenger extends Model
{
    protected $fillable = ['leave_request_id', 'passenger_type', 'dependent_id', 'passenger_name'];
    public function leaveRequest() { return $this->belongsTo(LeaveRequest::class); }
    public function dependent() { return $this->belongsTo(EmployeeDependent::class); }
}
