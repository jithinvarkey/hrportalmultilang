<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PayrollMonthEndReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $payrollUrl;

    public function __construct(
        public string $recipientName,
        public string $month,
        public ?string $payrollStatus,
    ) {
        $this->payrollUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/payroll';
    }

    public function build(): self
    {
        return $this->subject('Action required: Payroll is not paid for ' . $this->month)
            ->view('emails.payroll-month-end-reminder');
    }
}
