<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CommunicationPublishedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $type,
        public string $title,
        public ?string $body,
        public string $link,
        public string $recipientName = 'Employee',
        public ?string $titleAr = null,
        public ?string $bodyAr = null,
    ) {
    }

    public function build()
    {
        $label = $this->type === 'policy' ? 'Policy' : 'Announcement';

        return $this->subject("New {$label}: {$this->title}")
            ->view('emails.communications.published');
    }
}
