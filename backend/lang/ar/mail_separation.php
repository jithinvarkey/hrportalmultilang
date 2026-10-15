<?php

// رسائل إنهاء الخدمة وإجراءات إخلاء الطرف.
return [
    'colleague' => 'الزميل',

    'subject' => [
        'submitted'                  => 'طلب إنهاء خدمة جديد - :reference',
        'manager_approved'           => 'طلب إنهاء خدمة بانتظار اعتماد الموارد البشرية - :reference',
        'hr_approved'                => 'طلب إنهاء خدمة بانتظار اعتماد المدير المباشر - :reference',
        'offboarding_tasks'          => 'تم إسناد مهام إخلاء الطرف - :reference',
        'department_tasks_completed' => 'اكتمال مهام إخلاء الطرف - :reference',
        'default'                    => 'تحديث على طلب إنهاء الخدمة - :reference',
    ],

    'banner' => [
        'submitted'         => 'طلب إنهاء خدمة جديد',
        'manager_approved'  => 'اكتمل اعتماد المدير المباشر',
        'hr_approved'       => 'اكتمل اعتماد الموارد البشرية',
        'offboarding_tasks' => 'تم إسناد مهام إخلاء الطرف',
        'default'           => 'اكتمال مهام إخلاء الطرف للإدارة',
    ],

    'intro' => [
        'submitted'         => 'تم تقديم طلب إنهاء خدمة جديد ويتطلب مراجعتك.',
        'manager_approved'  => 'اعتمد المدير المباشر طلب إنهاء الخدمة، ويتطلب الآن اعتماد الموارد البشرية.',
        'hr_approved'       => 'اعتمدت الموارد البشرية طلب الاستقالة، ويتطلب الآن اعتماد المدير المباشر.',
        'offboarding_tasks' => 'بدأت إجراءات إخلاء الطرف. يرجى إنجاز مهام <strong>:category</strong> التالية:',
        'completed'         => 'تم إنجاز جميع مهام إخلاء الطرف الخاصة بـ<strong>:category</strong>، ويمكن للموارد البشرية الآن مراجعة اكتمال قائمة المهام لهذه الإدارة.',
        'completed_by'      => 'تم إنجاز جميع مهام إخلاء الطرف الخاصة بـ<strong>:category</strong> بواسطة <strong>:name</strong>، ويمكن للموارد البشرية الآن مراجعة اكتمال قائمة المهام لهذه الإدارة.',
    ],

    'last_working_day' => 'آخر يوم عمل',
    'assigned_tasks'   => 'المهام المسندة',
    'resolved_tasks'   => 'المهام المنجزة',
    'button'           => 'فتح طلب إنهاء الخدمة',
    'automated'        => 'إشعار آلي',

    'types' => [
        'resignation'      => 'استقالة',
        'termination'      => 'إنهاء خدمة',
        'end_of_contract'  => 'انتهاء العقد',
        'retirement'       => 'تقاعد',
        'abandonment'      => 'انقطاع عن العمل',
        'mutual_agreement' => 'إنهاء بالاتفاق بين الطرفين',
    ],

    'categories' => [
        'finance' => 'المالية',
        'it'      => 'تقنية المعلومات',
        'hr'      => 'الموارد البشرية',
        'admin'   => 'الشؤون الإدارية',
        'general' => 'عامة',
    ],
];
