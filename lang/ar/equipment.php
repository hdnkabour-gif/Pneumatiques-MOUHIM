<?php

return [
    'label' => 'معدة',
    'plural' => 'المعدات',
    'history' => 'السجل (أعطال، إصلاحات، صيانة)',
    'every_help' => 'صيانة وقائية كل X يوم: يُحسب التاريخ التالي تلقائيا.',
    'broken_alert' => 'تم التبليغ عن عطل: :name (:site)',
    'fields' => [
        'code' => 'الرقم', 'name' => 'الاسم', 'category' => 'النوع', 'site' => 'الموقع', 'status' => 'الحالة', 'brand' => 'العلامة', 'model' => 'الطراز',
        'serial' => 'الرقم التسلسلي', 'purchased_at' => 'تاريخ الشراء', 'cost' => 'التكلفة', 'warranty_until' => 'الضمان إلى غاية',
        'every_days' => 'الصيانة كل (يوم)', 'next' => 'الصيانة القادمة', 'photo' => 'صورة', 'notes' => 'ملاحظات',
        'total_cost' => 'مجموع تكلفة الصيانة', 'date' => 'التاريخ', 'type' => 'النوع', 'description' => 'الأشغال / المشكل',
        'performed_by' => 'من طرف (تقني، شركة)', 'what' => 'ما هو المشكل؟',
    ],
    'status' => ['in_service' => 'في الخدمة', 'broken' => 'معطلة', 'retired' => 'خارج الخدمة'],
    'events' => ['breakdown' => 'عطل', 'repair' => 'إصلاح', 'maintenance' => 'صيانة'],
    'actions' => ['breakdown' => 'التبليغ عن عطل', 'service' => 'إصلاح / صيانة'],
    'categories' => [
        'tyre_changer' => 'آلة فك العجلات', 'balancer' => 'آلة التوازن', 'alignment' => 'جهاز ضبط الاتجاه', 'compressor' => 'ضاغط هواء',
        'nitrogen' => 'مولد الأزوت', 'lift' => 'رافعة سيارات', 'jack' => 'رافعة يدوية', 'impact_wrench' => 'مفتاح صدمي',
        'oil_equipment' => 'معدات تغيير الزيت', 'headlight_tester' => 'جهاز ضبط الأضواء', 'other' => 'أخرى',
    ],
    'alert_due' => ':count معدة تحتاج صيانة أو معطلة',
    'errors' => ['event' => 'أدخل النوع والأشغال المنجزة.'],
];
