<?php
namespace App\Models;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;

class User extends Authenticatable implements HasLocalePreference
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles {
        HasRoles::hasRole as private spatieHasRole;
    }

    protected $fillable = ['name', 'email', 'preferred_language', 'password', 'legacy_password_md5', 'otp_exempt'];

    protected $hidden = ['password', 'legacy_password_md5', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'otp_exempt'         => 'boolean',
    ];

    /**
     * CEO is an access alias of HR Manager. Keeping this in the model makes
     * every Spatie hasRole()/hasAnyRole() authorization check consistent.
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        if ($this->spatieHasRole($roles, $guard)) {
            return true;
        }

        $requestedRoles = is_array($roles) ? $roles : [$roles];

        return in_array('hr_manager', $requestedRoles, true)
            && $this->spatieHasRole('ceo', $guard);
    }

    /**
     * Send the password reset notification with a link to the Angular SPA.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    /** Employee statuses that mean the person has left the company. */
    public const CLOSED_EMPLOYMENT_STATUSES = ['terminated', 'inactive'];

    /**
     * True when the linked employee has left: terminated, inactive, or archived (soft-deleted).
     * Such users may not sign in or keep a session; HR sends them final payslips and documents directly.
     * Note: the separation workflow sets employees to inactive when offboarding starts, so access ends then.
     * Accounts without an employee record (system administrators) are never closed by this rule.
     */
    public function hasClosedEmployment(): bool
    {
        $employee = Employee::withTrashed()->where('user_id', $this->id)->first(['id', 'status', 'deleted_at']);

        return $employee !== null
            && ($employee->trashed() || in_array($employee->status, self::CLOSED_EMPLOYMENT_STATUSES, true));
    }

    /**
     * Language for notifications and mail sent with Mail::to($user): the linked employee's
     * communication language (English by default). Not the UI language in preferred_language.
     * Mail addressed to a plain email string is resolved by App\Support\Locale\RecipientLocale.
     */
    public function preferredLocale(): string
    {
        return \App\Support\Locale\SupportedLocales::normalize($this->employee?->preferred_communication_language);
    }
}
