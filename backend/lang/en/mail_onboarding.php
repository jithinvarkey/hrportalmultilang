<?php

// New-hire onboarding emails.
return [
    'auto_notice'     => 'This is an automated onboarding notification.',
    'employee'        => 'Employee',
    'employee_number' => 'Employee number',
    'employee_code'   => 'Employee Code',
    'job_title'       => 'Job title',
    'joining_date'    => 'Joining date',
    'email'           => 'Email',
    'manager'         => 'Manager',

    'link' => [
        'subject'        => 'Complete your employee onboarding details',
        'welcome'        => 'Welcome to the team. Please complete your initial employee details and upload the requested onboarding documents using the secure link below.',
        'button'         => 'Complete Onboarding Details',
        'form_fields'    => 'The form asks for phone number, date of birth, address, bank details, ID/Iqama details, and optional passport details.',
        'attachments'    => 'Your prefilled NDA is attached as a PDF document for review and signature. A joining date form, prefilled with your name and job title, is also attached as a Word document.',
        'hdf'            => 'The HDF template is attached for you to complete and upload with your onboarding details.',
        'uploads'        => 'Please upload the signed offer letter, signed NDA, Iqama/ID copy, and national address copy in the form. Passport copy, experience letter, and educational documents are optional.',
        'login_details'  => 'Login Details',
        'temp_password'  => 'Temporary Password',
        'valid_until'    => 'This secure link is valid until :date.',
        'button_fallback'=> 'If the button does not open, copy this link into your browser:',
    ],

    'tasks' => [
        'subject'    => 'Onboarding tasks assigned - :name',
        'title'      => 'New Employee Onboarding Tasks',
        'greeting'   => 'Hello :group Team,',
        'intro'      => 'A new employee has been created. The following onboarding tasks require your attention.',
        'task'       => 'Task',
        'due_date'   => 'Due date',
        'note'       => "Please coordinate with HR and update the task status in the employee's Onboarding tab.",
        'groups' => [
            'it'         => 'IT',
            'hr'         => 'HR',
            'department' => 'Department',
        ],
    ],

    'it' => [
        'subject'     => 'New Hire Onboarding Submitted - :name',
        'title'       => 'New Hire IT Setup',
        'greeting'    => 'Hello IT Manager,',
        'intro'       => 'A new employee has submitted their onboarding details. Please prepare the required IT accounts, access, and equipment.',
        'auto_notice' => 'This is an automated HR notification.',
    ],

    'document' => [
        'subject'        => 'Employee document pending verification',
        'greeting'       => 'Dear HR Team,',
        'intro'          => '<strong>:name</strong> uploaded a document from the employee profile. Please verify it in HRMS.',
        'an_employee'    => 'An employee',
        'details'        => 'Document Details',
        'document_title' => 'Document Title',
        'document_type'  => 'Document Type',
        'file_name'      => 'File Name',
        'uploaded_at'    => 'Uploaded At',
        'closing'        => 'Please log in to HRMS and verify the uploaded employee document.',
        'auto_notice'    => 'This is an automated employee document verification notification.',
        'types' => [
            'contract'    => 'Contract',
            'id'          => 'ID',
            'certificate' => 'Certificate',
            'visa'        => 'Visa',
            'passport'    => 'Passport',
            'medical'     => 'Medical',
            'other'       => 'Other',
        ],
    ],
];
