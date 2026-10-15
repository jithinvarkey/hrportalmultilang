<?php

namespace App\Mail;

use App\Models\EmployeeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RequestStatusMail extends Mailable
{
    use Queueable;

    public function __construct(
        public EmployeeRequest $request,
        public string $status,
        public ?string $recipientName = null,
        public ?string $note = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'pending' => 'Request Awaiting Processing - ' . $this->request->reference,
            'in_progress' => 'Request In Progress - ' . $this->request->reference,
            'completed' => 'Request Completed - ' . $this->request->reference,
            'rejected' => 'Request Rejected - ' . $this->request->reference,
            'cancelled' => 'Request Cancelled - ' . $this->request->reference,
        ];

        return new Envelope(subject: $subjects[$this->status] ?? 'Request Update - ' . $this->request->reference);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.request-status');
    }
}
