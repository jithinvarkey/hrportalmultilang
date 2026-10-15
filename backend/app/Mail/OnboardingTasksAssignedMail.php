<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class OnboardingTasksAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public Collection $tasks,
        public string $recipientGroup,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Onboarding tasks assigned - ' . $this->employee->full_name)
            ->view('emails.onboarding-tasks-assigned');
    }
}
