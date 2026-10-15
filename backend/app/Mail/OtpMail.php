<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public $name;
    public $nameAr;
    public $otp;
    /** LoginOtp purpose code: "login" | "password_reset" (wording comes from lang/<locale>/mail_auth.php). */
    public $purpose;
    public $expiresInMinutes;

    public function __construct(
        string $name,
        string $otp,
        string $purpose,
        int $expiresInMinutes,
        ?string $nameAr = null
    ) {
        $this->name = $name;
        $this->nameAr = $nameAr;
        $this->otp = $otp;
        $this->purpose = in_array($purpose, ['login', 'password_reset'], true) ? $purpose : 'login';
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function build(): self
    {
        return $this->subject(__('mail_auth.otp.subject.' . $this->purpose))
            ->view('emails.otp');
    }
}
