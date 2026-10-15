<?php

namespace App\Mail;

use App\Mail\Support\MailFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class PayrollMonthEndReminderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $payrollUrl;

    /**
     * @param  string  $month  payroll month as stored (Y-m); shown with a localised month name
     */
    public function __construct(
        public string $recipientName,
        public string $month,
        public ?string $payrollStatus,
        public ?string $recipientNameAr = null,
    ) {
        $this->payrollUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/payroll';
    }

    public function build(): self
    {
        return $this->subject(__('mail_payroll.month_end.subject', ['month' => MailFormat::month($this->month)]))
            ->view('emails.payroll-month-end-reminder');
    }
}
