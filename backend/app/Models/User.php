<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles {
        HasRoles::hasRole as private spatieHasRole;
    }

    protected $fillable = ['name', 'email', 'password', 'legacy_password_md5', 'otp_exempt'];

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
}
