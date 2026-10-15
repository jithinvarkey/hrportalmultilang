<?php

namespace App\Mail;

use App\Models\EmployeeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RequestStatusMail extends BaseMailable
{
    use Queueable;

    /**
     * @param  string|null  $note  text typed by a user (completion notes, rejection reason). When null, a
     *                             standard note for the status is added in the recipient's language.
     */
    public function __construct(
        public EmployeeRequest $request,
        public string $status,
        public ?string $recipientName = null,
        public ?string $note = null,
        public ?string $recipientNameAr = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $key = in_array($this->status, ['pending', 'in_progress', 'completed', 'rejected', 'cancelled'], true)
            ? $this->status
            : 'default';

        return new Envelope(subject: __('mail_request.subject.' . $key, ['reference' => $this->request->reference]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.request-status', with: ['displayNote' => $this->displayNote()]);
    }

    /** User-entered note as typed, otherwise the standard note for the status (rendered in the mail locale). */
    private function displayNote(): ?string
    {
        if ($this->note !== null && trim($this->note) !== '') {
            return $this->note;
        }

        return match ($this->status) {
            'pending' => __('mail_request.note.pending'),
            'in_progress' => __('mail_request.note.in_progress', [
                'name' => $this->request->assignedTo?->name ?: __('mail_request.note.handling_team'),
            ]),
            'cancelled' => __('mail_request.note.cancelled'),
            default => null,
        };
    }
}
