<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyCategory extends Model
{
    protected $fillable = [
        'legacy_category_id', 'name', 'slug', 'audience_type',
        'target_department_ids', 'icon', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'target_department_ids' => 'array',
    ];

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class, 'category_id');
    }
}
