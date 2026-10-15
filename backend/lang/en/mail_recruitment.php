<?php

// Recruitment emails (applicants, interviews, offers, HR notifications).
return [
    'team'              => 'Recruitment Team',
    'auto_notice'       => 'This is an automated recruitment notification.',
    'candidate'         => 'Candidate',
    'position_fallback' => 'Position at :company',
    'confidential'      => 'Confidential',

    'rejected' => [
        'subject'          => 'Application Update — :name — :position',
        'subject_position' => 'your application',
        'p1'               => 'Thank you for your interest in the <strong>:position</strong> position and for the time you invested in our recruitment process.',
        'p1_generic'       => 'Thank you for your interest in the <strong>applied</strong> position and for the time you invested in our recruitment process.',
        'p2'               => 'After careful consideration, we have decided not to proceed with your application at this time. We appreciate the opportunity to learn about your experience and qualifications.',
        'p3'               => 'We encourage you to apply for future opportunities that match your skills and experience.',
    ],

    'interview' => [
        'subject'          => 'Interview Invitation — :name — :position',
        'banner'           => 'Interview Invitation',
        'intro'            => 'We are pleased to invite you for an interview for the position of <strong>:position</strong> at :company.',
        'intro_generic'    => 'We are pleased to invite you for an interview for <strong>the applied position</strong> at :company.',
        'time'             => 'Time',
        'location'         => 'Interview Location',
        'meeting_details'  => 'Meeting Details',
        'open_maps'        => 'Open in Google Maps',
        'minutes'          => ':count minutes',
        'interviewers'     => 'Interviewers',
        'hr_notes'         => 'Notes from HR:',
        'confirm'          => 'Please confirm your availability by replying to this email. If you need to reschedule, contact us at least 24 hours in advance.',
        'contact_hr'       => 'Contact HR:',
        'formats' => [
            'in_person' => 'Face to Face',
            'video'     => 'Video',
            'phone'     => 'Phone',
        ],
        // Calendar invitation (.ics)
        'ics_summary'      => 'Interview: :name — :position',
        'ics_position'     => 'Candidate Interview',
        'ics_description'  => 'Interview with :name for :position.',
        'ics_candidate'    => 'candidate',
        'ics_applied'      => 'the applied position',
        'ics_organizer'    => 'HR Team',
    ],

    'offer' => [
        'subject' => 'Job Offer — :name — :position',
        'p1'      => 'We are pleased to share your job offer letter. Please review the attached PDF carefully.',
        'p2'      => 'If you agree with the offer, please reply to this email. The onboarding form and NDA will be shared after HR confirms the hiring step.',
    ],

    'created' => [
        'subject_job'         => 'New job posting: :title',
        'subject_application' => 'New Applicant — :name — :position',
        'greeting'            => 'Dear HR Manager,',
        'intro_job'           => 'A new job has been posted and is ready for your review.',
        'intro_application'   => 'A new application has been submitted and is ready for your review.',
        'job_title'           => 'Job title',
        'department'          => 'Department',
        'employment_type'     => 'Employment type',
        'status'              => 'Status',
        'vacancies'           => 'Vacancies',
        'applicant'           => 'Applicant',
        'added_by'            => 'Added by',
        'email'               => 'Email',
        'phone'               => 'Phone',
        'not_specified'       => 'Not specified',
        'not_provided'        => 'Not provided',
        'public_applicant'    => 'Public applicant',
        'sign_in'             => 'Please sign in to the HR portal to review the details.',
    ],

    'employment_types' => [
        'full_time'  => 'Full time',
        'part_time'  => 'Part time',
        'contract'   => 'Contract',
        'intern'     => 'Intern',
        'internship' => 'Internship',
        'freelance'  => 'Freelance',
    ],

    'roles' => [
        'super_admin'           => 'Super Admin',
        'ceo'                   => 'CEO',
        'hr_manager'            => 'HR Manager',
        'hr_staff'              => 'HR Staff',
        'it_manager'            => 'IT Manager',
        'it_supervisor'         => 'IT Supervisor',
        'cybersecurity_officer' => 'Cybersecurity Officer',
        'finance_manager'       => 'Finance Manager',
        'department_manager'    => 'Department Manager',
        'employee'              => 'Employee',
    ],

    // Unused scaffold mailables (kept so they still produce a sensible subject).
    'application_subject'    => 'Recruitment Application',
    'status_changed_subject' => 'Recruitment Status Changed',
];
