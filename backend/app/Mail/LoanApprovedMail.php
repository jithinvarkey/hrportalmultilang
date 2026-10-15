<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class LoanApprovedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $action = 'approved';

    public string $requestUrl;

    public function __construct(
        public Loan $loan,
        public string $recipientName,
        public ?string $recipientNameAr = null
    ) {
        $this->requestUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/loans?loan_id=' . $loan->id;
    }

    public function build(): self
    {
        return $this->subject(__('mail_loan.subject.approved', ['reference' => $this->loan->reference]))
            ->view('emails.loan.status');
    }
}
