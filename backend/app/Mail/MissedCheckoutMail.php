<?php

namespace App\Mail;

use App\Mail\Support\MailFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class MissedCheckoutMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $attendanceDate  ISO date (Y-m-d)
     * @param  string  $checkInTime     time as stored (H:i[:s]); both are formatted in the recipient's language
     */
    public function __construct(
        public string $employeeName,
        public string $attendanceDate,
        public string $checkInTime,
        public ?string $employeeNameAr = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('mail_attendance.missed_checkout.subject', [
                'date' => MailFormat::date($this->attendanceDate),
            ]))
            ->view('emails.missed-checkout');
    }
}
