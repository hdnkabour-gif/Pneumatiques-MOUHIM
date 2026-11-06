<?php

return [
    'label' => 'طلب موافقة',
    'plural' => 'الموافقات',
    'fields' => [
        'type' => 'النوع',
        'status' => 'الحالة',
        'site' => 'الموقع',
        'requested_by' => 'طلبه',
        'reason' => 'السبب',
        'summary' => 'الطلب',
        'decided_by' => 'قرّره',
        'decided_at' => 'تاريخ القرار',
        'decided_via' => 'الطريقة',
        'comment' => 'تعليق',
        'created_at' => 'تاريخ الطلب',
        'pin' => 'رمز PIN الخاص بالمالك',
        'approval_pin' => 'رمز PIN للموافقة',
        'approval_pin_help' => 'من 4 إلى 8 أرقام. يسمح بالموافقة على شاشة الموظف. اتركه فارغا للإبقاء عليه.',
    ],
    'via' => ['app' => 'التطبيق', 'pin' => 'رمز PIN', 'self' => 'موافقة ذاتية'],
    'actions' => [
        'approve' => 'موافقة',
        'reject' => 'رفض',
        'approve_with_pin' => 'موافقة برمز PIN',
        'open' => 'فتح',
    ],
    'notifications' => [
        'new' => 'طلب جديد: :type',
        'granted' => 'تمت الموافقة على طلبك',
        'rejected' => 'تم رفض طلبك',
    ],
    'messages' => [
        'approved' => 'تمت الموافقة وتنفيذ العملية.',
        'rejected' => 'تم رفض الطلب.',
    ],
    'summaries' => [
        'sale' => ':type – بيع :total، الزبون :customer (أقصى تخفيض :discount %، دين :credit)',
        'stock_adjustment' => 'تسوية :delta لـ « :product » في :site',
        'damaged_stock' => 'منتج تالف :delta لـ « :product » في :site',
    ],
    'errors' => [
        'reason_required' => 'اذكر السبب.',
        'already_decided' => 'تمت معالجة هذا الطلب من قبل.',
        'pin_invalid' => 'رمز PIN غير صحيح.',
        'pin_locked' => 'محاولات كثيرة: أعد المحاولة بعد دقيقة.',
    ],
];
