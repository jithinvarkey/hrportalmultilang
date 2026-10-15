<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password reset notification whose action link points at the Angular SPA
 * reset page (FRONTEND_URL/auth/reset-password?token=...&email=...) instead of
 * the default backend web route.
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
            ->subject('Reset Your HRMS Password')
            ->greeting('Password Reset Request')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line("This password reset link will expire in {$expire} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
