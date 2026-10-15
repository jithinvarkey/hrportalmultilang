<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoanInstallmentSkippedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $loanUrl;

    public function __construct(
        public Loan $loan,
        public LoanInstallment $skippedInstallment,
        public LoanInstallment $replacementInstallment,
        public string $recipientName,
        public string $performedBy
    ) {
        $this->loanUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/loans?loan_id=' . $loan->id;
    }

    public function build(): self
    {
        return $this->subject("Loan Installment Skipped - {$this->loan->reference}")
            ->view('emails.loan.installment-skipped');
    }
}
