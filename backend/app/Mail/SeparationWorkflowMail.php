<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class SeparationWorkflowMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $separationUrl;

    /** @param string|null $recipientName null = generic "Colleague" greeting in the recipient's language */
    public function __construct(
        public $separation,
        public string $event,
        public ?string $recipientName,
        public array $tasks = [],
        public ?string $taskCategory = null,
        public ?string $completedByName = null,
    ) {
        $this->separationUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/separations?separation_id=' . $separation->id;
    }

    public function build()
    {
        $event = in_array($this->event, ['submitted', 'manager_approved', 'hr_approved', 'offboarding_tasks', 'department_tasks_completed'], true)
            ? $this->event
            : 'default';

        return $this->subject(__("mail_separation.subject.{$event}", ['reference' => $this->separation->reference]))
            ->view('emails.separation-workflow');
    }
}
