<?php

// Announcement / policy published emails. Titles and bodies are authored by HR and are never translated here.
return [
    'employee' => 'Employee',
    'greeting' => 'Dear <strong>:name</strong>,',

    'subject' => [
        'policy'       => 'New Policy: :title',
        'announcement' => 'New Announcement: :title',
    ],
    'banner' => [
        'policy'       => 'New Policy',
        'announcement' => 'New Announcement',
    ],
    'intro' => [
        'policy'       => 'A new policy has been published for you.',
        'announcement' => 'A new announcement has been published for you.',
    ],
    'view' => [
        'policy'       => 'View Policy',
        'announcement' => 'View Announcement',
    ],

    'lang_en'     => 'English',
    'lang_ar'     => 'العربية',
    'title'       => 'Title',
    'summary'     => 'Summary',
    'auto_notice' => 'This is an automated HRMS message, please do not reply.',

    // Generated policy summaries (the policy title itself stays as authored).
    'policy_message' => [
        'published'    => ':title has been published.',
        'requires_ack' => ':title requires your acknowledgement.',
        'updated'      => ':title updated to v:version - please re-acknowledge.',
    ],
];
