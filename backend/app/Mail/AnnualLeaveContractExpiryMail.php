<?php

namespace App\Mail;

use App\Mail\Support\MailFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AnnualLeaveContractExpiryMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $leaveUrl;

    /**
     * @param  string  $contractExpiryDate  ISO date (Y-m-d); formatted in the recipient's language when rendering.
     */
    public function __construct(
        public string $employeeName,
        public string $contractExpiryDate,
        public float $remainingDays,
        public float $carryForwardLimit,
        public ?string $employeeNameAr = null,
    ) {
        $this->leaveUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/leave/my';
    }

    public function build(): self
    {
        return $this->subject(__('mail_leave.contract_expiry.subject', [
                'date' => MailFormat::date($this->contractExpiryDate),
            ]))
            ->view('emails.annual-leave-contract-expiry');
    }
}
