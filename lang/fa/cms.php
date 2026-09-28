<?php

declare(strict_types=1);

/*
 * Persian (fa) — the source locale and the only one with complete content at
 * launch. en/ar exist structurally so activating them later is a content task,
 * not a re-engineering task (blueprint §2, Requirement 5.1).
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
        'slug_changed_title' => 'نشانی این مطلب تغییر کرد',
        'slug_changed_body' => 'برای :count زبان نشانی تغییر کرده است. برای جلوگیری از خطای ۴۰۴ می‌توانید تغییر مسیر ۳۰۱ بسازید.',
        'create_action' => 'ساخت تغییر مسیر ۳۰۱',
        'created' => ':count تغییر مسیر ساخته شد.',
    ],

    /*
     * نوع دادهٔ ساختاریافتهٔ مطلب (App\Enums\ArticleSchemaType). عنوان نوع عیناً
     * همان چیزی است که در JSON-LD اعلام می‌شود، پس ترجمه نمی‌شود؛ فقط توضیح آن
     * ترجمه می‌شود.
     */
    'schema_type' => [
        'Article' => 'مطلب عمومی (Article)',
        'Article_help' => 'برای مطالبی که تاریخ انتشارشان اهمیت تعیین‌کننده ندارد: راهنما، معرفی، مقالهٔ ماندگار.',
        'NewsArticle' => 'خبر (NewsArticle)',
        'NewsArticle_help' => 'برای خبر روز. ادعای تازگی می‌کند؛ اگر مطلب ماندگار است این گزینه درست نیست.',
        'BlogPosting' => 'یادداشت وبلاگی (BlogPosting)',
        'BlogPosting_help' => 'برای یادداشت شخصی یا وبلاگی با لحن نویسنده‌محور.',
    ],

    'media_role' => [
        'featured' => 'تصویر شاخص',
        'inline' => 'درون‌متنی',
        'gallery' => 'گالری',
        'og_image' => 'تصویر اشتراک‌گذاری',
    ],

    'locale' => [
        'tabs' => 'زبان‌ها',
        'source_badge' => 'زبان مبنا',
    ],

    'nav' => [
        'content' => 'محتوا',
        'taxonomy' => 'دسته‌بندی',
        'media' => 'رسانه',
        'appearance' => 'نمایش سایت',
        'system' => 'سیستم',
    ],

    'resource' => [
        'content' => 'خبر',
        'contents' => 'اخبار',
        'category' => 'دسته',
        'categories' => 'دسته‌ها',
        'tag' => 'برچسب',
        'tags' => 'برچسب‌ها',
        'gallery' => 'گالری',
        'galleries' => 'گالری‌ها',
        'page' => 'صفحه',
        'pages' => 'صفحه‌ها',
        'slide' => 'اسلاید',
        'slides' => 'اسلایدشو',
        'media_asset' => 'فایل رسانه',
        'media_assets' => 'کتابخانهٔ رسانه',
        'menu_item' => 'آیتم منو',
        'menu_items' => 'منوها',
        'redirect' => 'تغییر مسیر',
        'redirects' => 'تغییر مسیرها',
        'contact_submission' => 'پیام تماس',
        'contact_submissions' => 'پیام‌های تماس',
        'form' => 'فرم',
        'forms' => 'فرم‌ها',
        'user' => 'کاربر',
        'users' => 'کاربران',
    ],

    'section' => [
        'seo' => 'سئو و متادیتا',
        'publishing' => 'انتشار',
        'featured_image' => 'تصویر شاخص',
        'media' => 'فایل',
        'link' => 'پیوند',
        'appearance' => 'ظاهر',
        'identity' => 'هویت سایت',
        'analytics' => 'آنالیتیکس و تأیید مالکیت',
        'social_card' => 'کارت اشتراک‌گذاری (اختیاری)',
        'social_card_help' => 'اگر خالی بماند، همان عنوان و توضیح متا برای شبکه‌های اجتماعی استفاده می‌شود.',
        'diagnostics' => 'اطلاعات فنی',
    ],

    'field' => [
        'two_factor' => 'احراز دو مرحله‌ای',
        'title' => 'عنوان',
        'name' => 'نام',
        'slug' => 'نشانی (اسلاگ)',
        'slug_help' => 'اگر خالی بماند به‌طور خودکار از عنوان ساخته می‌شود. تغییر آن در مطلب منتشرشده نشانی عمومی را عوض می‌کند.',
        'excerpt' => 'خلاصه',
        'description' => 'توضیح',
        'body' => 'متن',
        'blocks' => 'محتوا',
        'answer_paragraph' => 'پاسخ کوتاه (GEO)',
        'answer_paragraph_help' => 'یک پاراگراف مستقل که بدون نیاز به بقیهٔ متن، پرسش اصلی را پاسخ دهد. موتورهای پاسخ‌گو همین را نقل می‌کنند.',
        'meta_title' => 'عنوان متا',
        'meta_title_help' => 'اگر خالی بماند از عنوان مطلب استفاده می‌شود. حدود ۶۰ نویسه توصیه می‌شود.',
        'meta_description' => 'توضیح متا',
        'meta_description_help' => 'اگر خالی بماند از خلاصه استفاده می‌شود. حدود ۱۵۵ نویسه توصیه می‌شود.',
        'robots_meta' => 'دستور ربات‌ها',
        'robots_meta_help' => 'پیش‌نویس‌ها و ترجمه‌های بازبینی‌نشده به‌طور خودکار noindex می‌شوند.',
        'focus_keyphrase' => 'کلیدواژهٔ هدف',
        'focus_keyphrase_help' => 'عبارتی که می‌خواهید این مطلب در همین زبان با آن پیدا شود. برای هر زبان جداگانه است و ترجمهٔ عبارت فارسی نیست.',
        'og_title' => 'عنوان کارت اشتراکگذاری',
        'og_title_help' => 'اگر خالی بماند از عنوان متا استفاده می‌شود.',
        'og_description' => 'توضیح کارت اشتراکگذاری',
        'og_description_help' => 'اگر خالی بماند از توضیح متا استفاده می‌شود.',
        'schema_type' => 'نوع مطلب در دادهٔ ساختاریافته',
        'schema_type_help' => 'این انتخاب مستقیماً در JSON-LD صفحه اعلام می‌شود و برای همهٔ زبان‌ها یکسان است.',
        'status' => 'وضعیت',
        'publish_date' => 'تاریخ انتشار',
        'publish_date_help' => 'تاریخ آینده به معنی انتشار زمان‌بندی‌شده است؛ مطلب تا آن زمان برای عموم نمایش داده نمی‌شود.',
        'primary_category' => 'دستهٔ اصلی',
        'primary_category_help' => 'نشانی مطلب و مسیر راهنما (breadcrumb) از این دسته ساخته می‌شود.',
        'categories' => 'دسته‌ها',
        'tags' => 'برچسب‌ها',
        'author' => 'نویسنده',
        'featured_image' => 'تصویر شاخص',
        'featured_image_help' => 'از کتابخانهٔ رسانه انتخاب کنید. انتخاب تصویر شاخص الزامی است.',
        'gallery_items_help' => 'ترتیب انتخاب، ترتیب نمایش تصویرها در گالری است.',
        'translation_status' => 'وضعیت ترجمه',
        'parent' => 'والد',
        'parent_help' => 'خالی بگذارید تا این مورد در سطح اول منو بماند. عمق منو حداکثر :depth سطح است.',
        'position' => 'ترتیب',
        'is_active' => 'فعال',
        'link' => 'پیوند',
        'menu_key' => 'منو',
        'menu_key_help' => 'جایگاه‌های منو در فایل تنظیمات سایت تعریف می‌شوند. فهرست زیر همان چیزی است که قالب سایت می‌تواند نمایش دهد.',
        'target' => 'مقصد',
        'target_help' => 'برای جستوجو چند حرف از عنوان را بنویسید. نشانی این مورد در هر زبان از اسلاگ همان زبانِ مقصد ساخته میشود.',
        'opens_in_new_tab' => 'باز شدن در تب جدید',
        'alt_text' => 'متن جایگزین (alt)',
        'alt_text_help' => 'برای دسترس‌پذیری و سئوی تصویر الزامی است.',
        'caption' => 'زیرنویس',
        'type' => 'نوع',
        'file' => 'فایل',
        'duration_seconds' => 'مدت (ثانیه)',
        'external_embed_url' => 'نشانی جای‌گذاری خارجی',
        'video_thumbnail' => 'تصویر بندانگشتی ویدیو',
        'video_thumbnail_help' => 'برای نقشهٔ سایت ویدیو الزامی است. اگر ffprobe نصب نباشد باید دستی بارگذاری شود.',
        'from_path' => 'از مسیر',
        'to_path' => 'به مسیر',
        'redirect_type' => 'نوع تغییر مسیر',
        'hits' => 'تعداد بازدید',
        'subtitle' => 'زیرعنوان',
        'cta_label' => 'متن دکمه',
        'image_dimensions' => 'ابعاد تصویر',
        'image_dimensions_help' => 'برای جلوگیری از جابه‌جایی چیدمان (CLS) الزامی است.',
        'read_at' => 'زمان مطالعه',
        'spam_reason' => 'دلیل هرزنامه',
        'last_login' => 'آخرین ورود',
        'never_signed_in' => 'هرگز وارد نشده',
        'preview' => 'پیش‌نمایش',
        'read_status' => 'وضعیت خواندن',
        'user_agent' => 'مرورگر فرستنده',
        'message' => 'پیام',
        'email' => 'ایمیل',
        'phone' => 'تلفن',
        'subject' => 'موضوع',
        'page_role' => 'نقش صفحه',
        'page_role_help' => 'نقش، صفحه‌ای را مشخص می‌کند که برنامه آن را با نام می‌شناسد و نه با اسلاگ. صفحهٔ اصلی روی نشانی /fa (ریشهٔ هر زبان) نمایش داده می‌شود و نه روی /fa/اسلاگ. فقط یک صفحه می‌تواند صفحهٔ اصلی باشد.',
        'system_key' => 'کلید سیستمی',
        'password' => 'گذرواژه',
        'role' => 'نقش',
    ],

    'dashboard' => [
        'title' => 'پیشخوان',
        'today' => 'امروز، :date',

        'live' => 'منتشرشده',
        'live_this_month' => ':count مورد در این ماه',
        'in_progress' => 'در جریان تولید',
        'in_progress_breakdown' => 'پیش‌نویس: :drafts — در بازبینی: :review',
        'scheduled' => 'زمان‌بندی‌شده',
        'next_publish' => 'بعدی: :date',
        'nothing_scheduled' => 'چیزی زمان‌بندی نشده',
        // Item 18 — replaces the promised publish date when cron is not running.
        'scheduler_stopped' => 'زمان‌بند متوقف است؛ این موارد منتشر نمی‌شوند.',
        // Raised for records whose time has ALREADY passed while cron was down: they are
        // live by the database's reckoning but the Delivery cache was never refreshed, so
        // they are probably not on the public site.
        'scheduler_missed' => 'زمان‌بند متوقف است و زمان انتشار :count مورد گذشته؛ احتمالاً روی سایت دیده نمی‌شوند.',
        'unread_messages' => 'پیام‌های خوانده‌نشده',
        'inbox_clear' => 'همهٔ پیام‌ها خوانده شده',

        'publishing_activity' => 'روند انتشار',
        'publishing_activity_description' => 'شمار مطالب منتشرشده در هر ماه',
        'published_count' => 'منتشرشده',

        'translation_progress' => 'وضعیت ترجمه',
        'reviewed_ratio' => ':reviewed از :total بازبینی‌شده',
        'no_translation_records' => 'هنوز رکوردی برای این زبان ثبت نشده',

        'recent_activity' => 'آخرین رخدادها',
    ],

    /*
     * Date picker chrome. The month and weekday NAMES are not here on purpose:
     * they come from ICU via App\Support\Dates\LocalizedDate, because «مهر» is a
     * property of the Persian calendar rather than a string this CMS translates.
     */

    'date' => [
        'today' => 'امروز',
        'clear' => 'پاک کردن',
        'previous_month' => 'ماه قبل',
        'next_month' => 'ماه بعد',
    ],

    'category' => [
        'cannot_detach_primary' => 'این دستهٔ اصلی مطلب است و نشانی یکتا و مسیر راهنما را تعیین می‌کند. اول دستهٔ اصلی را روی خود مطلب عوض کن، بعد از اینجا جدا کن.',
        'primary_skipped' => ':count مطلب جدا نشد چون این دستهٔ اصلی‌شان است.',
    ],
    'table' => [
        'primary' => 'اصلی',
        'scheduled' => 'زمان‌بندی‌شده',
        'unread' => 'خوانده‌نشده',
        'items' => 'مورد',
        'no_alt_text' => 'بدون متن جایگزین',
    ],

    'filter' => [
        'needs_translation' => 'نیازمند ترجمه',
        'scheduled' => 'زمان‌بندی‌شده',
        'unread' => 'خوانده‌نشده',
        'missing_alt_text' => 'بدون متن جایگزین',
        'unattached' => 'بدون پیوست',
        'unattached_indicator' => 'بدون پیوست — تصاویر داخل متن بررسی نمی‌شوند',
        'active' => 'فعال',
        'spam' => 'هرزنامه',
        'spam_all' => 'همه پیام‌ها',
        'spam_only' => 'فقط هرزنامه',
        'spam_excluded' => 'بدون هرزنامه',
    ],

    'action' => [
        'detach_selected' => 'جدا کردن موارد انتخاب‌شده از این دسته',
        'detach_selected_done' => ':count مطلب از این دسته جدا شد.',
        'publish_selected' => 'انتشار موارد انتخاب‌شده',
        'publish_selected_confirm' => 'موارد انتخاب‌شده منتشر می‌شوند. برای موردی که تاریخ انتشار ندارد همین لحظه ثبت می‌شود؛ تاریخ آیندهٔ تنظیم‌شده دست نمی‌خورد.',
        'publish_selected_done' => ':count مورد منتشر شد.',
        'unpublish_selected' => 'لغو انتشار موارد انتخاب‌شده',
        'unpublish_selected_confirm' => 'موارد انتخاب‌شده به پیش‌نویس برمی‌گردند و از سایت عمومی حذف می‌شوند.',
        'unpublish_selected_done' => 'انتشار :count مورد لغو شد.',
        'bulk_skipped' => ':count مورد به دلیل نداشتن دسترسی تغییر نکرد.',
        // Already in the requested status. Distinct from bulk_skipped: nothing was refused,
        // there was simply nothing to do — and reporting it as a refusal would send an
        // editor looking for a permission problem that does not exist.
        'bulk_unchanged' => ':count مورد از قبل همین وضعیت را داشت.',
        'mark_read_selected' => 'علامت‌گذاری به‌عنوان خوانده‌شده',
        'mark_read_selected_done' => ':count پیام خوانده‌شده علامت خورد.',
        'mark_spam' => 'انتقال به هرزنامه',
        // Item 11 — the bulk delete reports a COUNT because it may have kept some of the
        // selection back; "deleted" with no number would hide that.
        'delete_selected_done' => ':count مورد به سطل زباله رفت.',
        'delete_selected_blocked' => 'بخشی از انتخاب حذف نشد',
        'mark_not_spam' => 'هرزنامه نیست',
        'reset_two_factor' => 'ریست احراز دو مرحله‌ای',
        'reset_two_factor_confirm' => 'کلید و کدهای بازیابی این کاربر پاک می‌شود و در ورود بعدی باید دوباره احراز دو مرحله‌ای را تنظیم کند. برای کسی که گوشی‌اش را گم کرده همین لازم است.',
        'reset_two_factor_done' => 'احراز دو مرحله‌ای :name ریست شد.',
        'activate_selected' => 'فعال‌سازی کاربران انتخاب‌شده',
        'activate_selected_done' => ':count کاربر فعال شد.',
        'deactivate_selected' => 'غیرفعال‌سازی کاربران انتخاب‌شده',
        'deactivate_selected_done' => ':count کاربر غیرفعال شد.',
        'own_account_skipped' => 'حساب خودت تغییر نکرد؛ غیرفعال کردن خود یعنی خارج شدن از همین صفحه.',
        'edit' => 'ویرایش',
        'preview' => 'پیش‌نمایش',
        'publish' => 'انتشار',
        'archive' => 'بایگانی',
        'mark_read' => 'علامت‌گذاری به‌عنوان خوانده‌شده',
        'review_translation' => 'تأیید ترجمه',
        'restore_version' => 'بازگردانی این نسخه',
        'translate_ai' => 'ترجمه با هوش مصنوعی',
    ],

    'blocks' => [
        'callout' => [
            'label' => 'کادر تأکید',
            'description' => 'یک کادر برجسته برای نکته، هشدار یا توضیح تکمیلی.',
            'tone' => 'نوع',
            'tone_info' => 'اطلاع',
            'tone_success' => 'موفقیت',
            'tone_warning' => 'هشدار',
            'tone_danger' => 'خطر',
            'title' => 'عنوان کادر',
            'body' => 'متن کادر',
        ],
        'hero' => [
            'label' => 'بنر',
            'description' => 'بنر تمام‌عرض با عنوان، متن کوتاه و دکمه.',
            'heading' => 'عنوان',
            'lead' => 'متن کوتاه',
            'image' => 'تصویر',
            'image_help' => 'از کتابخانهٔ رسانه انتخاب می‌شود تا متن جایگزین و ذخیره‌سازی محلی حفظ شود.',
            'cta_label' => 'متن دکمه',
            'cta_url' => 'نشانی دکمه',
        ],
        'quote' => [
            'label' => 'نقل قول',
            'description' => 'نقل قول برجسته همراه با منبع.',
            'quote' => 'متن نقل قول',
            'attribution' => 'گوینده',
            'attribution_role' => 'سمت گوینده',
        ],
        'gallery_embed' => [
            'label' => 'گالری',
            'description' => 'جای‌گذاری یک گالری موجود با ارجاع، تا ویرایش بعدی گالری در همهٔ مطالب اعمال شود.',
            'gallery' => 'گالری',
            'layout' => 'چیدمان',
            'layout_grid' => 'شبکه‌ای',
            'layout_carousel' => 'اسلایدی',
            'layout_masonry' => 'آجری',
            'max_items' => 'حداکثر تعداد تصویر',
            'max_items_help' => 'خالی بگذارید تا همهٔ تصاویر نمایش داده شوند.',
            'missing' => 'گالری انتخاب‌شده حذف شده است.',
            'empty' => 'این گالری تصویری ندارد.',
        ],
    ],

    'page' => [
        'homepage' => 'صفحهٔ اصلی',
        'role_none' => 'صفحهٔ معمولی',
        'system_role' => 'صفحهٔ سیستمی (:key)',
    ],

    'menu' => [
        /*
         * Labels for the menu locations declared in `cms.menus.locations`.
         * A location with no entry here falls back to its own key, so a client site
         * can add one to config and ship without editing three lang files.
         */
        'location' => [
            'header' => 'منوی بالای سایت',
            'footer' => 'منوی پانویس',
            'sidebar' => 'منوی کنار صفحه',
        ],
        'target_not_live' => 'منتشرنشده',
    ],

    'media' => [
        'inline_upload' => 'بارگذاری تصویر تازه',
        'inline_upload_heading' => 'بارگذاری تصویر در کتابخانهٔ رسانه',
        'inline_upload_description' => 'تصویر در کتابخانهٔ رسانه ثبت و همینجا انتخاب میشود؛ لازم نیست این صفحه را ترک کنید.',
        'inline_upload_submit' => 'بارگذاری و انتخاب',

        // Item 55 — these were rendered as the raw English keys (`image`, `video`, `document`)
        // in a panel that is otherwise Persian, Arabic or English throughout.
        'type' => [
            'image' => 'تصویر',
            'video' => 'ویدیو',
            'document' => 'سند',
        ],
        'size' => 'حجم',
        'size_kb' => ':size کیلوبایت',
        'size_mb' => ':size مگابایت',

        // Item 12.
        'usage' => [
            'heading' => 'محل‌های استفاده',
            'caveat' => 'تصاویری که داخل متن گذاشته شده‌اند (بلوک هیرو و تصاویر درون‌متنی) ردیابی نمی‌شوند و در این فهرست نمی‌آیند.',
            'none' => 'به هیچ رکوردی پیوست نشده و لوگوی سایت هم نیست.',
            'summary' => 'در :count جا استفاده شده:',
            'logo' => 'لوگوی سایت',
            'trashed' => 'در سطل زباله',
            'more' => '…و :count مورد دیگر.',
        ],
        'replace' => [
            'action' => 'جایگزینی فایل',
            'heading' => 'جایگزینی این فایل در همه‌جا',
            'submit' => 'جایگزین کن',
            'done' => 'فایل جایگزین شد',
            'locked_hint' => 'برای عوض کردن فایل از «جایگزینی فایل» در بالای صفحه استفاده کنید؛ اول نشان می‌دهد فایل کجا استفاده شده.',
            'unused' => 'این فایل به هیچ رکوردی پیوست نشده است.',
            'used' => 'این فایل در :usage استفاده شده و همهٔ آن‌ها بلافاصله فایل جدید را نشان می‌دهند، از جمله صفحه‌های منتشر‌شده.',
            'logo' => 'این فایل لوگوی سایت هم هست.',
            'previous_deleted' => 'فایل فعلی حذف می‌شود و قابل بازگرداندن نیست.',
            'video' => 'مدت و ابعاد پاک می‌شوند و در صورت امکان از فایل جدید دوباره خوانده می‌شوند؛ تصویر پوستر باقی می‌ماند، آن را بررسی کنید.',
        ],
        'validation' => [
            'file_extension' => 'پسوند فایل برای نوع «:type» باید یکی از این‌ها باشد: :extensions.',
            'file_type' => 'محتوای این فایل (:mime) برای نوع «:type» پذیرفته نیست.',
            'type_mismatch' => 'نوع «:type» نمی‌تواند این فایل (:mime) را نگه دارد. نوع متناسب با فایل را انتخاب کنید یا فایل دیگری بارگذاری کنید.',
        ],
    ],

    'seo' => [
        'warnings' => 'بررسی سئو',
        'no_warnings' => 'هشداری وجود ندارد.',
        'character_count' => ':count از :limit نویسه',

        'preview' => [
            'label' => 'پیش‌نمایش در نتایج جست‌وجو',
            'no_url' => 'در این زبان نشانی (اسلاگ) ندارد، پس در نتایج این زبان نمایش داده نمی‌شود.',
            'empty_title' => '(عنوانی برای نمایش نیست)',
            'empty_description' => '(توضیحی برای نمایش نیست)',
            'cut_hint' => 'این بخش در نتایج جست‌وجو نمایش داده نمی‌شود.',
            'noindex' => 'این مطلب در این زبان با دستور «:robots» از فهرست موتورهای جست‌وجو کنار گذاشته می‌شود؛ پیش‌نویس بودن یا بازبینی‌نشدن ترجمه هم به‌تنهایی همین اثر را دارد.',
        ],

        'analysis' => [
            'label' => 'بررسی کلیدواژهٔ هدف',
            'no_keyphrase' => 'کلیدواژهٔ هدف را وارد کنید تا بررسی‌های مربوط به آن انجام شود.',
            'caveat' => 'این بررسی‌ها فقط تطبیق حرف‌به‌حرف عبارت را می‌سنجند: ریشه‌یابی واژه، شکل‌های جمع و مترادف‌ها در فارسی و عربی سنجیده نمی‌شوند و نمرهٔ خوانایی هم محاسبه نمی‌شود. بررسی‌های مربوط به متن، پس از ذخیره به‌روز می‌شوند.',
            'band' => [
                'good' => 'وضعیت خوب',
                'fair' => 'قابل بهبود',
                'poor' => 'نیاز به بازنگری',
            ],
            'check' => [
                'keyphrase_in_title' => [
                    'pass' => 'کلیدواژه در عنوان متا آمده است.',
                    'warn' => 'کلیدواژه در عنوان متا نیست؛ این مهم‌ترین جای آن است.',
                ],
                'keyphrase_in_description' => [
                    'pass' => 'کلیدواژه در توضیح متا آمده است.',
                    'warn' => 'کلیدواژه در توضیح متا نیست. این در رتبه اثر ندارد، اما در نتایج پررنگ می‌شود و نرخ کلیک را بالا می‌برد.',
                ],
                'keyphrase_in_slug' => [
                    'pass' => 'کلیدواژه در نشانی (اسلاگ) آمده است.',
                    'warn' => 'کلیدواژه در نشانی (اسلاگ) نیست.',
                ],
                'keyphrase_in_opening' => [
                    'pass' => 'کلیدواژه در آغاز متن (حدود :opening_words واژهٔ اول) آمده است.',
                    'warn' => 'کلیدواژه در آغاز متن (حدود :opening_words واژهٔ اول) نیست.',
                ],
                'keyphrase_in_heading' => [
                    'pass' => 'کلیدواژه در دست‌کم یکی از تیترهای متن آمده است.',
                    'warn' => 'کلیدواژه در هیچ تیتری از متن نیست.',
                ],
                'keyphrase_density' => [
                    'pass' => 'تکرار کلیدواژه :value است و در بازهٔ توصیه‌شده (:density_min٪ تا :density_max٪) قرار دارد.',
                    'warn' => 'تکرار کلیدواژه :value است؛ بازهٔ توصیه‌شده :density_min٪ تا :density_max٪ است.',
                ],
                'content_length' => [
                    'pass' => 'طول متن :value واژه است.',
                    'warn' => 'طول متن :value واژه است و از :min_words واژهٔ توصیه‌شده کمتر است.',
                ],
                'heading_distribution' => [
                    'pass' => 'متن با تیتر به بخش‌های قابل خواندن تقسیم شده است.',
                    'warn' => 'بخشی از متن :value واژه بدون تیتر است؛ هر :section_words واژه دست‌کم یک تیتر توصیه می‌شود.',
                ],
            ],
        ],
        'warning' => [
            'missing_title' => 'عنوان متا خالی است و جایگزینی هم برای آن پیدا نشد.',
            'title_too_long' => 'عنوان متا از :title_limit نویسهٔ توصیهشده بلندتر است و گوگل آن را کوتاه میکند.',
            'missing_description' => 'توضیح متا خالی است و جایگزینی هم برای آن پیدا نشد.',
            'description_too_long' => 'توضیح متا از :description_limit نویسهٔ توصیهشده بلندتر است و گوگل آن را کوتاه میکند.',
        ],
    ],

    'validation' => [
        'media_file_required' => 'انتخاب فایل تصویر الزامی است.',
        'featured_image_required' => 'انتخاب تصویر شاخص الزامی است.',
        'slides_max' => 'حداکثر :max اسلاید می‌توانید داشته باشید.',
        'slug_unique' => 'این نشانی (اسلاگ) در زبان :locale قبلاً استفاده شده است.',
        'alt_text_required' => 'نوشتن متن جایگزین (alt) برای تصویر الزامی است.',
        'invalid_transition' => 'تغییر وضعیت از «:from» به «:to» مجاز نیست.',
        'redirect_loop' => 'این تغییر مسیر به خودش بازمی‌گردد و ایجاد حلقه می‌کند.',
        'video_thumbnail_required' => 'برای انتشار ویدیوی محلی، بارگذاری تصویر بندانگشتی الزامی است.',
        'link_target_required' => 'باید یکی از دو گزینه مشخص شود: یک پیوند دستی یا یک مقصد از داخل سایت.',
        'menu_target_required' => 'برای هر مورد منو باید یکی از دو گزینه مشخص شود: یک پیوند دستی یا یک مقصد از داخل سایت.',
        'menu_key_unknown' => 'جایگاه «:key» در تنظیمات این سایت تعریف نشده است. جایگاه‌های موجود: :locations',
        'system_key_taken' => 'نقش «:key» همین حالا به صفحهٔ «:title» داده شده است. ابتدا آن را از همان صفحه بردارید و سپس این صفحه را ذخیره کنید؛ در غیر این صورت دو صفحه برای یک نشانی رقابت می‌کنند.',
        'menu_target_missing' => 'مقصد انتخابشده وجود ندارد یا از نوع دیگری است. یک مورد دیگر انتخاب کنید.',
        'menu_parent_missing' => 'والد انتخابشده وجود ندارد.',
        'menu_parent_cycle' => 'این مورد نمیتواند زیرمجموعهٔ خودش یا یکی از زیرمجموعههایش باشد؛ در آن صورت کل این شاخه از منو ناپدید میشود.',
        'menu_depth' => 'عمق منو حداکثر :depth سطح است و این انتخاب از آن فراتر میرود.',
    ],

    /*
    | Item 11 — what depends on a record, and why a delete was refused.
    |
    | Built by App\Services\Content\UsageInspector, which returns KEYS plus parameters rather
    | than sentences, so the panel decides the presentation and the wording stays here.
    |
    | `blocked` messages all name what to change FIRST. "Cannot delete" on its own leaves an
    | editor with no move except to give up or to go looking for a way around the rule.
    */
    'usage' => [
        'blocked' => [
            'media_featured' => 'این تصویر، تصویر شاخص :count مورد است (شامل پیش‌نویس‌ها و موارد سطل زباله) و حذفش آن‌ها را بی‌تصویر می‌کند. اول برای آن موارد تصویر شاخص دیگری بگذار.',
            'category_primary' => 'این دستهٔ اصلی :count مطلب است و نشانی یکتا و مسیر راهنمای آن‌ها را تعیین می‌کند. اول دستهٔ اصلی آن مطالب را عوض کن. (فیلتر «حذف‌شده‌ها» در فهرست مطالب را هم ببین.)',
            // The site logo lives as an id inside a Setting document, not as an attachment row, so
            // nothing else here can see it — and losing it drops `logo` from the Organization JSON-LD.
            'media_logo' => 'این تصویر، نشان (لوگو) سایت است و حذفش آن را از دادهٔ ساخت‌یافتهٔ سازمان برمی‌دارد. اول در تنظیمات نشان دیگری انتخاب کن.',
            // Refused only for PERMANENT deletion: something in the trash still needs this, so
            // destroying it would make that record come back wrong rather than not come back.
            'restorable_dependents' => ':count مورد در سطل زباله هنوز به این وابسته است؛ حذف همیشگی باعث می‌شود آن‌ها ناقص بازگردند. اول آن‌ها را بازگردان یا برای همیشه حذف کن.',
        ],
        /*
         * Item 56 — each label carries its own number. Persian takes a singular noun after a
         * numeral («۳ مطلب»), so one form is correct for every count.
         */
        'label' => [
            'articles' => ':count مطلب',
            'child_categories' => ':count زیردسته',
            'navigation_links' => ':count پیوند در منو یا اسلاید',
            'menu_children' => ':count زیرمجموعهٔ منو',
            'attached_to_content' => ':count پیوست مطلب',
            'attached_to_page' => ':count پیوست صفحه',
            'attached_to_gallery' => ':count تصویر گالری (گالری همین‌قدر تصویر کمتر نشان می‌دهد)',
            'attached_to_slide' => ':count پیوست اسلاید',
            'attached_to_other' => ':count پیوست در موارد دیگر',
        ],
        'in_use' => 'این مورد جایی استفاده شده است: :usage. با حذف آن، آن‌ها این مورد را از دست می‌دهند.',
    ],

    /*
    | Item 10 — the trash itself.
    */
    'trash' => [
        'filter' => 'حذف‌شده‌ها',
        'cascade' => 'با حذف این مورد، :count زیرمجموعهٔ آن هم به سطل زباله می‌رود و با بازگرداندنش برمی‌گردند.',
        'only_trashed' => 'فقط حذف‌شده‌ها',
        'without_trashed' => 'بدون حذف‌شده‌ها',
        'with_trashed' => 'همه، شامل حذف‌شده‌ها',
        'pruned' => ':count مورد که بیش از :days روز در سطل زباله بود برای همیشه حذف شد.',
        'nothing_pruned' => 'چیزی در سطل زباله به حد نگهداری نرسیده بود.',
        'prune_blocked' => ':count مورد حذف نشد چون هنوز جایی استفاده می‌شود؛ در سطل زباله می‌ماند.',
        // A different fact from `prune_blocked`: that was a decision, this was a surprise.
        'prune_failed' => 'حذف :count مورد با خطا روبه‌رو شد و در سطل زباله ماند؛ پیام خطای هر کدام در بالا آمده است.',
    ],

    'system' => [
        'version' => 'نسخه',
        'changelog' => 'تغییرات',
        'recent_changes' => 'آخرین تغییرات',
        'installed_at' => 'زمان نصب',
        'about' => 'دربارهٔ سیستم',
        'no_changelog' => 'هنوز تغییری ثبت نشده است.',

        // Keep a Changelog section names (App\Models\Changelog::CATEGORIES), shown in
        // the About widget. CHANGELOG.md itself keeps the English headings the format defines.
        'changelog_categories' => [
            'added' => 'افزوده‌شده',
            'changed' => 'تغییریافته',
            'deprecated' => 'منسوخ‌شده',
            'removed' => 'حذف‌شده',
            'fixed' => 'رفع‌شده',
            'security' => 'امنیت',
        ],

        /*
         * Item 18 — RUNTIME state, nested under `status` to keep it apart from the install
         * facts above. Both are legitimately "system", and a flat merge would put
         * `scheduler` next to `version` with nothing saying that one is a fact about this
         * release and the other changes every minute.
         *
         * Every string names a consequence or a remedy rather than a status word, because
         * "stopped" alone sends an administrator looking for a switch in the panel that does
         * not and should not exist.
         */
        'status' => [
            'scheduler' => 'زمان‌بند (cron)',
            'queue' => 'کارگر صف',
            'cache_store' => 'انبارهٔ کش',
            'running' => 'در حال اجرا',
            'stopped' => 'متوقف',
            'unknown' => 'نامعلوم',
            'last_seen' => 'آخرین گزارش :ago',
            'queue_lag' => 'تأخیر صف: :seconds ثانیه',
            'scheduler_stopped_help' => 'زمان‌بند لاراول اجرا نمی‌شود؛ پس انتشار زمان‌بندی‌شده هم انجام نمی‌شود. در ایمیج Docker خودکار اجرا می‌شود: بررسی کن CMS_RUN_SCHEDULER برابر false نباشد و لاگ کانتینر را ببین. بدون ایمیج، دستور cron را در docs/deployment.md ببین.',
            'queue_stopped_help' => 'هیچ کارگری صف را پردازش نمی‌کند؛ ترجمه، نمایه‌سازی جست‌وجو و وب‌هوک‌ها روی هم انبار می‌شوند. سرویس queue:work را راه بیندازید.',
            'queue_unknown_help' => 'تا وقتی زمان‌بند متوقف است چیزی به صف سپرده نمی‌شود، پس وضعیت صف سنجیدنی نیست. اول زمان‌بند را درست کن.',
            'cache_tags_ok' => 'برچسب‌گذاری پشتیبانی می‌شود؛ باطل‌سازی کش هدفمند است.',
            'cache_tags_missing' => 'این انباره برچسب ندارد، پس هر انتشار کل کش را پاک می‌کند (شمارنده‌های محدودیت نرخ هم با آن می‌رود). برای تولید از Redis استفاده کن.',
            // The web process and the cron process disagree about which cache they use. Each
            // writes where the other never reads, so an editor's publish clears a store the
            // API does not consult and the site serves stale pages indefinitely.
            'cache_store_mismatch' => 'ناسازگاری تنظیمات: زمان‌بند از انبارهٔ «:store» استفاده می‌کند و وب از انبارهٔ دیگری. باطل‌سازی کش به سایت نمی‌رسد. فایل env هر دو را یکسان کن.',
            'contact_protection' => 'محافظت فرم تماس',
            'contact_no_submissions' => 'هنوز پیامی نرسیده، پس کارکرد کادر پنهان سنجیدنی نیست.',
            'contact_honeypot_missing' => 'پیام‌های تازه بدون کادر پنهان می‌رسند؛ یعنی فرانت آن را نمی‌فرستد و این محافظت خاموش است. نام فیلد را با فرانت هم‌تراز کن.',
        ],
    ],

    'slide' => [
        // Requirement 7.6 — the first active slide's image is preloaded. This was the English word
        // `preload`, hardcoded in the slides table.
        'preloaded' => 'پیش‌بارگذاری در صفحهٔ اصلی',
    ],

    /*
    | Item 35 — someone else saved the record while this form was open.
    */
    'concurrency' => [
        'title' => 'این مورد در این فاصله ویرایش شده است',
        'body' => ':who آن را :when ذخیره کرده است. اگر الان ذخیره کنی، تغییرات او پاک می‌شود. صفحه را دوباره بارگذاری کن تا تغییرات را ببینی، یا اگر مطمئنی، روی آن ذخیره کن.',
        'reload' => 'بارگذاری دوباره',
        'overwrite' => 'ذخیره روی تغییرات او',
        // No audit row explains the change: an unaudited record, a quiet save or a background job.
        'background' => 'یک فرایند خودکار',
    ],

    'audit' => [
        'title' => 'گزارش فعالیت‌ها',
        'intro' => 'هر عملیات نوشتن در پنل به‌طور خودکار و بدون امکان غیرفعال‌سازی ثبت می‌شود. این گزارش فقط خواندنی است.',
        'when' => 'زمان',
        'who' => 'کاربر',
        'system' => 'سیستم',
        'event' => 'رویداد',
        'subject' => 'موضوع',
        'description' => 'شرح',
        'view_changes' => 'مشاهدهٔ تغییرات',
        'changes_heading' => 'تغییرات ثبت‌شده',
        'attribute' => 'فیلد',
        'before' => 'مقدار پیشین',
        'after' => 'مقدار جدید',
        'no_changes' => 'تغییری ثبت نشده است.',
        'denials' => 'دسترسی‌های رد‌شده',

        // Item 55 — the event filter and badge showed the raw event names in English. `restored`
        // and `destroyed` are new with the trash (items 10/11); without labels they appeared in the
        // log with no filter option to find them by.
        'context' => [
            'ip' => 'نشانی IP',
            'user_agent' => 'مرورگر',
            'reason' => 'علت',
            'via_cookie' => 'روش ورود',
        ],
        'via_cookie_yes' => 'از کوکی «مرا به خاطر بسپار»',
        'via_cookie_no' => 'با وارد کردن گذرواژه',
        'failure_reason' => [
            'wrong_password' => 'گذرواژهٔ نادرست',
            'account_disabled' => 'حساب غیرفعال (گذرواژه درست بود)',
            'wrong_second_factor' => 'کد تأیید دومرحله‌ای نادرست (گذرواژه درست بود)',
        ],
        'events' => [
            'created' => 'ایجاد',
            'updated' => 'ویرایش',
            'deleted' => 'انتقال به سطل زباله',
            'restored' => 'بازگردانی',
            'destroyed' => 'حذف همیشگی',
            'published' => 'انتشار',
            'archived' => 'بایگانی',
            'denied' => 'دسترسی ردشده',
            'login' => 'ورود',
            'login_failed' => 'ورود ناموفق',
        ],
    ],

    'version' => [
        'history' => 'تاریخچهٔ نسخه‌ها',
        'select' => 'انتخاب نسخه',
        'empty' => 'هنوز نسخه‌ای ثبت نشده است.',
        'restore_warning' => 'محتوای فعلی با نسخهٔ انتخابی جایگزین می‌شود. وضعیت فعلی هم به‌عنوان یک نسخهٔ جدید ذخیره می‌شود، پس این کار قابل بازگشت است.',
        'restored' => 'نسخهٔ :number بازگردانی شد.',
        'not_found' => 'نسخهٔ انتخابی پیدا نشد.',
        'keep_notice' => 'تنها :count نسخهٔ آخر نگه داشته می‌شود.',
        // cms.versions.keep <= 0 means no pruning at all (HasContentVersions::pruneVersions).
        'keep_all' => 'همهٔ نسخه‌ها نگه داشته می‌شوند.',
    ],

    'contact' => [
        'received' => 'پیام شما دریافت شد. سپاس از تماس شما.',

        // Why a submission was flagged (item 16). `manual` is set by an editor, the rest
        // by App\Services\Contact\SpamInspector.
        'spam_reason' => [
            'honeypot' => 'پر شدن کادر پنهان',
            'too_fast' => 'ارسال بی‌درنگ',
            'missing_timing' => 'نبود زمان نمایش فرم',
            'manual' => 'تشخیص سردبیر',
        ],
    ],

    // Item 15 — the form builder and the submission payload it produces.
    'forms' => [
        'form' => 'فرم',
        'submission' => 'پاسخ ارسال‌شده',
        'summary' => 'خلاصه',
        'section_details' => 'فرم',
        'section_fields' => 'فیلدها',
        'contact_locked' => 'این فرم تماس داخلی است. فیلدهای آن قرارداد ثابت POST /api/v1/contact هستند، پس اینجا فقط متن و ترتیب آن‌ها تغییر می‌کند و روشن و خاموش شدنش با ماژول تماس است. برای گرفتن فیلدهای دیگر، فرم تازه‌ای بسازید.',
        'key' => 'کلید',
        'key_help' => 'نامی که فرانت‌اند این فرم را با آن می‌گیرد، مثلاً contact. حروف کوچک انگلیسی، رقم و خط تیره؛ پس از استفاده در فرانت‌اند آن را تغییر ندهید.',
        'title' => 'عنوان',
        'is_active' => 'فعال',
        'is_active_help' => 'فرم غیرفعال از API ارائه نمی‌شود و پاسخی نمی‌پذیرد.',
        'add_field' => 'افزودن فیلد',
        'field_key' => 'کلید فیلد',
        'field_key_help' => 'حروف کوچک انگلیسی، رقم و زیرخط. پاسخ‌ها با این کلید ذخیره می‌شوند.',
        'key_reserved' => 'این نام را بررسی‌های هرزنامه به کار می‌برند و نمی‌تواند کلید فیلد باشد.',
        'field_type' => 'نوع',
        'max_length' => 'بیشینهٔ طول',
        'max_length_help' => 'برای مقدار پیش‌فرض (:default نویسه) خالی بگذارید؛ حداکثر :ceiling.',
        'required' => 'الزامی',
        'label' => 'برچسب',
        'placeholder' => 'متن راهنمای درون فیلد',
        'help' => 'توضیح',
        'options' => 'گزینه‌ها',
        'add_option' => 'افزودن گزینه',
        'option_value' => 'مقدار',
        'option_value_help' => 'همراه پاسخ ذخیره می‌شود؛ آن را ثابت نگه دارید.',
        'option_label' => 'برچسب (:locale)',
        'fields_count' => 'فیلدها',
        'submissions_count' => 'پاسخ‌ها',
        'updated_at' => 'آخرین به‌روزرسانی',
        'delete_blocked' => 'این فرم حذف‌شدنی نیست: یا فرم تماس داخلی است یا پاسخ دارد. به جای حذف، آن را غیرفعال کنید.',
        'value_yes' => 'بله',
        'value_no' => 'خیر',
        'type' => [
            'text' => 'متن',
            'email' => 'ایمیل',
            'tel' => 'شماره تلفن',
            'textarea' => 'متن بلند',
            'select' => 'فهرست کشویی',
            'checkbox' => 'چک‌باکس',
        ],
    ],

    'user' => [
        'password_help' => 'هنگام ویرایش، برای حفظ گذرواژهٔ فعلی این کادر را خالی بگذارید.',
        'role_locked' => 'تغییر نقش خود امکان‌پذیر نیست؛ فقط مدیر می‌تواند نقش دیگران را تعیین کند.',
        'role_guidance' => 'دسترسی هر نقش',
        // Human-readable labels for the abilities in App\Enums\UserRole. The role
        // guidance in the form is built from the enum matrix and mapped through
        // these keys, so a change to the matrix is reflected without touching prose.
        'ability' => [
            'content.view' => 'مشاهدهٔ محتوا',
            'content.create' => 'ایجاد محتوا',
            'content.update.own' => 'ویرایش محتوای خود',
            'content.update.any' => 'ویرایش محتوای دیگران',
            'content.delete' => 'حذف محتوا',
            'content.publish' => 'انتشار محتوا',
            'content.restore' => 'بازگردانی نسخه‌های محتوا',
            'media.view' => 'مشاهدهٔ رسانه',
            'media.upload' => 'بارگذاری رسانه',
            'media.update.own' => 'ویرایش رسانهٔ خود',
            'media.update.any' => 'ویرایش رسانهٔ دیگران',
            'media.delete' => 'حذف رسانه',
            'translation.view' => 'مشاهدهٔ ترجمه‌ها',
            'translation.review' => 'بازبینی و تأیید ترجمه‌ها',
            'redirect.manage' => 'مدیریت تغییر مسیرها',
            'menu.manage' => 'مدیریت منوها',
            'settings.manage' => 'مدیریت تنظیمات سایت',
            'contact.view' => 'مشاهدهٔ پیام‌های تماس',
            'user.manage' => 'مدیریت کاربران',
            'audit.view' => 'مشاهدهٔ گزارش فعالیت‌ها',
            'release.manage' => 'مدیریت انتشار نسخه',
        ],
    ],

    'translation_review' => [
        'title' => 'بازبینی ترجمه‌ها',
        'intro' => 'هر سطر یک زبان از یک مطلب است که نیازمند کار است. زبان مبنا (فارسی) در این فهرست نمی‌آید، چون مرجع است نه ترجمه.',
        'locale' => 'زبان',
        'source_text' => 'متن مبنا',
        'last_reviewed_by' => 'آخرین بازبین',
        'open' => 'ویرایش مطلب',
        'confirm' => 'با تأیید، این ترجمه بازبینی‌شده علامت می‌خورد و در نقشهٔ سایت آن زبان قرار می‌گیرد. اگر متن مبنا بعداً تغییر کند، به‌طور خودکار «نیازمند به‌روزرسانی» می‌شود.',
        'reviewed' => 'ترجمهٔ :locale بازبینی‌شده ثبت شد.',
        'nothing_to_review' => 'چیزی برای بازبینی وجود ندارد',
        'nothing_to_review_hint' => 'این زبان هنوز هیچ متنی ندارد. ابتدا ترجمه را وارد کنید.',
        'orphaned' => 'مطلب مرتبط با این ترجمه پیدا نشد.',
        'empty' => 'همهٔ ترجمه‌ها بازبینی شده‌اند',
        'empty_hint' => 'هیچ زبانی در انتظار ترجمه یا به‌روزرسانی نیست.',
    ],

    'editorial_calendar' => [
        'title' => 'تقویم انتشار',
        'intro' => 'خبرها، برگه‌ها و گالری‌ها بر اساس روز انتشار. ساعت‌ها به وقت :timezone است.',
        'previous' => 'ماه قبل',
        'next' => 'ماه بعد',
        'today' => 'ماه جاری',
        'state' => [
            'scheduled' => 'زمان‌بندی‌شده — خودکار منتشر می‌شود',
            'unapproved' => 'هنوز منتشر نشده — تا تأیید نشود منتشر نمی‌شود',
            'missed' => 'جا مانده — زمانش امروز گذشته و روی سایت نیست',
            'published' => 'منتشرشده',
        ],
        'more' => ':count مورد دیگر',
        'empty' => 'در این ماه چیزی زمان‌بندی یا منتشر نشده است.',
        'unavailable' => 'تقویم تنظیم‌شده برای این زبان را نمی‌توان به شکل جدول ماهانه نمایش داد.',
    ],

    'settings' => [
        'title' => 'تنظیمات',
        'save' => 'ذخیرهٔ تنظیمات',
        'saved' => 'تنظیمات ذخیره شد.',
        'validation_failed' => 'تنظیمات ذخیره نشد',
        'validation_failed_body' => 'چند فیلد نامعتبر است و هیچ تغییری ذخیره نشد. این تب‌ها را بررسی کنید: :tabs',

        'general' => [
            'tab' => 'عمومی',
            'identity' => 'هویت سایت',
            'identity_help' => 'نام سایت هم در پاسخ API به فرانت‌اند می‌رود، هم در داده‌های ساخت‌یافتهٔ Organization استفاده می‌شود، و هم در سرصفحهٔ همین پنل نمایش داده می‌شود.',
            'site_name' => 'نام سایت (:locale)',
            'social' => 'شبکه‌های اجتماعی',
            'social_help' => 'نشانی کامل صفحه‌ها با https. این‌ها به فرانت‌اند داده می‌شوند و در ویژگی sameAs داده‌های ساخت‌یافته هم می‌آیند.',
            'social_links' => 'نشانی صفحه‌ها',
            'social_add' => 'افزودن نشانی',
        ],

        'organisation' => [
            'section' => 'هویت سازمان (داده‌های ساخت‌یافته)',
            'section_help' => 'این‌ها ناشرِ سایت را به موتور جستجو معرفی می‌کنند و در JSON-LD هر مطلب به‌عنوان publisher می‌آیند. همه اختیاری‌اند و هرکدام خالی بماند اصلاً منتشر نمی‌شود.',
            'type' => 'نوع سازمان',
            'type_help' => 'خبرگزاری، شرکت، ارگان دولتی و دانشگاه برای موتور جستجو چهار موجودیت متفاوت‌اند. اگر مطمئن نیستی «سازمان (عمومی)» را بگذار.',
            'logo' => 'لوگوی سازمان',
            'logo_help' => 'مهم‌ترین فیلد این بخش. گوگل لوگوی ناشر را برای نتایج غنی مطالب و پنل دانش استفاده می‌کند.',
            'legal_name' => 'نام حقوقی (ثبتی)',
            'legal_name_help' => 'اگر با نام تجاری سایت فرق دارد.',
            'founding_date' => 'تاریخ تأسیس',
            'founding_date_help' => 'به شکل میلادی YYYY-MM-DD، چون schema.org تاریخ ISO می‌خواهد.',
            'alternate_name' => 'نام دیگر (:locale)',
            'description' => 'معرفی سازمان (:locale)',
        ],
        'discovery' => [
            'tab' => 'آمار و تأیید مالکیت',
            'analytics' => 'آمار بازدید',
            'analytics_help' => 'این شناسه‌ها فقط ذخیره می‌شوند و به فرانت‌اند داده می‌شوند تا آن‌ها را رندر کند. در همین پنل هیچ‌گاه بارگذاری نمی‌شوند — بک‌آفیس عمداً هیچ درخواستی به سرویس بیرونی نمی‌فرستد.',
            'ga' => 'شناسهٔ Google Analytics',
            'gtm' => 'شناسهٔ Google Tag Manager',
            'verification' => 'تأیید مالکیت سایت',
            'verification_help' => 'توکن تأیید مالکیت که فرانت‌اند در تگ meta می‌گذارد. مربوط به سایت عمومی است، نه این میزبان.',
            'gsc' => 'توکن Google Search Console',
            'bing' => 'توکن Bing Webmaster',
        ],

        'contact' => [
            'tab' => 'تماس',
            'details' => 'اطلاعات تماس',
            'details_help' => 'این مقادیر از طریق API در اختیار فرانت‌اند قرار می‌گیرند تا در صفحهٔ تماس نمایش داده شوند.',
            'phone' => 'تلفن',
            'email' => 'ایمیل',
            'address' => 'نشانی (:locale)',
            'office_hours' => 'ساعات کاری (:locale)',
            'map' => 'موقعیت روی نقشه',
            'map_help' => 'هر دو مقدار را با هم پر کنید یا هر دو را خالی بگذارید. با داشتن هر دو، داده‌های ساخت‌یافتهٔ LocalBusiness تولید می‌شود.',
            'latitude' => 'عرض جغرافیایی',
            'longitude' => 'طول جغرافیایی',
        ],

        'maintenance' => [
            'tab' => 'حالت تعمیر',
            'section' => 'حالت تعمیر',
            'section_help' => 'این کلید فقط روی Delivery API اثر دارد و همین پنل باز می‌ماند؛ با «php artisan down» که کل برنامه را می‌خواباند تفاوت دارد.',
            'enabled' => 'فعال‌سازی حالت تعمیر',
            'enabled_help' => 'با فعال بودن، Delivery API به همهٔ درخواست‌ها پاسخ ۵۰۳ با هدر Retry-After می‌دهد، پس سایت عمومی از کار می‌افتد. کد ۵۰۳ به خزنده می‌گوید بعداً برگردد، نه اینکه صفحه را حذف کند.',
            'active_title' => 'سایت در حالت تعمیر است',
            'active_body' => 'تا زمانی که این کلید خاموش نشود، Delivery API به فرانت‌اند پاسخ ۵۰۳ می‌دهد و سایت عمومی در دسترس نیست.',
        ],

        'ai' => [
            'tab' => 'ترجمهٔ خودکار',
            'section' => 'ترجمهٔ خودکار (هوش مصنوعی)',
            'section_help' => 'ترجمهٔ ماشینی فیلدهای متنی از زبان مبنا (فارسی) به زبان‌های دیگر. نتیجه پیش از انتشار باید توسط انسان بازبینی شود.',
            'enabled' => 'فعال‌سازی ترجمهٔ خودکار',
            'enabled_help' => 'با غیرفعال بودن، دکمهٔ «ترجمه با هوش مصنوعی» در گردش‌کار ترجمه نمایش داده نمی‌شود.',
            'provider' => 'سرویس ترجمه',
            'provider_help' => 'هر سه سرویس با پروتکل یکسان (OpenAI) کار می‌کنند. کلید و مدل هر سرویس جداگانه ذخیره می‌شود، پس جابه‌جایی بین آن‌ها نیازی به وارد کردن دوبارهٔ کلید ندارد.',
            'model' => 'مدل',
            'model_help' => 'خالی بگذارید تا مدل پیش‌فرض :provider یعنی :model استفاده شود. توجه: شناسهٔ مدل بین سرویس‌ها یکسان نیست — شناسه را از پنل همان سرویس بردارید.',
            'api_key' => 'کلید API :provider',
            'api_key_help' => 'این کلید در پایگاه‌داده ذخیره می‌شود، نه در فایل env. برای فعال‌سازی ترجمهٔ خودکار الزامی است.',
            'api_key_help_set' => 'یک کلید ذخیره شده است. برای جایگزینی، کلید تازه‌ای وارد کنید؛ خالی گذاشتن یعنی بدون تغییر.',
            'api_key_docs' => 'دریافت کلید: :url',
            'api_key_clear' => 'حذف کلید ذخیره‌شده',
            'api_key_cleared' => 'کلید ذخیره‌شده حذف شد.',
        ],
    ],

    /*
     * The translation providers (App\Enums\AiProvider). The descriptions are what
     * make the choice meaningful to an administrator, so each one says what the
     * service is rather than just naming it.
     */
    'organisation_type' => [
        'Organization' => 'سازمان (عمومی)',
        'NewsMediaOrganization' => 'خبرگزاری / رسانهٔ خبری',
        'Corporation' => 'شرکت',
        'GovernmentOrganization' => 'ارگان دولتی',
        'EducationalOrganization' => 'مؤسسهٔ آموزشی',
        'NGO' => 'سازمان مردم‌نهاد',
        'LocalBusiness' => 'کسب‌وکار محلی',
    ],

    /*
     * App\Support\SocialPlatform — which network a social link on the Settings page
     * points at. Brand names stay as each brand writes itself in this language.
     */
    'social_platform' => [
        'instagram' => 'اینستاگرام',
        'telegram' => 'تلگرام',
        'x' => 'ایکس',
        'linkedin' => 'لینکدین',
        'youtube' => 'یوتیوب',
        'facebook' => 'فیس‌بوک',
        'whatsapp' => 'واتساپ',
        'github' => 'گیت‌هاب',
        'aparat' => 'آپارات',
        'eitaa' => 'ایتا',
        'bale' => 'بله',
        'rubika' => 'روبیکا',
        'website' => 'وب‌سایت',
    ],

    'ai_provider' => [
        'openrouter' => 'اوپن‌روتر (OpenRouter)',
        'openrouter_help' => 'دروازهٔ بین‌المللی با گسترده‌ترین فهرست مدل‌ها. پرداخت ارزی و دسترسی از ایران معمولاً نیازمند تنظیمات شبکه است.',
        'gapgpt' => 'گپ‌جی‌پی‌تی (GapGPT)',
        'gapgpt_help' => 'سرویس واسط ایرانی و سازگار با OpenAI. کلید و فهرست مدل‌ها را از پنل خودِ گپ‌جی‌پی‌تی بگیرید.',
        'chatqt' => 'چت‌کیوتی (ChatQT)',
        'chatqt_help' => 'سرویس واسط ایرانی و سازگار با OpenAI، با کیف پول ریالی و کنسول توسعه‌دهنده. به گفتهٔ مستنداتش برای دسترسی از ایران بدون VPN طراحی شده است.',
    ],

    'ai_translation' => [
        'confirm' => 'کل این مطلب، شامل بدنهٔ متن غنی، از فارسی به این زبان ترجمه و در وضعیت «ترجمهٔ ماشینی» ذخیره می‌شود. ساختار متن حفظ می‌شود، اما نتیجه پیش از انتشار نیاز به بازبینی انسانی دارد.',
        'confirm_outdated' => 'این زبان پیش‌تر بازبینی شده بود و سپس متن فارسی تغییر کرد. با ترجمهٔ دوباره، متن موجود بازنویسی می‌شود، تأیید بازبین پیشین از بین می‌رود و این زبان تا بازبینی انسانی دوباره از نقشهٔ سایت خود حذف می‌شود.',
        'success' => 'ترجمهٔ خودکار به زبان :locale انجام شد و در انتظار بازبینی است.',
        'queued' => 'ترجمهٔ خودکار به زبان :locale در صف اجرا قرار گرفت.',
        'queued_hint' => 'ترجمه در پس‌زمینه انجام می‌شود و ممکن است چند دقیقه طول بکشد. نتیجه — موفق یا ناموفق — در زنگ اعلان‌های پنل به شما اطلاع داده می‌شود.',
        'skipped' => 'ترجمهٔ خودکار نادیده گرفته شد',
        'failed' => 'ترجمهٔ خودکار انجام نشد',
        'error' => [
            'disabled' => 'ترجمهٔ خودکار فعال نیست. آن را از صفحهٔ تنظیمات فعال کنید.',
            'missing_key' => 'برای سرویس ترجمهٔ انتخاب‌شده کلید API تنظیم نشده است. آن را در صفحهٔ تنظیمات وارد کنید.',
            'request_failed' => 'ارتباط با سرویس ترجمه ناموفق بود. کمی بعد دوباره تلاش کنید.',
            'empty_source' => 'متن مبنایی برای ترجمه وجود ندارد. ابتدا محتوای زبان فارسی را کامل کنید.',
            'already_reviewed' => 'این زبان قبلاً بازبینی شده است؛ برای جلوگیری از بازنویسی کار انسانی، ترجمهٔ خودکار انجام نشد.',
            'source_too_long' => 'متن این مطلب برای ترجمه در یک نوبت بیش از حد بلند است. آن را به چند مطلب کوتاهتر بشکنید یا سقف درخواستها را در تنظیمات افزایش دهید.',
        ],
    ],
];
