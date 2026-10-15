<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A physical or digital asset in the company's inventory.
 *
 * Statuses: available | assigned | under_maintenance | disposed | lost
 * Conditions: new | good | fair | poor
 */
class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'asset_code', 'brand', 'model', 'serial_number',
        'description', 'status', 'condition', 'purchase_price', 'purchase_date',
        'vendor', 'warranty_expiry', 'location', 'custodian_employee_id',
        'attachment_path', 'attachment_name', 'created_by',
        'source_system', 'source_id',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'purchase_date'  => 'date',
    ];

    protected $appends = ['attachment_url'];

    // ── Relations ─────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function currentAssignment(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->whereNull('return_date');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? Storage::disk('public')->url($this->attachment_path)
            : null;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    /** @param \Illuminate\Database\Eloquent\Builder $q */
    public function scopeAvailable($q)
    {
        return $q->where('status', 'available');
    }

    /** @param \Illuminate\Database\Eloquent\Builder $q */
    public function scopeAssignedTo($q, int $employeeId)
    {
        return $q->where('custodian_employee_id', $employeeId)
                 ->where('status', 'assigned');
    }
}
