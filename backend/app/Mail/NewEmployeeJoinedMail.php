<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewEmployeeJoinedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $newEmployee,
        public string $recipientName
    ) {
    }

    public function build(): self
    {
        return $this->subject('Welcome Our New Colleague - ' . $this->newEmployee->full_name)
            ->view('emails.new-employee-joined');
    }
}
