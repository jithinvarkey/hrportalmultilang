<?php

return [
    'subject' => [
        'submitted'           => 'Loan Request Requires Review - :reference',
        'approved'            => 'Loan Request Approved - :reference',
        'rejected'            => 'Loan Request Rejected - :reference',
        'installment_skipped' => 'Loan Installment Skipped - :reference',
    ],

    'banner' => [
        'submitted'           => 'Loan Request Requires Review',
        'approved'            => 'Loan Request Approved',
        'rejected'            => 'Loan Request Rejected',
        'installment_skipped' => 'Loan Installment Skipped',
    ],

    'intro' => [
        'submitted'           => 'A loan request has reached your approval stage and requires your review.',
        'approved'            => 'Your loan request has been fully approved.',
        'rejected'            => 'Your loan request has been rejected.',
        'installment_skipped' => 'A loan installment was skipped and a replacement installment was added to the end of the schedule.',
    ],

    'labels' => [
        'loan_type'               => 'Loan Type',
        'requested_amount'        => 'Requested Amount',
        'loan_reference'          => 'Loan Reference',
        'skipped_installment'     => 'Skipped Installment',
        'replacement_installment' => 'Replacement Installment',
        'notes'                   => 'Notes',
        'skipped_by'              => 'Skipped By',
    ],

    'installment_due' => '#:number — due :date',
    'performed_on'    => ':name on :date',

    'view_request'  => 'View Loan Request',
    'view_schedule' => 'View Loan Schedule',
    'questions'     => 'Questions? Contact HR: :email',
];
