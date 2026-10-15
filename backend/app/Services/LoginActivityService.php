<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoginActivityService
{
    public function record(
        Request $request,
        ?User $user,
        string $event,
        string $status,
        ?string $identifier = null,
        array $metadata = []
    ): LoginActivity {
        return LoginActivity::create([
            'user_id' => $user?->id,
            'login_identifier' => $identifier ?: $user?->email,
            'event' => $event,
            'status' => $status,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'personal_access_token_id' => $metadata['token_id'] ?? null,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
