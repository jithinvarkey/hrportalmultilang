<?php

return [
    'subject' => [
        'pending'     => 'Request Awaiting Processing - :reference',
        'in_progress' => 'Request In Progress - :reference',
        'completed'   => 'Request Completed - :reference',
        'rejected'    => 'Request Rejected - :reference',
        'cancelled'   => 'Request Cancelled - :reference',
        'default'     => 'Request Update - :reference',
    ],

    'banner' => 'Request :status',
    'intro'  => 'Request :reference is now :status.',

    // System notes added to the status email (notes typed by users are shown as typed).
    'note' => [
        'pending'       => 'The manager approved the request and it has been forwarded for processing.',
        'in_progress'   => 'The request has been assigned to :name.',
        'handling_team' => 'the handling team',
        'cancelled'     => 'The request was cancelled by the requester.',
    ],

    'labels' => [
        'request_type'     => 'Request Type',
        'requested_by'     => 'Requested By',
        'submitted'        => 'Submitted',
        'completion_notes' => 'Completion notes',
    ],

    'questions'     => 'Questions? Contact HR: :email',
    'footer'        => ':company • :location • Automated notification',

    'general_notification' => [
        'subject' => 'General Notification',
    ],
];
