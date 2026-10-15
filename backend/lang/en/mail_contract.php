<?php

// Contract renewal emails.
return [
    'subject'         => 'Contract renewal request auto-created - :reference',
    'greeting'        => 'Dear Team,',
    'intro'           => 'A contract renewal request has been auto-created for <strong>:name</strong>.',
    'employee'        => 'Employee',
    'details'         => 'Renewal Details',
    'employee_code'   => 'Employee Code',
    'current_contract'=> 'Current Contract',
    'current_expiry'  => 'Current Expiry',
    'proposed_period' => 'Proposed Period',
    'period'          => ':from to :to',
    'period_open'     => ':from to Unlimited',
    'unlimited'       => 'Unlimited',
    'proposed_type'   => 'Proposed Type',
    'proposed_salary' => 'Proposed Salary',
    'money'           => ':currency :amount',
    'waiting'         => 'The request is waiting for manager review and will continue through the contract renewal approval workflow.',
    'login'           => 'Please log in to HRMS to review the request.',
    'auto_notice'     => 'This is an automated contract renewal notification.',

    'types' => [
        'full_time'  => 'Full Time',
        'part_time'  => 'Part Time',
        'contract'   => 'Contract',
        'intern'     => 'Intern',
        'probation'  => 'Probation',
        'fixed_term' => 'Fixed Term',
        'fixed'      => 'Fixed',
        'unlimited'  => 'Unlimited',
        'freelance'  => 'Freelance',
    ],
];
