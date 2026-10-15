<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class LoanInstallmentSkippedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $loanUrl;

    public function __construct(
        public Loan $loan,
        public LoanInstallment $skippedInstallment,
        public LoanInstallment $replacementInstallment,
        public string $recipientName,
        public string $performedBy,
        public ?string $recipientNameAr = null
    ) {
        $this->loanUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/loans?loan_id=' . $loan->id;
    }

    public function build(): self
    {
        return $this->subject(__('mail_loan.subject.installment_skipped', ['reference' => $this->loan->reference]))
            ->view('emails.loan.installment-skipped');
    }
}
