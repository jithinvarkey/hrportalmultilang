<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password reset notification whose action link points at the Angular SPA
 * reset page (FRONTEND_URL/auth/reset-password?token=...&email=...) instead of
 * the default backend web route.
 *
 * Laravel builds notifications in the notifiable's preferredLocale() (User implements HasLocalePreference),
 * so the __() calls below and the Blade view are already in the recipient's language. A portal-styled view
 * is used instead of Laravel's default markdown layout because that layout cannot be switched to RTL.
 */
class ResetPasswordNotification extends BaseResetPassword
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $url = $frontend . '/auth/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $expire = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject(__('mail_auth.reset_password.subject'))
            ->view('emails.auth.reset-password', [
                'url' => $url,
                'expire' => $expire,
                'name' => $notifiable->name ?? null,
                'nameAr' => $notifiable->employee?->arabic_name ?? null,
            ]);
    }
}
