<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDependent extends Model
{
    protected $fillable = ['employee_id', 'full_name', 'relationship', 'date_of_birth', 'nationality',
        'id_number', 'id_expiry', 'passport_number', 'passport_expiry',
        'passport_file_path', 'passport_file_name', 'id_file_path', 'id_file_name', 'is_active'];
    protected $casts = [
        'date_of_birth' => 'date:Y-m-d', 'id_expiry' => 'date:Y-m-d', 'passport_expiry' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];
    public function employee() { return $this->belongsTo(Employee::class); }
}
