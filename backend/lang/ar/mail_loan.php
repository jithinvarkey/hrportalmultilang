<?php

return [
    'subject' => [
        'submitted'           => 'طلب سلفة بانتظار مراجعتكم - :reference',
        'approved'            => 'تم اعتماد طلب السلفة - :reference',
        'rejected'            => 'تم رفض طلب السلفة - :reference',
        'installment_skipped' => 'تأجيل قسط سلفة - :reference',
    ],

    'banner' => [
        'submitted'           => 'طلب سلفة بانتظار المراجعة',
        'approved'            => 'تم اعتماد طلب السلفة',
        'rejected'            => 'تم رفض طلب السلفة',
        'installment_skipped' => 'تأجيل قسط سلفة',
    ],

    'intro' => [
        'submitted'           => 'وصل طلب سلفة إلى مرحلة الاعتماد الخاصة بكم ويتطلب مراجعتكم.',
        'approved'            => 'نفيدكم باعتماد طلب السلفة الخاص بكم نهائياً.',
        'rejected'            => 'نأسف لإبلاغكم بأنه تم رفض طلب السلفة الخاص بكم.',
        'installment_skipped' => 'تم تأجيل أحد أقساط السلفة، وأُضيف قسط بديل في نهاية جدول السداد.',
    ],

    'labels' => [
        'loan_type'               => 'نوع السلفة',
        'requested_amount'        => 'المبلغ المطلوب',
        'loan_reference'          => 'الرقم المرجعي للسلفة',
        'skipped_installment'     => 'القسط المؤجل',
        'replacement_installment' => 'القسط البديل',
        'notes'                   => 'ملاحظات',
        'skipped_by'              => 'تم التأجيل بواسطة',
    ],

    'installment_due' => 'رقم :number — يستحق في :date',
    'performed_on'    => ':name بتاريخ :date',

    'view_request'  => 'عرض طلب السلفة',
    'view_schedule' => 'عرض جدول السداد',
    'questions'     => 'لأي استفسار، يرجى التواصل مع الموارد البشرية: :email',
];
