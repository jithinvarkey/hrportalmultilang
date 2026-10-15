<?php
namespace App\Mail;

use App\Models\Interview;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InterviewInviteMail extends Mailable
{
    use Queueable;

    public function __construct(public Interview $interview, public Collection $interviewerEmployees) {}

    public function envelope(): Envelope
    {
        $application = $this->interview->application;
        $posting = $this->interview->application?->jobPosting;
        return new Envelope(
            subject: 'Interview Invitation — '
                . ($application?->applicant_name ?? 'Candidate')
                . ' — '
                . ($posting?->title ?? 'Position at Diamond Insurance Broker')
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.interview-invite');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->calendarInvite(), 'interview-invitation.ics')
                ->withMime('text/calendar; method=REQUEST; charset=UTF-8'),
        ];
    }

    private function calendarInvite(): string
    {
        $application = $this->interview->application;
        $posting = $application?->jobPosting;
        $start = $this->interview->scheduled_at;
        $end = $start?->copy()->addMinutes((int) ($this->interview->duration_minutes ?: 60));
        $summary = 'Interview: '
            . ($application?->applicant_name ?? 'Candidate')
            . ' — '
            . ($posting?->title ?? 'Candidate Interview');
        $description = trim('Interview with ' . ($application?->applicant_name ?? 'candidate') . ' for ' . ($posting?->title ?? 'the applied position') . '.');
        $uid = 'interview-' . $this->interview->id . '@' . parse_url(config('app.url'), PHP_URL_HOST);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Diamond Insurance Broker//HRMS//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $this->escapeCalendarText($uid),
            'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:' . $this->calendarDate($start),
            'DTEND:' . $this->calendarDate($end),
            'SUMMARY:' . $this->escapeCalendarText($summary),
            'DESCRIPTION:' . $this->escapeCalendarText($description),
            'LOCATION:' . $this->escapeCalendarText((string) $this->interview->location_or_link),
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'TRANSP:OPAQUE',
            'CLASS:PUBLIC',
            'PRIORITY:5',
            'X-MICROSOFT-CDO-BUSYSTATUS:BUSY',
            'ORGANIZER;CN=HR Team:MAILTO:' . config('mail.from.address'),
        ];

        if ($application?->applicant_email) {
            $lines[] = $this->attendeeLine($application->applicant_name ?: 'Candidate', $application->applicant_email, true);
        }

        foreach ($this->interviewerEmployees as $employee) {
            if ($employee->email) {
                $lines[] = $this->attendeeLine($employee->full_name ?: $employee->email, $employee->email);
            }
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function calendarDate(?CarbonInterface $date): string
    {
        return ($date ?? now())->copy()->utc()->format('Ymd\THis\Z');
    }

    private function attendeeLine(string $name, string $email, bool $required = false): string
    {
        $role = $required ? 'REQ-PARTICIPANT' : 'OPT-PARTICIPANT';
        return 'ATTENDEE;CN=' . $this->escapeCalendarText($name) . ';ROLE=' . $role . ';PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:' . $email;
    }

    private function escapeCalendarText(string $value): string
    {
        return Str::of($value)
            ->replace('\\', '\\\\')
            ->replace(';', '\;')
            ->replace(',', '\,')
            ->replace("\r\n", '\n')
            ->replace("\n", '\n')
            ->toString();
    }
}
