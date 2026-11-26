<?php

// Leave emails (leave status workflow, contract-expiry balance reminder, monthly reminder).
return [
    'subject' => [
        'submitted'        => 'New Leave Request',
        'manager_approved' => 'Leave Request Awaiting HR Approval',
        'manager_rejected' => 'Leave Request Rejected By Manager',
        'manager_rejected_by_hr' => 'Leave Request Rejected By HR',
        'hr_approved'      => 'Leave Request Approved',
        'hr_rejected'      => 'Leave Request Rejected By HR',
        'cancelled'        => 'Leave Request Cancelled',
        'default'          => 'Leave Request Notification',
    ],

    'banner' => [
        'submitted'        => 'Leave Request Submitted',
        'manager_approved' => 'Manager Approval Completed',
        'manager_rejected' => 'Rejected By Manager',
        'manager_approved_by_hr' => 'First Approval Completed',
        'manager_rejected_by_hr' => 'Rejected By HR',
        'hr_approved'      => 'Leave Approved',
        'hr_rejected'      => 'Rejected By HR',
        'cancelled'        => 'Leave Cancelled',
        'default'          => 'Leave Request',
    ],

    'intro' => [
        'submitted'        => 'A new leave request has been submitted and requires your review.',
        'manager_approved' => 'The leave request has been approved by the reporting manager and is awaiting HR approval.',
        'manager_approved_by_hr' => 'HR has given the first approval for the leave request, which is now awaiting final HR approval.',
        'manager_rejected' => 'The leave request has been rejected by the reporting manager.',
        'manager_rejected_by_hr' => 'The leave request has been rejected by HR.',
        'hr_approved'      => 'The leave request has been fully approved by HR.',
        'hr_rejected'      => 'The leave request has been rejected by HR.',
        'cancelled'        => 'The leave request has been cancelled.',
    ],

    // The requester's own copy, where it differs from the approvers' copy.
    'employee' => [
        'subject' => [
            'submitted' => 'Leave Request Submitted',
        ],
        'intro' => [
            'submitted'        => 'Your leave request has been submitted and is awaiting approval.',
            'manager_approved' => 'Your leave request has been approved by your reporting manager and is awaiting HR approval.',
            'manager_approved_by_hr' => 'HR has given the first approval for your leave request, which is now awaiting final HR approval.',
            'manager_rejected' => 'Your leave request has been rejected by your reporting manager.',
            'manager_rejected_by_hr' => 'Your leave request has been rejected by HR.',
            'hr_approved'      => 'Your leave request has been fully approved by HR.',
            'hr_rejected'      => 'Your leave request has been rejected by HR.',
            'cancelled'        => 'Your leave request has been cancelled.',
        ],
    ],

    // Work From Home requests are leave requests in the system but are not called "leave" in emails.
    'wfh' => [
        'subject' => [
            'submitted'        => 'New Work From Home Request',
            'manager_approved' => 'Work From Home Request Awaiting HR Approval',
            'manager_rejected' => 'Work From Home Request Rejected By Manager',
            'manager_rejected_by_hr' => 'Work From Home Request Rejected By HR',
            'hr_approved'      => 'Work From Home Request Approved',
            'hr_rejected'      => 'Work From Home Request Rejected By HR',
            'cancelled'        => 'Work From Home Request Cancelled',
            'default'          => 'Work From Home Request Notification',
        ],
        'banner' => [
            'submitted'        => 'Work From Home Request Submitted',
            'manager_approved' => 'Manager Approval Completed',
            'manager_approved_by_hr' => 'First Approval Completed',
            'manager_rejected' => 'Rejected By Manager',
            'manager_rejected_by_hr' => 'Rejected By HR',
            'hr_approved'      => 'Work From Home Approved',
            'hr_rejected'      => 'Rejected By HR',
            'cancelled'        => 'Work From Home Cancelled',
            'default'          => 'Work From Home Request',
        ],
        'intro' => [
            'submitted'        => 'A new work from home request has been submitted and requires your review.',
            'manager_approved' => 'The work from home request has been approved by the reporting manager and is awaiting HR approval.',
            'manager_approved_by_hr' => 'HR has given the first approval for the work from home request, which is now awaiting final HR approval.',
            'manager_rejected' => 'The work from home request has been rejected by the reporting manager.',
            'manager_rejected_by_hr' => 'The work from home request has been rejected by HR.',
            'hr_approved'      => 'The work from home request has been fully approved by HR.',
            'hr_rejected'      => 'The work from home request has been rejected by HR.',
            'cancelled'        => 'The work from home request has been cancelled.',
        ],
        'employee' => [
            'subject' => [
                'submitted' => 'Work From Home Request Submitted',
            ],
            'intro' => [
                'submitted'        => 'Your work from home request has been submitted and is awaiting approval.',
                'manager_approved' => 'Your work from home request has been approved by your reporting manager and is awaiting HR approval.',
                'manager_approved_by_hr' => 'HR has given the first approval for your work from home request, which is now awaiting final HR approval.',
                'manager_rejected' => 'Your work from home request has been rejected by your reporting manager.',
                'manager_rejected_by_hr' => 'Your work from home request has been rejected by HR.',
                'hr_approved'      => 'Your work from home request has been fully approved by HR.',
                'hr_rejected'      => 'Your work from home request has been rejected by HR.',
                'cancelled'        => 'Your work from home request has been cancelled.',
            ],
        ],
        'labels' => [
            'leave_type' => 'Request Type',
        ],
        'view_request' => 'View Request',
    ],

    'usage_value' => ':used of :limit (approved and pending)',

    'labels' => [
        'leave_type' => 'Leave Type',
        'from_date'  => 'From Date',
        'to_date'    => 'To Date',
        'monthly_usage' => 'Used in :month',
    ],

    'view_request' => 'View Leave Request',
    'contact_hr'   => 'If you have any questions regarding this request, contact HR at :email.',
    'auto_notice'  => 'This is an automated email. Please do not reply.',

    'conflict' => [
        'title' => 'Department Leave Conflict Warning',
        'intro' => 'This employee has applied for more than 5 days of Annual Leave. The following employees from the same department have overlapping leave requests:',
    ],

    // Simple status template (emails/leave-status): approved / rejected / submitted.
    'simple' => [
        'banner' => [
            'approved' => 'Leave Request Approved',
            'rejected' => 'Leave Request Rejected',
            'default'  => 'Leave Request :status',
        ],
        'approved'  => 'Your leave request has been :status. Details below:',
        'rejected'  => 'We regret to inform you that your leave request has been :status.',
        'submitted' => 'Your leave request has been :status and is pending approval.',
        'word' => [
            'approved'  => 'approved',
            'rejected'  => 'rejected',
            'submitted' => 'submitted',
        ],
    ],

    'contract_expiry' => [
        'subject' => 'Annual leave balance reminder - contract expires :date',
        'intro'   => 'Your current employment contract will expire on :date.',
        'balance' => 'Your remaining annual leave balance is :remaining. A maximum of :limit can be carried forward to your next contract.',
        'advice'  => 'Please plan any leave above the carry-forward limit before your current contract expires and coordinate with your direct manager and Human Resources.',
        'button'  => 'View Leave Balance',
        'footer'  => 'This is an automated annual leave reminder sent 90 days before contract expiry.',
    ],

    // Default text of the monthly reminder. HR can override subject/body in settings (sent exactly as typed).
    // Placeholders: {{first_name}} {{employee_name}} {{month_name}} {{year}} {{company_name}}
    'monthly_reminder' => [
        'subject' => 'Reminder: Submit current month leave entries',
        'body'    => "Dear {{first_name}},\n\nPlease make sure all leave requests for {{month_name}} {{year}} are submitted in HRMS before payroll processing. Any missing or unsubmitted leave entries may be deducted from salary as per company policy.\n\nRegards,\nHuman Resources",
        'button'  => 'Open Leave Requests',
        'footer'  => 'Sent by Human Resources',
    ],

    // Fractional durations (0.5 day, 1.5 hours); whole numbers use mail.units.*
    'units' => [
        'days_decimal'  => ':count days',
        'hours_decimal' => ':count hours',
    ],
];
