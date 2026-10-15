<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class LeaveStatusMail extends BaseMailable {

    use Queueable,
        SerializesModels;

    public $leave;
    public $action;
    public $recipientName;
    /** Recipient's Arabic name, used instead of $recipientName when the mail is rendered in Arabic. */
    public $recipientNameAr;
    public $remarks;
    public $conflicts;
    public string $requestUrl;

    public function __construct(
            $leave,
            string $action,
            string $recipientName,
            ?string $remarks = null,
            $conflicts = null,
            ?string $recipientNameAr = null
    ) {
        $this->leave = $leave;
        $this->action = $action;
        $this->recipientName = $recipientName;
        $this->recipientNameAr = $recipientNameAr;
        $this->remarks = $remarks;
        $this->conflicts = $conflicts;
        $this->requestUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/leave?request_id=' . $leave->id;
    }

    public function build() {

        return $this->subject($this->getSubject())
                        ->view('emails.leave.status');
    }

    /** Built while rendering, so it is in the recipient's language. */
    protected function getSubject(): string {

        $known = ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'cancelled'];

        return in_array($this->action, $known, true)
            ? __('mail_leave.subject.' . $this->action, ['reference' => $this->leave->reference])
            : __('mail_leave.subject.default');
    }
}
