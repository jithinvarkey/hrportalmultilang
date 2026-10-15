<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeDocumentUploadedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public EmployeeDocument $document,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Employee document pending verification')
            ->view('emails.employee-document-uploaded');
    }
}
