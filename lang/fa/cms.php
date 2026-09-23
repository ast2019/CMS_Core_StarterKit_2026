<?php

declare(strict_types=1);

/*
 * Persian (fa) — the source locale. This is the only locale with complete
 * content at launch; en/ar exist structurally so activating them later is a
 * content task, not a re-engineering task (Requirement 1 / blueprint §2).
 */

return [

    'status' => [
        'draft' => 'پیش‌نویس',
        'review' => 'در انتظار بازبینی',
        'published' => 'منتشرشده',
        'archived' => 'بایگانی‌شده',
    ],

    'translation' => [
        'not_translated' => 'ترجمه‌نشده',
        'ai_translated' => 'ترجمهٔ ماشینی',
        'reviewed' => 'بازبینی‌شده',
        'outdated' => 'نیازمند به‌روزرسانی',
    ],

    'role' => [
        'admin' => 'مدیر کل',
        'editor' => 'سردبیر',
        'author' => 'نویسنده',
        'viewer' => 'بازدیدکننده',
    ],

    'redirect' => [
        'permanent' => 'دائمی (۳۰۱)',
        'temporary' => 'موقت (۳۰۲)',
    ],

    'media_role' => [
        'featured' => 'تصویر شاخص',
        'inline' => 'درون‌متنی',
        'gallery' => 'گالری',
        'og_image' => 'تصویر اشتراک‌گذاری',
    ],

    'validation' => [
        'featured_image_required' => 'انتخاب تصویر شاخص الزامی است.',
        'slides_max' => 'حداکثر :max اسلاید می‌توانید داشته باشید.',
        'slug_unique' => 'این نشانی (اسلاگ) در زبان :locale قبلاً استفاده شده است.',
        'alt_text_required' => 'نوشتن متن جایگزین (alt) برای تصویر الزامی است.',
        'invalid_transition' => 'تغییر وضعیت از «:from» به «:to» مجاز نیست.',
        'redirect_loop' => 'این تغییر مسیر به خودش بازمی‌گردد و ایجاد حلقه می‌کند.',
    ],

];
