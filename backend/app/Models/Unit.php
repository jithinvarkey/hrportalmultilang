<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'legacy_unitid', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
