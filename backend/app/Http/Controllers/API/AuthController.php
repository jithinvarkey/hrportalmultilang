<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\LoginActivityService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private $otpService;
    private $loginActivityService;

    public function __construct(OtpService $otpService, LoginActivityService $loginActivityService)
    {
        $this->otpService = $otpService;
        $this->loginActivityService = $loginActivityService;
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required_without:login|string|max:255',
            'login'    => 'nullable|string|max:255',
            'password' => 'required|string|min:6',
        ]);

        $identifier = (string) ($request->input('login') ?: $request->input('email'));
        $user = $this->userForLogin($identifier);

        if (!$user || !$this->passwordIsValid($user, (string) $request->password)) {
            if (!$user || !$this->attemptLegacyMd5Login($user, $request->password)) {
                $this->loginActivityService->record($request, $user, 'login_failed', 'failed', $identifier, [
                    'reason' => 'invalid_credentials',
                ]);

                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }
        }

        if ($this->isOtpExempt($user)) {
            $tokenResult = $user->createToken('hrms-token');
            $token = $tokenResult->plainTextToken;

            $this->loginActivityService->record($request, $user, 'login_success', 'success', $identifier, [
                'otp_exempt' => true,
                'token_id' => $tokenResult->accessToken->id ?? null,
            ]);

            return response()->json([
                'token' => $token,
                'user' => $this->userPayload($user),
            ]);
        }

        $challenge = $this->otpService->createChallenge($user->load('employee'), LoginOtp::PURPOSE_LOGIN);
        $this->loginActivityService->record($request, $user, 'otp_challenge', 'pending', $identifier, [
            'challenge_id' => $challenge->id,
        ]);

        return response()->json([
            'otp_required' => true,
            'challenge_token' => $challenge->challenge_token,
            'expires_in' => $this->otpService->expiresInSeconds($challenge),
            'message' => 'OTP sent to your email' . ($this->hasMobileNumber($user) ? ' and mobile number.' : '.'),
        ]);
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        $challengeToken = (string) $request->input('challenge_token');
        $challenge = $this->otpService->findChallenge($challengeToken, LoginOtp::PURPOSE_LOGIN);
        $user = $challenge?->user;

        try {
            $challenge = $this->otpService->verify(
                $challengeToken,
                (string) $request->input('otp'),
                LoginOtp::PURPOSE_LOGIN
            );
        } catch (ValidationException $exception) {
            $this->loginActivityService->record($request, $user, 'otp_failed', 'failed', $user?->email, [
                'challenge_token' => $challengeToken,
            ]);

            throw $exception;
        }

        $user = $challenge->user;
        $tokenResult = $user->createToken('hrms-token');
        $token = $tokenResult->plainTextToken;

        $this->loginActivityService->record($request, $user, 'otp_success', 'success', $user->email, [
            'challenge_id' => $challenge->id,
        ]);
        $this->loginActivityService->record($request, $user, 'login_success', 'success', $user->email, [
            'otp_verified' => true,
            'token_id' => $tokenResult->accessToken->id ?? null,
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => 'required|string',
            'purpose' => 'required|string|in:login,password_reset',
        ]);

        $challenge = $this->otpService->findChallenge(
            (string) $request->input('challenge_token'),
            (string) $request->input('purpose')
        );

        if (!$challenge) {
            throw ValidationException::withMessages(['otp' => ['This OTP request is invalid.']]);
        }

        $challenge = $this->otpService->resend($challenge);

        return response()->json([
            'message' => 'A new OTP has been sent.',
            'expires_in' => $this->otpService->expiresInSeconds($challenge),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();
            $this->loginActivityService->record($request, $user, 'logout', 'success', $user->email, [
                'token_id' => $token->id ?? null,
            ]);

            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
        }

        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $request->user()->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::query()
            ->with('employee')
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'If an account exists for that email, an OTP has been sent.',
            ]);
        }

        $challenge = $this->otpService->createChallenge($user, LoginOtp::PURPOSE_PASSWORD_RESET);

        return response()->json([
            'otp_required' => true,
            'challenge_token' => $challenge->challenge_token,
            'expires_in' => $this->otpService->expiresInSeconds($challenge),
            'message' => 'OTP sent to your email' . ($this->hasMobileNumber($user) ? ' and mobile number.' : '.'),
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        if ($request->filled('challenge_token') || $request->filled('otp')) {
            $request->validate([
                'challenge_token' => 'required|string',
                'otp' => 'required|string|size:6',
                'password' => 'required|min:8|confirmed',
            ]);

            $challenge = $this->otpService->verify(
                (string) $request->input('challenge_token'),
                (string) $request->input('otp'),
                LoginOtp::PURPOSE_PASSWORD_RESET
            );

            $challenge->user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();

            return response()->json(['message' => 'Password has been reset successfully.']);
        }

        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password has been reset successfully.']);
        }

        throw ValidationException::withMessages(['email' => [__($status)]]);
    }

    protected function userPayload(User $user): array
    {
        $user->load('employee.department', 'employee.designation', 'roles');

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'roles'       => $user->getRoleNames()->values()->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->toArray(),
            'employee'    => $user->employee ? [
                'id'         => $user->employee->id,
                'code'       => $user->employee->employee_code,
                'full_name'  => $user->employee->full_name,
                'avatar_url' => $user->employee->avatar_url,
                'department' => optional($user->employee->department)->name,
                'departmentId'=>optional($user->employee)->department_id,
                'departmentCode'=>optional($user->employee->department)->code,
                'isDepartmentManager'=>(int) optional($user->employee->department)->manager_id === (int) $user->employee->id,
                'designation' => optional($user->employee->designation)->title ?? optional($user->employee->designation)->name,
                'designationId'=>optional($user->employee)->designation_id,
            ] : null,
        ];
    }

    private function attemptLegacyMd5Login(User $user, string $password): bool
    {
        $legacyHash = strtolower((string) $user->legacy_password_md5);

        if ($legacyHash === '' || !hash_equals($legacyHash, md5($password))) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'legacy_password_md5' => null,
        ])->save();

        return true;
    }

    private function passwordIsValid(User $user, string $password): bool
    {
        return Hash::check($password, (string) $user->password);
    }

    private function hasMobileNumber(User $user): bool
    {
        if (!$user->employee) {
            return false;
        }

        return (bool) ($user->employee->phone ?: $user->employee->work_phone);
    }

    private function isOtpExempt(User $user): bool
    {
        return (bool) $user->otp_exempt || $user->hasRole('super_admin');
    }

    private function userForLogin(string $login): ?User
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        $employeeCodeCandidates = $this->employeeCodeCandidates($login);
        $email = strtolower($login);

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orWhereHas('employee', function ($query) use ($employeeCodeCandidates) {
                $query->whereIn('employee_code', $employeeCodeCandidates);
            })
            ->with('employee')
            ->first();
    }

    /**
     * Accept both migrated codes like EMP182 and padded seeded codes like EMP0001.
     */
    private function employeeCodeCandidates(string $value): array
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? '');

        if ($value === '') {
            return [];
        }

        $withoutPrefix = str_starts_with($value, 'EMP') ? substr($value, 3) : $value;
        $numericPart = ltrim($withoutPrefix, '0');
        $numericPart = $numericPart === '' ? '0' : $numericPart;

        return array_values(array_unique(array_filter([
            $value,
            str_starts_with($value, 'EMP') ? $value : 'EMP' . $value,
            'EMP' . $withoutPrefix,
            ctype_digit($withoutPrefix) ? 'EMP' . str_pad($numericPart, 4, '0', STR_PAD_LEFT) : null,
        ])));
    }
}
