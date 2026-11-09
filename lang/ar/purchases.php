<?php

return [
    'suppliers' => 'الموردون', 'supplier' => 'مورد',
    'orders' => 'طلبيات الموردين', 'order' => 'طلبية',
    'fields' => [
        'name' => 'الاسم', 'phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'ice' => 'ICE', 'city' => 'المدينة', 'address' => 'العنوان',
        'payment_terms_days' => 'أجل الأداء (أيام)', 'opening_balance' => 'الرصيد الافتتاحي (المستحق)', 'notes' => 'ملاحظات',
        'balance' => 'ندين له', 'number' => 'الرقم', 'status' => 'الحالة', 'site' => 'موقع الاستلام', 'expected_at' => 'التسليم المتوقع',
        'lines' => 'المنتجات', 'product' => 'المنتج', 'quantity' => 'الكمية', 'received' => 'المستلم', 'unit_cost' => 'التكلفة الأحادية بدون ضريبة',
        'invoice_ref' => 'رقم فاتورة المورد', 'dot' => 'DOT (مثال 2425)', 'received_now' => 'تم الاستلام (استلام فوري)',
        'amount' => 'المبلغ', 'method' => 'الطريقة', 'reference' => 'المرجع', 'date' => 'التاريخ', 'total' => 'المجموع مع الضريبة',
    ],
    'status' => ['ordered' => 'مطلوبة', 'partial' => 'مستلمة جزئيا', 'received' => 'مستلمة', 'cancelled' => 'ملغاة'],
    'actions' => ['receive' => 'استلام', 'pay' => 'أداء', 'compare' => 'مقارنة الأثمنة'],
    'receipts' => 'الاستلامات', 'payments' => 'الأداءات',
    'cost_rise' => 'ارتفاع تكلفة «:product»: :from ← :to',
    'suggested_price' => 'ثمن البيع المقترح: :price',
    'no_history' => 'لا توجد مشتريات مسجلة.',
    'errors' => [
        'no_lines' => 'أضف منتجا واحدا على الأقل مع الكمية.',
        'order_closed' => 'هذه الطلبية مستلمة أو ملغاة.',
    ],
];
