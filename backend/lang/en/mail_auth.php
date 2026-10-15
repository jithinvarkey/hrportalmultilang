<?php

return [
    'otp' => [
        'subject' => [
            'login'          => 'Login OTP',
            'password_reset' => 'Password Reset OTP',
        ],
        'purpose' => [
            'login'          => 'login',
            'password_reset' => 'password reset',
        ],
        'intro'   => 'Use this one-time password to continue with :purpose:',
        'expires' => 'This OTP expires in :minutes. If you did not request this, please ignore this email.',
        'footer'  => 'This is an automated security message.',
    ],

    'reset_password' => [
        'subject' => 'Reset Your HRMS Password',
        'heading' => 'Password Reset Request',
        'intro'   => 'You are receiving this email because we received a password reset request for your account.',
        'button'  => 'Reset Password',
        'expires' => 'This password reset link will expire in :minutes.',
        'ignore'  => 'If you did not request a password reset, no further action is required.',
    ],

    'employee_password_reset' => [
        'subject' => 'Your Password Has Been Reset',
    ],

    'minutes' => '{1} :count minute|[2,*] :count minutes',
];
