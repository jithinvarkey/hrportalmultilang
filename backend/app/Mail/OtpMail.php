<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $otp;
    public $purposeLabel;
    public $expiresInMinutes;

    public function __construct(
        string $name,
        string $otp,
        string $purposeLabel,
        int $expiresInMinutes
    ) {
        $this->name = $name;
        $this->otp = $otp;
        $this->purposeLabel = $purposeLabel;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function build(): self
    {
        return $this->subject("{$this->purposeLabel} OTP")
            ->view('emails.otp');
    }
}
