<?php

namespace App\Mail;

use App\Models\Employee;
use App\Support\Locale\LocalName;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class NewEmployeeJoinedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /** @param string|null $recipientName null = generic "Colleagues" greeting in the recipient's language */
    public function __construct(
        public Employee $newEmployee,
        public ?string $recipientName = null
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('mail_people.joined.subject', ['name' => LocalName::employee($this->newEmployee)]))
            ->view('emails.new-employee-joined');
    }
}
