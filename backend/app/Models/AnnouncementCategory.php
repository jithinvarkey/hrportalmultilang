<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnouncementCategory extends Model
{
    protected $fillable = ['name', 'slug', 'color', 'icon', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'category_id');
    }
}
