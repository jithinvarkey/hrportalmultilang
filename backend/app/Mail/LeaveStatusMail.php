<?php

namespace App\Mail;

use App\Mail\Support\MailFormat;
use App\Services\LeaveService;
use Carbon\Carbon;
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
    /** The requester's own copy; everyone else gets the approver wording. */
    public bool $forEmployee;

    private const ACTIONS = ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'cancelled'];

    public function __construct(
            $leave,
            string $action,
            string $recipientName,
            ?string $remarks = null,
            $conflicts = null,
            ?string $recipientNameAr = null,
            bool $forEmployee = false
    ) {
        $this->leave = $leave;
        $this->action = $action;
        $this->recipientName = $recipientName;
        $this->recipientNameAr = $recipientNameAr;
        $this->remarks = $remarks;
        $this->conflicts = $conflicts;
        $this->forEmployee = $forEmployee;
        $this->requestUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/leave?request_id=' . $leave->id;
    }

    public function build() {

        return $this->subject($this->getSubject())
                        ->view('emails.leave.status', [
                            'bannerText' => $this->wording('banner'),
                            'introText' => in_array($this->action, self::ACTIONS, true) ? $this->wording('intro') : null,
                            'viewText' => __($this->group() . '.view_request'),
                            'typeLabel' => __($this->group() . '.labels.leave_type'),
                            'monthlyUsage' => $this->monthlyUsage(),
                        ]);
    }

    /** Built while rendering, so it is in the recipient's language. */
    protected function getSubject(): string {

        // e.g. "New Leave Request - Annual Leave - Sara Khan - LV-00001"
        return implode(' - ', array_filter([
            $this->wording('subject'),
            $this->isWorkFromHome() ? null : MailFormat::localized($this->leave->leaveType),
            $this->forEmployee ? null : MailFormat::name($this->leave->employee?->full_name, $this->leave->employee?->arabic_name),
            $this->leave->reference,
        ]));
    }

    private function isWorkFromHome(): bool {
        return (bool) $this->leave->leaveType?->isWorkFromHome();
    }

    /** Work From Home is not called "leave" in its emails. */
    private function group(): string {
        return $this->isWorkFromHome() ? 'mail_leave.wfh' : 'mail_leave';
    }

    private function wording(string $part): string {
        $group = $this->group();
        if (!in_array($this->action, self::ACTIONS, true)) {
            return __("{$group}.{$part}.default");
        }

        // The first stage can also be decided by HR; do not say the reporting manager did it.
        $action = in_array($this->action, ['manager_approved', 'manager_rejected'], true) && $this->firstStageDecidedByHr()
            ? $this->action . '_by_hr'
            : $this->action;

        $candidates = $this->forEmployee
            ? ["{$group}.employee.{$part}.{$action}", "{$group}.{$part}.{$action}", "{$group}.{$part}.{$this->action}"]
            : ["{$group}.{$part}.{$action}", "{$group}.{$part}.{$this->action}"];

        foreach ($candidates as $key) {
            if (trans()->has($key)) {
                return __($key);
            }
        }

        return __("{$group}.{$part}.default");
    }

    private function firstStageDecidedByHr(): bool {
        $decidedBy = $this->action === 'manager_approved' ? $this->leave->manager_approved_by : $this->leave->approved_by;

        return $decidedBy !== null && (int) $decidedBy !== (int) $this->leave->employee?->manager?->user_id;
    }

    /** Months this request touches, for types with a monthly day limit: [['month' => …, 'text' => '3 of 4 days'], …] */
    private function monthlyUsage(): array {
        $type = $this->leave->leaveType;
        if (!$type || $type->monthly_days_limit === null || !$this->leave->start_date || !$this->leave->end_date) {
            return [];
        }

        $rows = [];
        $month = Carbon::parse($this->leave->start_date)->startOfMonth();
        while ($month->lte(Carbon::parse($this->leave->end_date))) {
            $used = app(LeaveService::class)->monthlyDaysUsed((int) $this->leave->employee_id, $type, $month);
            $rows[] = [
                'month' => MailFormat::month($month->format('Y-m')),
                'text' => __('mail_leave.usage_value', [
                    'used' => MailFormat::number($used),
                    'limit' => MailFormat::days($type->monthly_days_limit),
                ]),
            ];
            $month = $month->copy()->addMonthNoOverflow();
        }

        return $rows;
    }
}
