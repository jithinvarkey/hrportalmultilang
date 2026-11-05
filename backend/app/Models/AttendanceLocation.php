<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AttendanceLocation — a work site employees may punch from on their phone.
 *
 * @property int    $id
 * @property string $name
 * @property float  $latitude
 * @property float  $longitude
 * @property int    $radius_meters
 * @property bool   $is_active
 */
class AttendanceLocation extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'is_active' => 'boolean',
    ];
}
