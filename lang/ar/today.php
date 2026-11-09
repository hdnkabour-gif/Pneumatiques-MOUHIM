<?php

return [
    'title' => 'اليوم',
    'sales_today' => 'مبيعات اليوم',
    'sales_count' => 'عملية بيع',
    'vs_yesterday' => 'مقارنة بالأمس',
    'cash' => 'نقدا', 'card' => 'بطاقة', 'cheque' => 'شيكات', 'credit' => 'بالدين', 'expenses' => 'مصاريف',
    'margin' => 'الهامش', 'margin_incomplete' => 'غير كامل (تكلفة ناقصة)',
    'month' => 'هذا الشهر',
    'live' => 'مباشر',
    'set_targets' => 'أهداف الشهر',
    'events' => ['sale_confirmed' => 'بيع', 'quote_issued' => 'عرض سعر', 'credit_note' => 'فاتورة مضادة', 'cash_closed' => 'إغلاق الصندوق',
        'transfer_sent' => 'تحويل مرسل', 'transfer_received' => 'تحويل مستلم', 'payment_received' => 'استخلاص', 'expense' => 'مصروف'],
    'alerts' => [
        'approvals' => ':count طلب ينتظر موافقتك',
        'cheques' => ':count شيك حان أجله: :amount',
        'late' => ':count زبون مدين منذ أكثر من 30 يوما',
    ],
    'summary' => [
        'title' => 'ملخص :date',
        'line' => ':site: مبيعات :sales، نقدا :cash، بطاقة :card، شيكات :cheque، دين :credit، مصاريف :expenses',
        'total' => 'المجموع: :sales (:count عملية)',
    ],
];
