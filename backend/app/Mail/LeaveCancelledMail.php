<?php

declare(strict_types=1);

namespace App\Mail;

class LeaveCancelledMail extends LeaveStatusMail
{
    public function __construct($leave, string $recipientName, ?string $remarks = null)
    {
        parent::__construct($leave, 'cancelled', $recipientName, $remarks);
    }
}
