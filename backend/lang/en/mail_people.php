<?php

// People lifecycle emails (birthday wishes, new colleague announcements).
return [
    'sent_by_hr' => 'Sent by Human Resources',

    'birthday' => [
        'image_alt' => 'Birthday message',
    ],

    'joined' => [
        'subject'      => 'Welcome Our New Colleague - :name',
        'title'        => 'Welcome Our New Colleague',
        'colleagues'   => 'Colleagues',
        'intro'        => 'We are pleased to welcome <strong>:name</strong>, who joins us today.',
        'job_title'    => 'Job title',
        'department'   => 'Department',
        'joining_date' => 'Joining date',
        'closing'      => 'Please join us in welcoming :name and wishing them every success.',
    ],

    // Unused scaffold mailable.
    'employee_created_subject' => 'Employee Created',
];
