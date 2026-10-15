<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtpService
{
    private const EXPIRES_IN_MINUTES = 3;
    private const MAX_ATTEMPTS = 5;

    private $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function createChallenge(User $user, string $purpose): LoginOtp
    {
        LoginOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = (string) random_int(100000, 999999);

        $challenge = LoginOtp::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'challenge_token' => Str::random(64),
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'last_sent_at' => now(),
            'send_count' => 1,
        ]);

        $this->deliver($user, $otp, $purpose);

        return $challenge;
    }

    public function resend(LoginOtp $challenge): LoginOtp
    {
        if ($challenge->consumed_at !== null) {
            throw ValidationException::withMessages(['otp' => ['This OTP has already been used.']]);
        }

        if ($challenge->last_sent_at && $challenge->last_sent_at->gt(now()->subSeconds(30))) {
            throw ValidationException::withMessages(['otp' => ['Please wait before requesting another OTP.']]);
        }

        $otp = (string) random_int(100000, 999999);
        $challenge->forceFill([
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'last_sent_at' => now(),
            'attempts' => 0,
            'send_count' => $challenge->send_count + 1,
        ])->save();

        $this->deliver($challenge->user, $otp, $challenge->purpose);

        return $challenge->fresh('user.employee');
    }

    public function verify(string $challengeToken, string $otp, string $purpose): LoginOtp
    {
        $challenge = LoginOtp::query()
            ->with('user.employee')
            ->where('challenge_token', $challengeToken)
            ->where('purpose', $purpose)
            ->first();

        if (!$challenge || !$challenge->isActive()) {
            throw ValidationException::withMessages(['otp' => ['This OTP is invalid or has expired.']]);
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            throw ValidationException::withMessages(['otp' => ['Too many incorrect attempts. Please request a new OTP.']]);
        }

        if (!Hash::check($otp, $challenge->otp_hash)) {
            $challenge->increment('attempts');
            throw ValidationException::withMessages(['otp' => ['The OTP is incorrect.']]);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return $challenge;
    }

    public function findChallenge(string $challengeToken, string $purpose): ?LoginOtp
    {
        return LoginOtp::query()
            ->with('user.employee')
            ->where('challenge_token', $challengeToken)
            ->where('purpose', $purpose)
            ->first();
    }

    public function expiresInSeconds(LoginOtp $challenge): int
    {
        return max(0, now()->diffInSeconds($challenge->expires_at, false));
    }

    private function deliver(User $user, string $otp, string $purpose): void
    {
        $label = $purpose === LoginOtp::PURPOSE_PASSWORD_RESET ? 'Password Reset' : 'Login';

        Mail::to($user->email)->send(new OtpMail(
            $user->name ?: $user->email,
            $otp,
            $label,
            self::EXPIRES_IN_MINUTES
        ));

        $phone = $user->employee ? ($user->employee->phone ?: $user->employee->work_phone) : null;
        if ($phone) {
            $this->smsService->send($phone, "{$label} OTP: {$otp}. Valid for " . self::EXPIRES_IN_MINUTES . ' minutes.');
        }
    }
}
