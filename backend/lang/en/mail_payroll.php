<?php

return [
    'month_end' => [
        'subject'       => 'Action required: Payroll is not paid for :month',
        'not_paid'      => 'Payroll for :month has been generated, but its current status is :status. It has not yet been marked as :paid.',
        'not_generated' => 'Payroll for :month has not yet been generated.',
        'action'        => 'Please review, complete the required payroll actions, and mark the payroll as :paid.',
        'button'        => 'Open Payroll',
        'footer'        => 'Automated month-end payroll reminder',
    ],

    'payslip' => [
        'subject'    => 'Your Payslip for :month – :company',
        'department' => 'Payroll Department',
        'banner'     => 'Payslip Ready — :month',
        'intro'      => 'Your payslip for :month is attached to this email as a PDF.',
        'net_salary' => 'Net Salary',
        'period'     => 'Period',
        'contact'    => 'Please find your detailed payslip attached. Contact HR for any queries: :email',
        'footer'     => ':company • Confidential — Do not forward',
    ],
];
