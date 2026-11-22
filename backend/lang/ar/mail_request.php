<?php

return [
    'subject' => [
        'pending'     => 'طلب بانتظار المعالجة - :reference',
        'pending_manager' => 'طلب بانتظار اعتماد المدير - :reference',
        'submitted'   => 'تم تقديم الطلب - :reference',
        'approval_required' => 'مطلوب اعتمادك: طلب جديد من فريقك - :reference',
        'in_progress' => 'الطلب قيد التنفيذ - :reference',
        'final_approval_required' => 'مطلوب الاعتماد النهائي - :reference',
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
        'submitted'     => 'تم تقديم طلبك بنجاح وإحالته للمعالجة.',
        'submitted_awaiting_manager' => 'تم تقديم طلبك وإرساله إلى مديرك المباشر للاعتماد.',
        'approval_required' => 'قدّم :name طلبًا يتطلب اعتمادك. يرجى تسجيل الدخول إلى بوابة الموارد البشرية لمراجعته.',
        'final_approval_required' => 'اعتمد المدير المباشر الطلب المقدّم من :name، وتم إسناده إليك للاعتماد النهائي. يرجى تسجيل الدخول إلى بوابة الموارد البشرية لمراجعته.',
        'new_for_department' => 'يوجد طلب جديد من :name بانتظار المعالجة من قِبل إدارتكم.',
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
