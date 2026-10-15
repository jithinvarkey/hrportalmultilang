<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginOtp extends Model
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'user_id',
        'purpose',
        'challenge_token',
        'otp_hash',
        'expires_at',
        'last_sent_at',
        'consumed_at',
        'attempts',
        'send_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
