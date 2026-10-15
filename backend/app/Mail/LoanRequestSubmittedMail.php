<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoanRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $action = 'submitted';

    public string $requestUrl;

    public function __construct(public Loan $loan, public string $recipientName)
    {
        $this->requestUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/loans?loan_id=' . $loan->id;
    }

    public function build(): self
    {
        return $this->subject("Loan Request Requires Review - {$this->loan->reference}")
            ->view('emails.loan.status');
    }
}
