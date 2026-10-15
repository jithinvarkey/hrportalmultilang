<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Title/body are authored by HR (optionally in both languages) and are never translated.
 * $messageKey lets callers send a generated summary (e.g. "policy_message.requires_ack") that is
 * rendered in the recipient's language instead of an English $body.
 */
class CommunicationPublishedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $type,
        public string $title,
        public ?string $body,
        public string $link,
        public ?string $recipientName = null,
        public ?string $titleAr = null,
        public ?string $bodyAr = null,
        public ?string $recipientNameAr = null,
        public ?string $messageKey = null,
        public array $messageParams = [],
    ) {
    }

    public function build()
    {
        $kind = $this->type === 'policy' ? 'policy' : 'announcement';
        $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
        $title = $isRtl && filled($this->titleAr) ? $this->titleAr : $this->title;

        return $this->subject(__("mail_communications.subject.{$kind}", ['title' => $title]))
            ->view('emails.communications.published');
    }
}
