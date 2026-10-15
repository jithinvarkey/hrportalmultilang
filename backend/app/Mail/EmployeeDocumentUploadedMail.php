<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class EmployeeDocumentUploadedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public EmployeeDocument $document,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('mail_onboarding.document.subject'))
            ->view('emails.employee-document-uploaded');
    }
}
