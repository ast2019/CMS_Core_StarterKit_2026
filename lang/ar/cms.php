<?php

declare(strict_types=1);

return [

    'status' => [
        'draft' => 'مسودة',
        'review' => 'قيد المراجعة',
        'published' => 'منشور',
        'archived' => 'مؤرشف',
    ],

    'translation' => [
        'not_translated' => 'غير مترجم',
        'ai_translated' => 'ترجمة آلية',
        'reviewed' => 'تمت المراجعة',
        'outdated' => 'بحاجة إلى تحديث',
    ],

    'role' => [
        'admin' => 'مدير',
        'editor' => 'رئيس التحرير',
        'author' => 'كاتب',
        'viewer' => 'مشاهد',
    ],

    'redirect' => [
        'permanent' => 'دائم (301)',
        'temporary' => 'مؤقت (302)',
    ],

    'media_role' => [
        'featured' => 'الصورة البارزة',
        'inline' => 'داخل النص',
        'gallery' => 'معرض',
        'og_image' => 'صورة المشاركة',
    ],

    'validation' => [
        'featured_image_required' => 'اختيار صورة بارزة مطلوب.',
        'slides_max' => 'يمكنك إضافة :max شرائح كحد أقصى.',
        'slug_unique' => 'هذا المعرّف مستخدم بالفعل في اللغة :locale.',
        'alt_text_required' => 'النص البديل مطلوب لهذه الصورة.',
        'invalid_transition' => 'الانتقال من ":from" إلى ":to" غير مسموح.',
        'redirect_loop' => 'إعادة التوجيه هذه تعود إلى نفسها وتُنشئ حلقة.',
    ],

];
