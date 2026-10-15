<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SeparationWorkflowMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $separationUrl;

    public function __construct(
        public $separation,
        public string $event,
        public string $recipientName,
        public array $tasks = [],
        public ?string $taskCategory = null,
        public ?string $completedByName = null,
    ) {
        $this->separationUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/separations?separation_id=' . $separation->id;
    }

    public function build()
    {
        $subject = match ($this->event) {
            'submitted' => "New Separation Request - {$this->separation->reference}",
            'manager_approved' => "Separation Awaiting HR Approval - {$this->separation->reference}",
            'hr_approved' => "Separation Awaiting Manager Approval - {$this->separation->reference}",
            'offboarding_tasks' => "Offboarding Tasks Assigned - {$this->separation->reference}",
            'department_tasks_completed' => "Offboarding Tasks Completed - {$this->separation->reference}",
            default => "Separation Update - {$this->separation->reference}",
        };

        return $this->subject($subject)->view('emails.separation-workflow');
    }
}
