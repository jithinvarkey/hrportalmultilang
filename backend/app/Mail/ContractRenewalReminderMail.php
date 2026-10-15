<?php

namespace App\Mail;

use App\Models\ContractRenewalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class ContractRenewalReminderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContractRenewalRequest $renewal,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('mail_contract.subject', ['reference' => $this->renewal->reference]))
            ->view('emails.contract-renewal-reminder');
    }
}
