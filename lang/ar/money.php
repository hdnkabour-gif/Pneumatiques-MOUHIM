<?php

return [
    'receive' => 'استخلاص',
    'receive_help' => 'يُخصم المبلغ تلقائيا من أقدم الديون.',
    'opening' => 'الرصيد الافتتاحي',
    'opening_help' => 'دين سابق قبل التطبيق (الكناش، Excel) بتاريخه.',
    'as_of' => 'التاريخ',
    'note' => 'ملاحظة',
    'amount' => 'المبلغ',
    'method' => 'الطريقة',
    'done' => 'تم تسجيل الاستخلاص.',
    'payments' => 'الاستخلاصات',
    'cheque_status' => ['received' => 'مستلم', 'deposited' => 'مودع في البنك', 'cleared' => 'مستخلص', 'rejected' => 'مرفوض'],
    'actions' => ['deposited' => 'أودع', 'cleared' => 'استُخلص', 'rejected' => 'رُفض'],
    'reason' => 'سبب الرفض',
    'errors' => [
        'amount' => 'مبلغ غير صحيح.',
        'more_than_due' => 'الزبون مدين بـ :due: لا يمكن أن يكون المبلغ أكبر.',
        'cheque_status' => 'لا يمكن هذا التغيير لهذا الشيك.',
    ],
];
