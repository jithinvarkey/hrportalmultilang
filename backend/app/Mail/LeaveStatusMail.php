<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveStatusMail extends Mailable {

    use Queueable,
        SerializesModels;

    public $leave;
    public $action;
    public $recipientName;
    public $remarks;
    public $conflicts;
    public string $requestUrl;

    public function __construct(
            $leave,
            string $action,
            string $recipientName,
            ?string $remarks = null,
            $conflicts = null
    ) {
        $this->leave = $leave;
        $this->action = $action;
        $this->recipientName = $recipientName;
        $this->remarks = $remarks;
        $this->conflicts = $conflicts;
        $this->requestUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/leave?request_id=' . $leave->id;
    }

    public function build() {
        
        return $this->subject($this->getSubject())
                        ->view('emails.leave.status');
    }

    protected function getSubject(): string {

        return match ($this->action) {

            'submitted' =>
            "New Leave Request - {$this->leave->reference}",
            'manager_approved' =>
            "Leave Request Awaiting HR Approval - {$this->leave->reference}",
            'manager_rejected' =>
            "Leave Request Rejected By Manager - {$this->leave->reference}",
            'hr_approved' =>
            "Leave Request Approved - {$this->leave->reference}",
            'hr_rejected' =>
            "Leave Request Rejected By HR - {$this->leave->reference}",
            'cancelled' =>
            "Leave Request Cancelled - {$this->leave->reference}",
            default =>
            "Leave Request Notification"
        };
    }
}
