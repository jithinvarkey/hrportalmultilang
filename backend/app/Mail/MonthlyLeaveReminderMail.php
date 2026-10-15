<?php

namespace App\Mail;

use App\Mail\Support\MailFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Monthly "submit your leave" reminder.
 *
 * HR can customise the subject/body in settings. A customised text is sent exactly as typed (in whatever
 * language HR wrote it); when HR kept the default, the translated default from lang/<locale>/mail_leave.php
 * is used so the employee gets it in their own language. Placeholders are filled while rendering, so the
 * month name is localised too: {{first_name}} {{employee_name}} {{month_name}} {{year}} {{company_name}}.
 */
class MonthlyLeaveReminderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public string $leaveUrl;

    public string $mailBody = '';

    /**
     * @param  string|null  $subjectTemplate  custom subject from settings, or null for the translated default
     * @param  string|null  $bodyTemplate     custom body from settings, or null for the translated default
     * @param  string       $period           any date in the reminder month (Y-m-d)
     */
    public function __construct(
        public ?string $subjectTemplate,
        public ?string $bodyTemplate,
        public string $firstName,
        public string $employeeName,
        public string $period,
        public ?string $employeeNameAr = null,
    ) {
        $this->leaveUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/leave/my';
    }

    public function build(): self
    {
        $this->mailBody = $this->fill($this->bodyTemplate ?? __('mail_leave.monthly_reminder.body'));

        return $this->subject($this->fill($this->subjectTemplate ?? __('mail_leave.monthly_reminder.subject')))
            ->view('emails.monthly-leave-reminder');
    }

    private function fill(string $template): string
    {
        return strtr($template, [
            '{{employee_name}}' => MailFormat::name($this->employeeName, $this->employeeNameAr),
            '{{first_name}}' => MailFormat::name($this->firstName, $this->employeeNameAr),
            '{{month_name}}' => MailFormat::date($this->period, 'F'),
            '{{year}}' => MailFormat::date($this->period, 'Y'),
            '{{company_name}}' => config('app.name', 'HRMS'),
        ]);
    }
}
