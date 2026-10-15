<?php

namespace App\Mail;

use App\Models\Employee;
use App\Support\Locale\LocalName;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class OnboardingTasksAssignedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /** @param string $recipientGroup group code: "IT", "HR" or "Department" */
    public function __construct(
        public Employee $employee,
        public Collection $tasks,
        public string $recipientGroup,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('mail_onboarding.tasks.subject', ['name' => LocalName::employee($this->employee)]))
            ->view('emails.onboarding-tasks-assigned');
    }
}
