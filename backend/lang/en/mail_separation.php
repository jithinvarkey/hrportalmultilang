<?php

// Separation (resignation / termination) and offboarding workflow emails.
return [
    'colleague' => 'Colleague',

    'subject' => [
        'submitted'                  => 'New Separation Request - :reference',
        'manager_approved'           => 'Separation Awaiting HR Approval - :reference',
        'hr_approved'                => 'Separation Awaiting Manager Approval - :reference',
        'offboarding_tasks'          => 'Offboarding Tasks Assigned - :reference',
        'department_tasks_completed' => 'Offboarding Tasks Completed - :reference',
        'default'                    => 'Separation Update - :reference',
    ],

    'banner' => [
        'submitted'         => 'New Separation Request',
        'manager_approved'  => 'Manager Approval Completed',
        'hr_approved'       => 'HR Approval Completed',
        'offboarding_tasks' => 'Offboarding Tasks Assigned',
        'default'           => 'Department Offboarding Tasks Completed',
    ],

    'intro' => [
        'submitted'         => 'A new separation request has been submitted and requires review.',
        'manager_approved'  => 'The direct manager has approved this separation request. HR approval is now required.',
        'hr_approved'       => 'HR has approved this resignation request. Direct manager approval is now required.',
        'offboarding_tasks' => 'Offboarding has started. Please complete the following <strong>:category</strong> tasks:',
        'completed'         => "All <strong>:category</strong> offboarding tasks have been resolved. HR may now review this department's checklist completion.",
        'completed_by'      => "All <strong>:category</strong> offboarding tasks have been resolved by <strong>:name</strong>. HR may now review this department's checklist completion.",
    ],

    'last_working_day' => 'Last Working Day',
    'assigned_tasks'   => 'Assigned tasks',
    'resolved_tasks'   => 'Resolved tasks',
    'button'           => 'Open Separation Request',
    'automated'        => 'Automated notification',

    'types' => [
        'resignation'      => 'Resignation',
        'termination'      => 'Termination',
        'end_of_contract'  => 'End of Contract',
        'retirement'       => 'Retirement',
        'abandonment'      => 'Abandonment',
        'mutual_agreement' => 'Mutual Agreement',
    ],

    'categories' => [
        'finance' => 'Finance',
        'it'      => 'IT',
        'hr'      => 'HR',
        'admin'   => 'Admin',
        'general' => 'General',
    ],
];
