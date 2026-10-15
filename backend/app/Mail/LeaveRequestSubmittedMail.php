<?php

declare(strict_types=1);

namespace App\Mail;

class LeaveRequestSubmittedMail extends LeaveStatusMail
{
    public function __construct($leave, string $recipientName, $conflicts = null, ?string $recipientNameAr = null)
    {
        parent::__construct($leave, 'submitted', $recipientName, null, $conflicts, $recipientNameAr);
    }
}
