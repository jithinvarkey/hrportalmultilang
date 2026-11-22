<?php

return [
    'subject' => [
        'pending'     => 'Request Awaiting Processing - :reference',
        'pending_manager' => 'Request Awaiting Manager Approval - :reference',
        'submitted'   => 'Request Submitted - :reference',
        'approval_required' => 'Approval Required: New Request from Your Team - :reference',
        'in_progress' => 'Request In Progress - :reference',
        'final_approval_required' => 'Final Approval Required - :reference',
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
        'submitted'     => 'Your request has been submitted successfully and forwarded for processing.',
        'submitted_awaiting_manager' => 'Your request has been submitted and sent to your direct manager for approval.',
        'approval_required' => ':name has submitted a request that requires your approval. Please log in to the HR portal to review it.',
        'final_approval_required' => 'The direct manager has approved the request from :name. It has been assigned to you for final approval. Please log in to the HR portal to review it.',
        'new_for_department' => 'A new request from :name is awaiting processing by your department.',
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
