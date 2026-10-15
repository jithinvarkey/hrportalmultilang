<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model {
    protected $fillable = ['name', 'date', 'end_date', 'is_recurring'];
    protected $casts    = ['date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'is_recurring' => 'boolean'];
}
