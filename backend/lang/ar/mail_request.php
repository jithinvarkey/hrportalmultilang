<?php

return [
    'subject' => [
        'pending'     => 'طلب بانتظار المعالجة - :reference',
        'in_progress' => 'الطلب قيد التنفيذ - :reference',
        'completed'   => 'تم إنجاز الطلب - :reference',
        'rejected'    => 'تم رفض الطلب - :reference',
        'cancelled'   => 'تم إلغاء الطلب - :reference',
        'default'     => 'تحديث على الطلب - :reference',
    ],

    'banner' => 'الطلب: :status',
    'intro'  => 'حالة الطلب :reference الآن: :status.',

    'note' => [
        'pending'       => 'اعتمد المدير الطلب وتمت إحالته للمعالجة.',
        'in_progress'   => 'تم إسناد الطلب إلى :name.',
        'handling_team' => 'الفريق المختص',
        'cancelled'     => 'ألغى مقدّم الطلب هذا الطلب.',
    ],

    'labels' => [
        'request_type'     => 'نوع الطلب',
        'requested_by'     => 'مقدّم الطلب',
        'submitted'        => 'تاريخ التقديم',
        'completion_notes' => 'ملاحظات الإنجاز',
    ],

    'questions'     => 'لأي استفسار، يرجى التواصل مع الموارد البشرية: :email',
    'footer'        => ':company • :location • إشعار آلي',

    'general_notification' => [
        'subject' => 'إشعار عام',
    ],
];
