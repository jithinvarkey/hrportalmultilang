<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single maintenance/repair event for an asset.
 */
class AssetMaintenance extends Model
{
    protected $table = 'asset_maintenance';

    protected $fillable = [
        'asset_id', 'type', 'title', 'description', 'scheduled_date',
        'completed_date', 'cost', 'vendor', 'status', 'resolution', 'created_by',
    ];

    protected $casts = [
        'scheduled_date'  => 'date',
        'completed_date'  => 'date',
        'cost'            => 'decimal:2',
    ];

    public function asset(): BelongsTo     { return $this->belongsTo(Asset::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
