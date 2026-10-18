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
    private const MAX_SENDS = 5;
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
            throw ValidationException::withMessages(['otp' => [__('This OTP has already been used.')]]);
        }

        if ($challenge->last_sent_at && $challenge->last_sent_at->gt(now()->subSeconds(30))) {
            throw ValidationException::withMessages(['otp' => [__('Please wait before requesting another OTP.')]]);
        }

        // Each resend resets the attempt counter, so cap resends to keep the total number of guesses bounded.
        if ((int) $challenge->send_count >= self::MAX_SENDS) {
            throw ValidationException::withMessages(['otp' => [__('Too many OTP requests. Please sign in again.')]]);
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
            throw ValidationException::withMessages(['otp' => [__('This OTP is invalid or has expired.')]]);
        }

        // Reserve the attempt atomically before the (slow) hash check, so parallel guesses
        // cannot all slip past the MAX_ATTEMPTS check.
        $reserved = LoginOtp::query()
            ->whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($reserved === 0) {
            $challenge->refresh();
            if ($challenge->consumed_at !== null) {
                throw ValidationException::withMessages(['otp' => [__('This OTP is invalid or has expired.')]]);
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            throw ValidationException::withMessages(['otp' => [__('Too many incorrect attempts. Please request a new OTP.')]]);
        }

        if (!Hash::check($otp, $challenge->otp_hash)) {
            throw ValidationException::withMessages(['otp' => [__('The OTP is incorrect.')]]);
        }

        // Consume once: a second concurrent request with the same correct OTP must not get another token.
        $consumed = LoginOtp::query()
            ->whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            throw ValidationException::withMessages(['otp' => [__('This OTP is invalid or has expired.')]]);
        }

        return $challenge->refresh();
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

        // The mail gets the purpose code; its wording is translated into the recipient's language.
        Mail::to($user->email)->send(new OtpMail(
            $user->name ?: $user->email,
            $otp,
            $purpose,
            self::EXPIRES_IN_MINUTES,
            $user->employee?->arabic_name
        ));

        $phone = $user->employee ? ($user->employee->phone ?: $user->employee->work_phone) : null;
        if ($phone) {
            $this->smsService->send($phone, "{$label} OTP: {$otp}. Valid for " . self::EXPIRES_IN_MINUTES . ' minutes.');
        }
    }
}
