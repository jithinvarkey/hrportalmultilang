<?php

namespace App\Mail;

use App\Models\ContractRenewalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContractRenewalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContractRenewalRequest $renewal,
    ) {
    }

    public function build(): self
    {
        return $this->subject("Contract renewal request auto-created - {$this->renewal->reference}")
            ->view('emails.contract-renewal-reminder');
    }
}
