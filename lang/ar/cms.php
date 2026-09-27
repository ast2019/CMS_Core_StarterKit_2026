<?php

declare(strict_types=1);

/*
 * Arabic (ar) — structural from day one, no content entered at launch
 * (blueprint §2). Panel strings are translated so activating the locale later is
 * a content task rather than a development task.
 */

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
        'slug_changed_title' => 'تغيّر رابط هذا المحتوى',
        'slug_changed_body' => 'لم يتغيّر الرابط في أي لغة.|تغيّر الرابط في لغة واحدة. أنشئ إعادة توجيه 301 لتجنّب أخطاء 404.|تغيّر الرابط في لغتين. أنشئ إعادة توجيه 301 لتجنّب أخطاء 404.|تغيّر الرابط في :count لغات. أنشئ إعادة توجيه 301 لتجنّب أخطاء 404.|تغيّر الرابط في :count لغةً. أنشئ إعادة توجيه 301 لتجنّب أخطاء 404.|تغيّر الرابط في :count لغة. أنشئ إعادة توجيه 301 لتجنّب أخطاء 404.',
        'create_action' => 'إنشاء إعادة توجيه 301',
        'created' => 'لم تُنشأ أي إعادة توجيه.|تم إنشاء إعادة توجيه واحدة.|تم إنشاء إعادتَي توجيه.|تم إنشاء :count إعادات توجيه.|تم إنشاء :count إعادةَ توجيه.|تم إنشاء :count إعادة توجيه.',
    ],

    /*
     * نوع البيانات المنظمة للمقال (App\Enums\ArticleSchemaType). اسم النوع يُعلن
     * حرفيًا في JSON-LD فلا يُترجم؛ الشرح وحده هو المُترجم.
     */
    'schema_type' => [
        'Article' => 'مقال عام (Article)',
        'Article_help' => 'لمحتوى لا يكون تاريخ نشره حاسمًا: الأدلة والشروح والمقالات الدائمة.',
        'NewsArticle' => 'خبر (NewsArticle)',
        'NewsArticle_help' => 'لخبر اليوم. يدّعي الحداثة، فهو خيار خاطئ للمحتوى الدائم.',
        'BlogPosting' => 'تدوينة (BlogPosting)',
        'BlogPosting_help' => 'لتدوينة شخصية أو مدوّنة بصوت الكاتب.',
    ],

    'media_role' => [
        'featured' => 'الصورة البارزة',
        'inline' => 'داخل النص',
        'gallery' => 'معرض',
        'og_image' => 'صورة المشاركة',
    ],

    'locale' => [
        'tabs' => 'اللغات',
        'source_badge' => 'اللغة الأصلية',
    ],

    'nav' => [
        'content' => 'المحتوى',
        'taxonomy' => 'التصنيفات',
        'media' => 'الوسائط',
        'appearance' => 'المظهر',
        'system' => 'النظام',
    ],

    'resource' => [
        'content' => 'خبر',
        'contents' => 'الأخبار',
        'category' => 'تصنيف',
        'categories' => 'التصنيفات',
        'tag' => 'وسم',
        'tags' => 'الوسوم',
        'gallery' => 'معرض',
        'galleries' => 'المعارض',
        'page' => 'صفحة',
        'pages' => 'الصفحات',
        'slide' => 'شريحة',
        'slides' => 'عرض الشرائح',
        'media_asset' => 'ملف وسائط',
        'media_assets' => 'مكتبة الوسائط',
        'menu_item' => 'عنصر قائمة',
        'menu_items' => 'القوائم',
        'redirect' => 'إعادة توجيه',
        'redirects' => 'إعادة التوجيه',
        'contact_submission' => 'رسالة تواصل',
        'contact_submissions' => 'رسائل التواصل',
        'user' => 'مستخدم',
        'users' => 'المستخدمون',
    ],

    'section' => [
        'seo' => 'تحسين محركات البحث',
        'publishing' => 'النشر',
        'featured_image' => 'الصورة البارزة',
        'media' => 'الملف',
        'link' => 'الرابط',
        'appearance' => 'المظهر',
        'identity' => 'هوية الموقع',
        'analytics' => 'التحليلات والتحقق',
        'social_card' => 'بطاقة المشاركة (اختياري)',
        'social_card_help' => 'إذا تُركت فارغة، يُستخدم عنوان ووصف الميتا في الشبكات الاجتماعية.',
        'diagnostics' => 'معلومات تشخيصية',
    ],

    'field' => [
        'two_factor' => 'التحقّق بخطوتين',
        'title' => 'العنوان',
        'name' => 'الاسم',
        'slug' => 'المعرّف (slug)',
        'slug_help' => 'يُولّد من العنوان إذا تُرك فارغًا. تغييره في محتوى منشور يغيّر الرابط العام.',
        'excerpt' => 'المقتطف',
        'description' => 'الوصف',
        'body' => 'النص',
        'blocks' => 'المحتوى',
        'answer_paragraph' => 'إجابة موجزة (GEO)',
        'answer_paragraph_help' => 'فقرة مستقلة تجيب على السؤال الرئيسي دون الحاجة لبقية النص.',
        'meta_title' => 'عنوان الميتا',
        'meta_title_help' => 'يعود إلى العنوان إذا تُرك فارغًا. يُنصح بنحو 60 حرفًا.',
        'meta_description' => 'وصف الميتا',
        'meta_description_help' => 'يعود إلى المقتطف إذا تُرك فارغًا. يُنصح بنحو 155 حرفًا.',
        'robots_meta' => 'توجيه الروبوتات',
        'robots_meta_help' => 'المسودات والترجمات غير المراجَعة تُضبط تلقائيًا على noindex.',
        'focus_keyphrase' => 'العبارة المفتاحية المستهدفة',
        'focus_keyphrase_help' => 'العبارة التي تريد أن يُعثر على هذا المحتوى بها في هذه اللغة. تُحدَّد لكل لغة وليست ترجمة للعبارة الفارسية.',
        'og_title' => 'عنوان بطاقة المشاركة',
        'og_title_help' => 'إذا تُرك فارغًا يُستخدم عنوان الميتا.',
        'og_description' => 'وصف بطاقة المشاركة',
        'og_description_help' => 'إذا تُرك فارغًا يُستخدم وصف الميتا.',
        'schema_type' => 'نوع البيانات المنظمة',
        'schema_type_help' => 'يُعلن حرفيًا في JSON-LD للصفحة، وهو نفسه في كل اللغات.',
        'status' => 'الحالة',
        'publish_date' => 'تاريخ النشر',
        'publish_date_help' => 'التاريخ المستقبلي يعني نشرًا مُجدولًا؛ يبقى المحتوى مخفيًا حتى ذلك الحين.',
        'primary_category' => 'التصنيف الرئيسي',
        'primary_category_help' => 'يحدّد الرابط الأساسي ومسار التنقل.',
        'categories' => 'التصنيفات',
        'tags' => 'الوسوم',
        'author' => 'الكاتب',
        'featured_image' => 'الصورة البارزة',
        'featured_image_help' => 'اختر من مكتبة الوسائط. الصورة البارزة مطلوبة.',
        'gallery_items_help' => 'الترتيب الذي تختاره هو ترتيب ظهور الصور في المعرض.',
        'translation_status' => 'حالة الترجمة',
        'parent' => 'الأصل',
        'parent_help' => 'اتركه فارغًا ليبقى هذا العنصر في المستوى الأول. أقصى عمق للقائمة :depth مستويات.',
        'position' => 'الترتيب',
        'is_active' => 'نشط',
        'link' => 'الرابط',
        'menu_key' => 'القائمة',
        'menu_key_help' => 'تُعرَّف مواضع القوائم في إعدادات الموقع. هذه القائمة هي ما يمكن للواجهة عرضه فعلاً.',
        'target' => 'الهدف',
        'target_help' => 'اكتب بعض أحرف العنوان للبحث. يُبنى الرابط في كل لغة من معرّف الهدف في تلك اللغة.',
        'opens_in_new_tab' => 'الفتح في تبويب جديد',
        'alt_text' => 'النص البديل',
        'alt_text_help' => 'مطلوب لإمكانية الوصول وتحسين ظهور الصور.',
        'caption' => 'التسمية التوضيحية',
        'type' => 'النوع',
        'file' => 'الملف',
        'duration_seconds' => 'المدة (ثانية)',
        'external_embed_url' => 'رابط التضمين الخارجي',
        'video_thumbnail' => 'الصورة المصغّرة للفيديو',
        'video_thumbnail_help' => 'مطلوبة لخريطة موقع الفيديو. تُرفع يدويًا عند عدم توفّر ffprobe.',
        'from_path' => 'من المسار',
        'to_path' => 'إلى المسار',
        'redirect_type' => 'نوع إعادة التوجيه',
        'hits' => 'عدد الزيارات',
        'subtitle' => 'العنوان الفرعي',
        'cta_label' => 'نص الزر',
        'image_dimensions' => 'أبعاد الصورة',
        'image_dimensions_help' => 'مطلوبة لمنع إزاحة التصميم (CLS).',
        'read_at' => 'وقت القراءة',
        'spam_reason' => 'سبب الاعتبار مزعجة',
        'preview' => 'معاينة',
        'read_status' => 'حالة القراءة',
        'user_agent' => 'متصفح المُرسل',
        'message' => 'الرسالة',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'subject' => 'الموضوع',
        'page_role' => 'دور الصفحة',
        'page_role_help' => 'يحدد الدور صفحةً يعرفها النظام بالاسم لا بالاسم اللطيف. تُقدَّم الصفحة الرئيسية على /fa (جذر اللغة) وليس على /fa/slug. صفحة واحدة فقط يمكن أن تكون الرئيسية.',
        'system_key' => 'المفتاح النظامي',
        'password' => 'كلمة المرور',
        'role' => 'الدور',
    ],

    'dashboard' => [
        'title' => 'لوحة التحكم',
        'today' => 'اليوم، :date',

        'live' => 'منشور',
        'live_this_month' => ':count هذا الشهر',
        'in_progress' => 'قيد الإعداد',
        'in_progress_breakdown' => 'مسودات: :drafts — قيد المراجعة: :review',
        'scheduled' => 'مُجدول',
        'next_publish' => 'التالي: :date',
        'nothing_scheduled' => 'لا شيء مُجدول',
        // Item 18 — replaces the promised publish date when cron is not running.
        'scheduler_stopped' => 'المُجدوِل متوقف — لن يُنشر هذا.',
        // Raised for records whose time has ALREADY passed while cron was down: they are
        // live by the database's reckoning but the Delivery cache was never refreshed, so
        // they are probably not on the public site.
        'scheduler_missed' => 'المُجدوِل متوقف، ولم يحن وقت نشر أي عنصر بعد.|المُجدوِل متوقف وقد حان وقت نشر عنصر واحد — أغلب الظن أنه ليس على الموقع.|المُجدوِل متوقف وقد حان وقت نشر عنصرين — أغلب الظن أنهما ليسا على الموقع.|المُجدوِل متوقف وقد حان وقت نشر :count عناصر — أغلب الظن أنها ليست على الموقع.|المُجدوِل متوقف وقد حان وقت نشر :count عنصرًا — أغلب الظن أنها ليست على الموقع.|المُجدوِل متوقف وقد حان وقت نشر :count عنصر — أغلب الظن أنها ليست على الموقع.',
        'unread_messages' => 'رسائل غير مقروءة',
        'inbox_clear' => 'تمت قراءة جميع الرسائل',

        'publishing_activity' => 'نشاط النشر',
        'publishing_activity_description' => 'عدد المواد المنشورة شهريًا',
        'published_count' => 'منشور',

        'translation_progress' => 'حالة الترجمة',
        'reviewed_ratio' => ':reviewed من :total مُراجَع',
        'no_translation_records' => 'لا توجد سجلات لهذه اللغة بعد',

        'recent_activity' => 'أحدث الأحداث',
    ],

    /*
     * Date picker chrome. Month and weekday names come from ICU, not from here.
     */

    'date' => [
        'today' => 'اليوم',
        'clear' => 'مسح',
        'previous_month' => 'الشهر السابق',
        'next_month' => 'الشهر التالي',
    ],

    'category' => [
        'cannot_detach_primary' => 'هذا هو التصنيف الرئيسي للمقال، وهو ما يحدّد رابطه المعياري ومسار التنقّل. غيّر التصنيف الرئيسي في المقال أولًا ثم افصله من هنا.',
        'primary_skipped' => 'لم يُستثنَ أي مقال.|لم يُفصل مقال واحد لأن هذا تصنيفه الرئيسي.|لم يُفصل مقالان لأن هذا تصنيفهما الرئيسي.|لم تُفصل :count مقالات لأن هذا تصنيفها الرئيسي.|لم يُفصل :count مقالًا لأن هذا تصنيفها الرئيسي.|لم يُفصل :count مقال لأن هذا تصنيفها الرئيسي.',
    ],
    'table' => [
        'primary' => 'رئيسي',
        'scheduled' => 'مُجدول',
        'unread' => 'غير مقروء',
        'items' => 'عنصر',
        'no_alt_text' => 'بدون نص بديل',
    ],

    'filter' => [
        'needs_translation' => 'بحاجة إلى ترجمة',
        'scheduled' => 'مُجدول',
        'unread' => 'غير مقروء',
        'missing_alt_text' => 'بدون نص بديل',
        'active' => 'نشط',
        'spam' => 'رسائل مزعجة',
        'spam_all' => 'كل الرسائل',
        'spam_only' => 'المزعجة فقط',
        'spam_excluded' => 'بدون المزعجة',
    ],

    'action' => [
        'detach_selected' => 'فصل المحدد عن هذا التصنيف',
        'detach_selected_done' => 'لم يُفصل أي مقال عن هذا التصنيف.|تم فصل مقال واحد عن هذا التصنيف.|تم فصل مقالين عن هذا التصنيف.|تم فصل :count مقالات عن هذا التصنيف.|تم فصل :count مقالًا عن هذا التصنيف.|تم فصل :count مقال عن هذا التصنيف.',
        'publish_selected' => 'نشر المحدد',
        'publish_selected_confirm' => 'سيُنشَر ما حدّدته. وما لا يحمل تاريخ نشر يأخذ الوقت الحالي، أما التاريخ المستقبلي المحدَّد مسبقًا فيبقى كما هو.',
        'publish_selected_done' => 'لم يُنشر أي عنصر.|تم نشر عنصر واحد.|تم نشر عنصرين.|تم نشر :count عناصر.|تم نشر :count عنصرًا.|تم نشر :count عنصر.',
        'unpublish_selected' => 'إلغاء نشر المحدد',
        'unpublish_selected_confirm' => 'سيعود ما حدّدته إلى مسودة ويخرج من الموقع العام.',
        'unpublish_selected_done' => 'لم يُلغَ نشر أي عنصر.|تم إلغاء نشر عنصر واحد.|تم إلغاء نشر عنصرين.|تم إلغاء نشر :count عناصر.|تم إلغاء نشر :count عنصرًا.|تم إلغاء نشر :count عنصر.',
        'bulk_skipped' => 'لم يُستبعد أي عنصر لعدم وجود صلاحية.|لم يتغيّر عنصر واحد لعدم وجود صلاحية.|لم يتغيّر عنصران لعدم وجود صلاحية.|لم تتغيّر :count عناصر لعدم وجود صلاحية.|لم يتغيّر :count عنصرًا لعدم وجود صلاحية.|لم يتغيّر :count عنصر لعدم وجود صلاحية.',
        // Already in the requested status. Distinct from bulk_skipped: nothing was refused,
        // there was simply nothing to do — and reporting it as a refusal would send an
        // editor looking for a permission problem that does not exist.
        'bulk_unchanged' => 'لم يكن أي عنصر بهذه الحالة أصلًا.|عنصر واحد كان بهذه الحالة أصلًا.|عنصران كانا بهذه الحالة أصلًا.|:count عناصر كانت بهذه الحالة أصلًا.|:count عنصرًا كانت بهذه الحالة أصلًا.|:count عنصر كانت بهذه الحالة أصلًا.',
        'mark_read_selected' => 'تحديد كمقروء',
        'mark_read_selected_done' => 'لم تُحدَّد أي رسالة كمقروءة.|تم تحديد رسالة واحدة كمقروءة.|تم تحديد رسالتين كمقروءتين.|تم تحديد :count رسائل كمقروءة.|تم تحديد :count رسالةً كمقروءة.|تم تحديد :count رسالة كمقروءة.',
        'mark_spam' => 'نقل إلى المزعجة',
        // Item 11 — the bulk delete reports a COUNT because it may have kept some of the
        // selection back; "deleted" with no number would hide that.
        'delete_selected_done' => 'لم يُنقل أي عنصر إلى المهملات.|تم نقل عنصر واحد إلى المهملات.|تم نقل عنصرين إلى المهملات.|تم نقل :count عناصر إلى المهملات.|تم نقل :count عنصرًا إلى المهملات.|تم نقل :count عنصر إلى المهملات.',
        'delete_selected_blocked' => 'لم يُحذف جزء من التحديد',
        'mark_not_spam' => 'ليست مزعجة',
        'reset_two_factor' => 'إعادة تعيين التحقّق بخطوتين',
        'reset_two_factor_confirm' => 'سيُحذف مفتاح المستخدم ورموز الاسترداد، وسيُعيد الإعداد عند تسجيل الدخول التالي. هذا ما يحتاجه من فقد هاتفه.',
        'reset_two_factor_done' => 'أُعيد تعيين التحقّق بخطوتين للمستخدم :name.',
        'activate_selected' => 'تنشيط المستخدمين المحدَّدين',
        'activate_selected_done' => 'لم يُنشَّط أي مستخدم.|تم تنشيط مستخدم واحد.|تم تنشيط مستخدمَين.|تم تنشيط :count مستخدمين.|تم تنشيط :count مستخدمًا.|تم تنشيط :count مستخدم.',
        'deactivate_selected' => 'تعطيل المستخدمين المحدَّدين',
        'deactivate_selected_done' => 'لم يُعطَّل أي مستخدم.|تم تعطيل مستخدم واحد.|تم تعطيل مستخدمَين.|تم تعطيل :count مستخدمين.|تم تعطيل :count مستخدمًا.|تم تعطيل :count مستخدم.',
        'own_account_skipped' => 'لم يتغيّر حسابك؛ تعطيل نفسك يُخرجك من هذه الشاشة.',
        'edit' => 'تعديل',
        'preview' => 'معاينة',
        'publish' => 'نشر',
        'archive' => 'أرشفة',
        'mark_read' => 'تعليم كمقروء',
        'review_translation' => 'اعتماد الترجمة',
        'restore_version' => 'استعادة هذه النسخة',
        'translate_ai' => 'ترجمة بالذكاء الاصطناعي',
    ],

    'blocks' => [
        'callout' => [
            'label' => 'مربع تنبيه',
            'description' => 'مربع بارز لملاحظة أو تحذير أو تفصيل إضافي.',
            'tone' => 'النوع',
            'tone_info' => 'معلومة',
            'tone_success' => 'نجاح',
            'tone_warning' => 'تحذير',
            'tone_danger' => 'خطر',
            'title' => 'عنوان المربع',
            'body' => 'نص المربع',
        ],
        'hero' => [
            'label' => 'بانر',
            'description' => 'بانر بعرض كامل مع عنوان ونص وزر.',
            'heading' => 'العنوان',
            'lead' => 'النص التمهيدي',
            'image' => 'الصورة',
            'image_help' => 'تُختار من مكتبة الوسائط للحفاظ على النص البديل والتخزين المحلي.',
            'cta_label' => 'نص الزر',
            'cta_url' => 'رابط الزر',
        ],
        'quote' => [
            'label' => 'اقتباس',
            'description' => 'اقتباس بارز مع نسبته إلى قائله.',
            'quote' => 'نص الاقتباس',
            'attribution' => 'القائل',
            'attribution_role' => 'صفة القائل',
        ],
        'gallery_embed' => [
            'label' => 'معرض',
            'description' => 'تضمين معرض موجود بالإشارة إليه، فتظهر التعديلات اللاحقة في كل مكان.',
            'gallery' => 'المعرض',
            'layout' => 'التصميم',
            'layout_grid' => 'شبكي',
            'layout_carousel' => 'شرائح',
            'layout_masonry' => 'متداخل',
            'max_items' => 'أقصى عدد للصور',
            'max_items_help' => 'اتركه فارغًا لعرض كل الصور.',
            'missing' => 'تم حذف المعرض المُشار إليه.',
            'empty' => 'لا توجد صور في هذا المعرض.',
        ],
    ],

    'page' => [
        'homepage' => 'الصفحة الرئيسية',
        'role_none' => 'صفحة عادية',
        'system_role' => 'صفحة نظامية (:key)',
    ],

    'menu' => [
        /*
         * Labels for the menu locations declared in `cms.menus.locations`.
         * A location with no entry here falls back to its own key, so a client site
         * can add one to config and ship without editing three lang files.
         */
        'location' => [
            'header' => 'قائمة الرأس',
            'footer' => 'قائمة التذييل',
            'sidebar' => 'القائمة الجانبية',
        ],
        'target_not_live' => 'غير منشور',
    ],

    'media' => [
        'inline_upload' => 'رفع صورة جديدة',
        'inline_upload_heading' => 'رفع صورة إلى مكتبة الوسائط',
        'inline_upload_description' => 'تُضاف الصورة إلى مكتبة الوسائط وتُختار هنا، دون مغادرة هذه الصفحة.',
        'inline_upload_submit' => 'رفع واختيار',

        // Item 55 — these were rendered as the raw English keys (`image`, `video`, `document`)
        // in a panel that is otherwise Persian, Arabic or English throughout.
        'type' => [
            'image' => 'صورة',
            'video' => 'فيديو',
            'document' => 'مستند',
        ],
        'size' => 'الحجم',
        'size_kb' => ':size كيلوبايت',
        'size_mb' => ':size ميغابايت',
    ],

    'seo' => [
        'warnings' => 'فحص تحسين محركات البحث',
        'no_warnings' => 'لا توجد تحذيرات.',
        'character_count' => ':count من :limit حرفًا',

        'preview' => [
            'label' => 'معاينة نتيجة البحث',
            'no_url' => 'لا يوجد مُعرِّف (slug) في هذه اللغة، لذا لن يظهر في نتائج هذه اللغة.',
            'empty_title' => '(لا يوجد عنوان لعرضه)',
            'empty_description' => '(لا يوجد وصف لعرضه)',
            'cut_hint' => 'هذا الجزء لا يظهر في نتائج البحث.',
            'noindex' => 'في هذه اللغة يستبعد التوجيه «:robots» هذا المحتوى من الفهرس. كونه مسوَّدة أو ترجمة غير مراجَعة يؤدي إلى الأثر نفسه.',
        ],

        'analysis' => [
            'label' => 'فحص العبارة المفتاحية',
            'no_keyphrase' => 'أدخل العبارة المفتاحية لتشغيل فحوصها.',
            'caveat' => 'تطابق هذه الفحوص العبارة حرفيًا: لا تُحلَّل جذور الكلمات ولا صيغ الجمع ولا المترادفات في الفارسية والعربية، ولا تُحسب درجة قابلية القراءة. الفحوص التي تقرأ النص نفسه تُحدَّث بعد الحفظ.',
            'band' => [
                'good' => 'الحالة جيدة',
                'fair' => 'قابل للتحسين',
                'poor' => 'يحتاج إلى مراجعة',
            ],
            'check' => [
                'keyphrase_in_title' => [
                    'pass' => 'العبارة المفتاحية موجودة في عنوان الميتا.',
                    'warn' => 'العبارة المفتاحية غير موجودة في عنوان الميتا، وهو أهم موضع لها.',
                ],
                'keyphrase_in_description' => [
                    'pass' => 'العبارة المفتاحية موجودة في وصف الميتا.',
                    'warn' => 'العبارة المفتاحية غير موجودة في وصف الميتا. هذا لا يؤثر في الترتيب، لكنه يُبرزها في المقتطف ويرفع نسبة النقر.',
                ],
                'keyphrase_in_slug' => [
                    'pass' => 'العبارة المفتاحية موجودة في المُعرِّف (slug).',
                    'warn' => 'العبارة المفتاحية غير موجودة في المُعرِّف (slug).',
                ],
                'keyphrase_in_opening' => [
                    'pass' => 'العبارة المفتاحية تظهر في بداية النص (أول :opening_words كلمة تقريبًا).',
                    'warn' => 'العبارة المفتاحية لا تظهر في بداية النص (أول :opening_words كلمة تقريبًا).',
                ],
                'keyphrase_in_heading' => [
                    'pass' => 'العبارة المفتاحية تظهر في عنوان فرعي واحد على الأقل.',
                    'warn' => 'العبارة المفتاحية لا تظهر في أي عنوان فرعي.',
                ],
                'keyphrase_density' => [
                    'pass' => 'كثافة العبارة :value وهي داخل النطاق الموصى به (:density_min٪ إلى :density_max٪).',
                    'warn' => 'كثافة العبارة :value؛ النطاق الموصى به من :density_min٪ إلى :density_max٪.',
                ],
                'content_length' => [
                    'pass' => 'طول النص :value كلمة.',
                    'warn' => 'طول النص :value كلمة، أقل من :min_words كلمة الموصى بها.',
                ],
                'heading_distribution' => [
                    'pass' => 'العناوين الفرعية تقسّم النص إلى أقسام مقروءة.',
                    'warn' => 'هناك مقطع من :value كلمة بلا عنوان فرعي؛ يُوصى بعنوان كل :section_words كلمة تقريبًا.',
                ],
            ],
        ],
        'warning' => [
            'missing_title' => 'عنوان الميتا فارغ ولم يُوجد بديل له.',
            'title_too_long' => 'عنوان الميتا أطول من :title_limit حرفًا الموصى بها وسيقتطعه جوجل.',
            'missing_description' => 'وصف الميتا فارغ ولم يُوجد بديل له.',
            'description_too_long' => 'وصف الميتا أطول من :description_limit حرفًا الموصى بها وسيقتطعه جوجل.',
        ],
    ],

    'validation' => [
        'media_file_required' => 'ملف الصورة مطلوب.',
        'featured_image_required' => 'اختيار صورة بارزة مطلوب.',
        'slides_max' => 'يمكنك إضافة :max شرائح كحد أقصى.',
        'slug_unique' => 'هذا المعرّف مستخدم بالفعل في اللغة :locale.',
        'alt_text_required' => 'النص البديل مطلوب لهذه الصورة.',
        'invalid_transition' => 'الانتقال من ":from" إلى ":to" غير مسموح.',
        'redirect_loop' => 'إعادة التوجيه هذه تعود إلى نفسها وتُنشئ حلقة.',
        'video_thumbnail_required' => 'يجب رفع صورة مصغّرة قبل نشر فيديو مُستضاف محليًا.',
        'link_target_required' => 'اختر وجهة واحدة فقط: رابط يدوي أو هدف داخل الموقع.',
        'menu_target_required' => 'يحتاج عنصر القائمة إلى وجهة واحدة فقط: رابط يدوي أو هدف داخل الموقع.',
        'menu_key_unknown' => 'الموضع [:key] غير معرَّف في إعدادات هذا الموقع. المواضع المتاحة: :locations',
        'system_key_taken' => 'الدور [:key] يخص بالفعل الصفحة «:title». أزِله من هناك أولاً ثم احفظ هذه الصفحة، وإلا تنازعت صفحتان على النشاني نفسه.',
        'menu_target_missing' => 'الهدف المختار غير موجود أو ليس من هذا النوع. اختر هدفًا آخر.',
        'menu_parent_missing' => 'الأصل المختار غير موجود.',
        'menu_parent_cycle' => 'لا يمكن أن يكون العنصر تابعًا لنفسه أو لأحد فروعه، وإلا فسيختفي هذا الفرع من القائمة بالكامل.',
        'menu_depth' => 'أقصى عمق للقائمة :depth مستويات، وهذا الاختيار يتجاوزه.',
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
            'media_featured' => 'هذه الصورة ليست صورة بارزة لأي عنصر.|هذه هي الصورة البارزة لعنصر واحد (حتى لو كان مسودة أو في المهملات)، وحذفها يتركه بلا صورة. اضبط له صورة بارزة أخرى أولًا.|هذه هي الصورة البارزة لعنصرين (حتى لو كانا مسودتين أو في المهملات)، وحذفها يتركهما بلا صورة. اضبط لهما صورة بارزة أخرى أولًا.|هذه هي الصورة البارزة لـ :count عناصر، بما فيها المسودات وما في المهملات، وحذفها يتركها بلا صورة. اضبط صورة بارزة أخرى لتلك العناصر أولًا.|هذه هي الصورة البارزة لـ :count عنصرًا، بما فيها المسودات وما في المهملات، وحذفها يتركها بلا صورة. اضبط صورة بارزة أخرى لتلك العناصر أولًا.|هذه هي الصورة البارزة لـ :count عنصر، بما فيها المسودات وما في المهملات، وحذفها يتركها بلا صورة. اضبط صورة بارزة أخرى لتلك العناصر أولًا.',
            'category_primary' => 'هذا ليس التصنيف الرئيسي لأي مقال.|هذا هو التصنيف الرئيسي لمقال واحد ويحدّد رابطه المعياري ومسار التنقّل. غيّر التصنيف الرئيسي لذلك المقال أولًا، وراجع مرشّح «المحذوفة» في قائمة المقالات.|هذا هو التصنيف الرئيسي لمقالين ويحدّد رابطيهما المعياريين ومسار التنقّل. غيّر التصنيف الرئيسي لهذين المقالين أولًا، وراجع مرشّح «المحذوفة» في قائمة المقالات.|هذا هو التصنيف الرئيسي لـ :count مقالات ويحدّد روابطها المعيارية ومسار التنقّل. غيّر التصنيف الرئيسي لتلك المقالات أولًا، وراجع مرشّح «المحذوفة» في قائمة المقالات.|هذا هو التصنيف الرئيسي لـ :count مقالًا ويحدّد روابطها المعيارية ومسار التنقّل. غيّر التصنيف الرئيسي لتلك المقالات أولًا، وراجع مرشّح «المحذوفة» في قائمة المقالات.|هذا هو التصنيف الرئيسي لـ :count مقال ويحدّد روابطها المعيارية ومسار التنقّل. غيّر التصنيف الرئيسي لتلك المقالات أولًا، وراجع مرشّح «المحذوفة» في قائمة المقالات.',
            // The site logo lives as an id inside a Setting document, not as an attachment row, so
            // nothing else here can see it — and losing it drops `logo` from the Organization JSON-LD.
            'media_logo' => 'هذه صورة شعار الموقع، وحذفها يُسقِط `logo` من البيانات المنظّمة للمؤسسة. اختر شعارًا آخر في الإعدادات أولًا.',
            // Refused only for PERMANENT deletion: something in the trash still needs this, so
            // destroying it would make that record come back wrong rather than not come back.
            'restorable_dependents' => 'لا يعتمد أي عنصر في المهملات على هذا.|لا يزال عنصر واحد في المهملات يعتمد على هذا، والحذف النهائي يجعله يعود ناقصًا. استعده أو احذفه نهائيًا أولًا.|لا يزال عنصران في المهملات يعتمدان على هذا، والحذف النهائي يجعلهما يعودان ناقصين. استعدهما أو احذفهما نهائيًا أولًا.|لا تزال :count عناصر في المهملات تعتمد على هذا، والحذف النهائي يجعلها تعود ناقصة. استعدها أو احذفها نهائيًا أولًا.|لا تزال :count عنصرًا في المهملات تعتمد على هذا، والحذف النهائي يجعلها تعود ناقصة. استعدها أو احذفها نهائيًا أولًا.|لا تزال :count عنصر في المهملات تعتمد على هذا، والحذف النهائي يجعلها تعود ناقصة. استعدها أو احذفها نهائيًا أولًا.',
        ],
        /*
         * Item 56 — written as "label: number" rather than as a counted noun. Arabic number
         * agreement has six forms, and these fragments are joined into a list inside another
         * sentence, where a case-inflected noun after each numeral would still read wrongly in
         * half the positions it can land in. A labelled count is correct for every number.
         */
        'label' => [
            'articles' => 'المقالات: :count',
            'child_categories' => 'التصنيفات الفرعية: :count',
            'navigation_links' => 'روابط القوائم والشرائح: :count',
            'menu_children' => 'العناصر الفرعية في القائمة: :count',
            'attached_to_content' => 'مرفقات المقالات: :count',
            'attached_to_page' => 'مرفقات الصفحات: :count',
            'attached_to_gallery' => 'صور المعارض: :count (سيعرض المعرض صورًا أقل بهذا العدد)',
            'attached_to_slide' => 'مرفقات الشرائح: :count',
            'attached_to_other' => 'مرفقات أخرى: :count',
        ],
        'in_use' => 'هذا العنصر مستخدم في: :usage. حذفه يسلبه منها.',
    ],

    /*
    | Item 10 — the trash itself.
    */
    'trash' => [
        'filter' => 'المحذوفة',
        'cascade' => 'حذف هذا لا ينقل أي عنصر آخر إلى المهملات.|حذف هذا ينقل عنصرًا واحدًا تحته إلى المهملات أيضًا، واستعادته تُعيده.|حذف هذا ينقل عنصرين تحته إلى المهملات أيضًا، واستعادته تُعيدهما.|حذف هذا ينقل :count عناصر تحته إلى المهملات أيضًا، واستعادته تُعيدها.|حذف هذا ينقل :count عنصرًا تحته إلى المهملات أيضًا، واستعادته تُعيدها.|حذف هذا ينقل :count عنصر تحته إلى المهملات أيضًا، واستعادته تُعيدها.',
        'only_trashed' => 'المحذوفة فقط',
        'without_trashed' => 'بدون المحذوفة',
        'with_trashed' => 'الكل، بما فيها المحذوفة',
        'pruned' => 'لم يُحذف أي عنصر نهائيًا.|تم الحذف النهائي لعنصر واحد بقي في المهملات أطول من مدة الاحتفاظ (بالأيام: :days).|تم الحذف النهائي لعنصرين بقيا في المهملات أطول من مدة الاحتفاظ (بالأيام: :days).|تم الحذف النهائي لـ :count عناصر بقيت في المهملات أطول من مدة الاحتفاظ (بالأيام: :days).|تم الحذف النهائي لـ :count عنصرًا بقيت في المهملات أطول من مدة الاحتفاظ (بالأيام: :days).|تم الحذف النهائي لـ :count عنصر بقيت في المهملات أطول من مدة الاحتفاظ (بالأيام: :days).',
        'nothing_pruned' => 'لا شيء في المهملات بلغ حدّ الاحتفاظ.',
        'prune_blocked' => 'لم يُستبقَ أي عنصر.|تم الإبقاء على عنصر واحد لأنه لا يزال مستخدمًا؛ يبقى في المهملات.|تم الإبقاء على عنصرين لأنهما لا يزالان مستخدمَين؛ يبقيان في المهملات.|تم الإبقاء على :count عناصر لأنها لا تزال مستخدمة؛ تبقى في المهملات.|تم الإبقاء على :count عنصرًا لأنها لا تزال مستخدمة؛ تبقى في المهملات.|تم الإبقاء على :count عنصر لأنها لا تزال مستخدمة؛ تبقى في المهملات.',
        // A different fact from `prune_blocked`: that was a decision, this was a surprise.
        'prune_failed' => 'لم يتعذّر حذف أي عنصر.|تعذّر الحذف النهائي لعنصر واحد فبقي في المهملات؛ رسالة الخطأ مذكورة أعلاه.|تعذّر الحذف النهائي لعنصرين فبقيا في المهملات؛ رسالتا الخطأ مذكورتان أعلاه.|تعذّر الحذف النهائي لـ :count عناصر فبقيت في المهملات؛ رسالة كل خطأ مذكورة أعلاه.|تعذّر الحذف النهائي لـ :count عنصرًا فبقيت في المهملات؛ رسالة كل خطأ مذكورة أعلاه.|تعذّر الحذف النهائي لـ :count عنصر فبقيت في المهملات؛ رسالة كل خطأ مذكورة أعلاه.',
    ],

    'system' => [
        'version' => 'الإصدار',
        'changelog' => 'سجل التغييرات',
        'recent_changes' => 'أحدث التغييرات',
        'installed_at' => 'وقت التثبيت',
        'about' => 'حول النظام',
        'no_changelog' => 'لا توجد إصدارات مسجّلة بعد.',

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
            'scheduler' => 'المُجدوِل (cron)',
            'queue' => 'عامل الطابور',
            'cache_store' => 'مخزن التخزين المؤقت',
            'running' => 'يعمل',
            'stopped' => 'متوقف',
            'unknown' => 'غير معروف',
            'last_seen' => 'آخر تقرير :ago',
            'queue_lag' => 'تأخّر الطابور: :seconds ثانية',
            'scheduler_stopped_help' => 'مُجدوِل لارافيل لا يعمل، لذا لا يحدث النشر المُجدول أيضًا. راجع أمر cron في docs/deployment.md.',
            'queue_stopped_help' => 'لا يوجد عامل يعالج الطابور، فتتراكم الترجمات وفهرسة البحث وخطافات الويب. شغّل خدمة queue:work.',
            'queue_unknown_help' => 'لا يُرسَل شيء إلى الطابور أثناء توقف المُجدوِل، فلا يمكن قياس حالته. أصلح cron أولًا.',
            'cache_tags_ok' => 'الوسوم مدعومة — إبطال التخزين المؤقت مُوجَّه.',
            'cache_tags_missing' => 'هذا المخزن لا يدعم الوسوم، لذا يمسح كل نشر التخزين المؤقت بالكامل، بما فيه عدّادات تحديد المعدل. استخدم Redis في الإنتاج.',
            // The web process and the cron process disagree about which cache they use. Each
            // writes where the other never reads, so an editor's publish clears a store the
            // API does not consult and the site serves stale pages indefinitely.
            'cache_store_mismatch' => 'تعارض في الإعداد: المُجدوِل يستخدم مخزن «:store» والويب يستخدم غيره. لا يصل إبطال التخزين المؤقت إلى الموقع. وحّد بيئة الاثنين.',
            'contact_protection' => 'حماية نموذج التواصل',
            'contact_no_submissions' => 'لم تصل أي رسالة بعد، لذا لا يمكن التأكد من عمل الحقل المخفي.',
            'contact_honeypot_missing' => 'الرسائل الأخيرة تصل بدون الحقل المخفي، أي أن الواجهة لا ترسله وهذه الحماية معطّلة. وحّد اسم الحقل مع الواجهة.',
        ],
    ],

    /*
    | Item 35 — someone else saved the record while this form was open.
    */
    'concurrency' => [
        'title' => 'تم تعديل هذا العنصر أثناء تحريرك له',
        'body' => 'حفظه :who :when. الحفظ الآن سيمحو تغييراته. أعد التحميل لترى ما تغيّر، أو احفظ فوقه إن كنت متأكدًا.',
        'reload' => 'إعادة التحميل',
        'overwrite' => 'الحفظ فوق تغييراته',
        // No audit row explains the change: an unaudited record, a quiet save or a background job.
        'background' => 'عملية تلقائية',
    ],

    'audit' => [
        'title' => 'سجل التدقيق',
        'intro' => 'يُسجّل كل إجراء كتابة في اللوحة تلقائيًا دون إمكانية التعطيل. هذا السجل للقراءة فقط.',
        'when' => 'الوقت',
        'who' => 'المستخدم',
        'system' => 'النظام',
        'event' => 'الحدث',
        'subject' => 'الموضوع',
        'description' => 'الوصف',
        'view_changes' => 'عرض التغييرات',
        'changes_heading' => 'التغييرات المسجّلة',
        'attribute' => 'الحقل',
        'before' => 'القيمة السابقة',
        'after' => 'القيمة الجديدة',
        'no_changes' => 'لا توجد تغييرات مسجّلة.',
        'denials' => 'المحاولات المرفوضة',

        // Item 55 — the event filter and badge showed the raw event names in English. `restored`
        // and `destroyed` are new with the trash (items 10/11); without labels they appeared in the
        // log with no filter option to find them by.
        'events' => [
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'نقل إلى المهملات',
            'restored' => 'استعادة',
            'destroyed' => 'حذف نهائي',
            'published' => 'نشر',
            'archived' => 'أرشفة',
            'denied' => 'رفض الوصول',
        ],
    ],

    'version' => [
        'history' => 'سجل النسخ',
        'select' => 'اختر نسخة',
        'empty' => 'لا توجد نسخ مسجّلة بعد.',
        'restore_warning' => 'سيُستبدل المحتوى الحالي بالنسخة المختارة. تُحفظ الحالة الحالية كنسخة جديدة، لذا يمكن التراجع.',
        'restored' => 'تم استعادة النسخة :number.',
        'not_found' => 'لم يتم العثور على النسخة المختارة.',
        // cms.versions.keep <= 0 means no pruning at all (HasContentVersions::pruneVersions).
        'keep_all' => 'يُحتفظ بجميع النسخ.',
        'keep_notice' => 'يُحتفظ بجميع النسخ.|يُحتفظ بآخر نسخة فقط.|يُحتفظ بآخر نسختين فقط.|يُحتفظ بآخر :count نسخ فقط.|يُحتفظ بآخر :count نسخةً فقط.|يُحتفظ بآخر :count نسخة فقط.',
    ],

    'contact' => [
        'received' => 'تم استلام رسالتك. شكرًا لتواصلك.',

        // Why a submission was flagged (item 16). `manual` is set by an editor, the rest
        // by App\Services\Contact\SpamInspector.
        'spam_reason' => [
            'honeypot' => 'تم تعبئة الحقل المخفي',
            'too_fast' => 'إرسال فوري',
            'missing_timing' => 'لا يوجد وقت لعرض النموذج',
            'manual' => 'قرار المحرر',
        ],
    ],

    'user' => [
        'password_help' => 'عند التحرير، اترك هذا الحقل فارغًا للإبقاء على كلمة المرور الحالية.',
        'role_locked' => 'لا يمكنك تغيير دورك؛ يستطيع المسؤول وحده إسناد الأدوار للآخرين.',
        'role_guidance' => 'صلاحيات كل دور',
        // Human-readable labels for the abilities in App\Enums\UserRole. The role
        // guidance in the form is built from the enum matrix and mapped through
        // these keys, so a change to the matrix is reflected without touching prose.
        'ability' => [
            'content.view' => 'عرض المحتوى',
            'content.create' => 'إنشاء المحتوى',
            'content.update.own' => 'تحرير محتواه الخاص',
            'content.update.any' => 'تحرير محتوى الآخرين',
            'content.delete' => 'حذف المحتوى',
            'content.publish' => 'نشر المحتوى',
            'content.restore' => 'استعادة نسخ المحتوى',
            'media.view' => 'عرض الوسائط',
            'media.upload' => 'رفع الوسائط',
            'media.update.own' => 'تحرير وسائطه الخاصة',
            'media.update.any' => 'تحرير وسائط الآخرين',
            'media.delete' => 'حذف الوسائط',
            'translation.view' => 'عرض الترجمات',
            'translation.review' => 'مراجعة الترجمات واعتمادها',
            'redirect.manage' => 'إدارة إعادة التوجيه',
            'menu.manage' => 'إدارة القوائم',
            'settings.manage' => 'إدارة إعدادات الموقع',
            'contact.view' => 'عرض رسائل التواصل',
            'user.manage' => 'إدارة المستخدمين',
            'audit.view' => 'عرض سجل النشاط',
            'release.manage' => 'إدارة الإصدارات',
        ],
    ],

    'translation_review' => [
        'title' => 'مراجعة الترجمات',
        'intro' => 'كل سطر يمثّل لغة واحدة لمحتوى واحد بحاجة إلى عمل. اللغة الأصلية مستثناة لأنها المرجع وليست ترجمة.',
        'locale' => 'اللغة',
        'source_text' => 'النص الأصلي',
        'last_reviewed_by' => 'آخر مراجع',
        'open' => 'تحرير المحتوى',
        'confirm' => 'الاعتماد يعلّم هذه الترجمة كمراجَعة ويجعلها مؤهّلة لخريطة موقع تلك اللغة. إذا تغيّر النص الأصلي لاحقًا فستُعلَّم تلقائيًا كبحاجة إلى تحديث.',
        'reviewed' => 'تم اعتماد ترجمة :locale.',
        'nothing_to_review' => 'لا شيء للمراجعة',
        'nothing_to_review_hint' => 'لا يوجد نص لهذه اللغة بعد. أدخل الترجمة أولًا.',
        'orphaned' => 'لم يتم العثور على المحتوى المرتبط بهذه الترجمة.',
        'empty' => 'جميع الترجمات مراجَعة',
        'empty_hint' => 'لا توجد لغة في انتظار ترجمة أو تحديث.',
    ],

    'settings' => [
        'general' => [
            'tab' => 'عام',
            'identity' => 'هوية الموقع',
            'identity_help' => 'اسم الموقع يُنشَر إلى الواجهة عبر الـ API، ويُستخدم كاسم Organization في البيانات المنظّمة، ويظهر في ترويسة هذه اللوحة نفسها.',
            'site_name' => 'اسم الموقع (:locale)',
            'social' => 'حسابات التواصل',
            'social_help' => 'عناوين كاملة بـ https. تُنشَر إلى الواجهة وتظهر في خاصية sameAs في البيانات المنظّمة.',
            'social_links' => 'عناوين الحسابات',
            'social_add' => 'إضافة عنوان',
        ],

        'organisation' => [
            'section' => 'هوية المؤسسة (البيانات المنظّمة)',
            'section_help' => 'تُعرّف هذه الحقول ناشر الموقع لمحرّكات البحث وتظهر كـ publisher في JSON-LD لكل مقال. كلّها اختيارية، وما يُترك فارغًا لا يُنشَر إطلاقًا.',
            'type' => 'نوع المؤسسة',
            'type_help' => 'الوكالة الخبرية والشركة والجهة الحكومية والجامعة كيانات مختلفة لمحرّك البحث. اتركه «مؤسسة (عام)» إن لم تكن متأكدًا.',
            'logo' => 'شعار المؤسسة',
            'logo_help' => 'أهم حقل هنا. تستخدم Google شعار الناشر في النتائج الغنية للمقالات وفي لوحة المعرفة.',
            'legal_name' => 'الاسم القانوني (المسجَّل)',
            'legal_name_help' => 'إن كان مختلفًا عن الاسم التجاري للموقع.',
            'founding_date' => 'تاريخ التأسيس',
            'founding_date_help' => 'بالصيغة YYYY-MM-DD، لأن schema.org يتوقّع تاريخًا بمعيار ISO.',
            'alternate_name' => 'اسم بديل (:locale)',
            'description' => 'وصف المؤسسة (:locale)',
        ],
        'discovery' => [
            'tab' => 'الإحصاءات والتحقّق',
            'analytics' => 'إحصاءات الزيارات',
            'analytics_help' => 'تُخزَّن هذه المعرّفات وتُسلَّم إلى الواجهة لتعرضها فقط. ولا تُحمَّل أبدًا في هذه اللوحة — فالواجهة الخلفية لا ترسل أي طلب خارجي بشكل مقصود.',
            'ga' => 'معرّف Google Analytics',
            'gtm' => 'معرّف Google Tag Manager',
            'verification' => 'التحقّق من ملكية الموقع',
            'verification_help' => 'رموز التحقّق لتضعها الواجهة في وسم meta. تخصّ الموقع العام لا هذا المضيف.',
            'gsc' => 'رمز Google Search Console',
            'bing' => 'رمز Bing Webmaster',
        ],

        'contact' => [
            'tab' => 'الاتصال',
            'details' => 'بيانات الاتصال',
            'details_help' => 'تُقدَّم هذه القيم إلى الواجهة عبر الـ API لتعرضها في صفحة الاتصال.',
            'phone' => 'الهاتف',
            'email' => 'البريد الإلكتروني',
            'address' => 'العنوان (:locale)',
            'office_hours' => 'ساعات العمل (:locale)',
            'map' => 'الموقع على الخريطة',
            'map_help' => 'املأ القيمتين معًا أو اتركهما فارغتين. بوجودهما تُنتَج بيانات LocalBusiness المنظّمة.',
            'latitude' => 'خط العرض',
            'longitude' => 'خط الطول',
            'form_labels' => 'تسميات نموذج الاتصال',
            'form_labels_help' => 'المفاتيح تستهلكها الواجهة، فاستخدم المفاتيح التي تتوقّعها (مثل name وemail وmessage).',
            'form_labels_locale' => 'التسميات (:locale)',
            'form_labels_key' => 'المفتاح',
            'form_labels_value' => 'التسمية',
        ],

        'maintenance' => [
            'tab' => 'وضع الصيانة',
            'section' => 'وضع الصيانة',
            'section_help' => 'يؤثّر هذا المفتاح على Delivery API وحده ويُبقي هذه اللوحة متاحة؛ وهو ليس مثل «php artisan down» الذي يوقف التطبيق بالكامل.',
            'enabled' => 'تفعيل وضع الصيانة',
            'enabled_help' => 'أثناء التفعيل يردّ Delivery API على كل طلب بالرمز 503 مع ترويسة Retry-After، فيتوقّف الموقع العام. والرمز 503 يطلب من الزاحف العودة لاحقًا لا إزالة الصفحة.',
            'active_title' => 'الموقع في وضع الصيانة',
            'active_body' => 'إلى أن يُوقَف هذا المفتاح، يردّ Delivery API على الواجهة بالرمز 503 ويكون الموقع العام غير متاح.',
        ],

        'title' => 'الإعدادات',
        'save' => 'حفظ الإعدادات',
        'saved' => 'تم حفظ الإعدادات.',
        'validation_failed' => 'لم تُحفَظ الإعدادات',
        'validation_failed_body' => 'بعض الحقول غير صالحة ولم يُحفَظ أي تغيير. راجع هذه التبويبات: :tabs',
        'ai' => [
            'tab' => 'الترجمة الآلية',
            'section' => 'الترجمة بالذكاء الاصطناعي',
            'section_help' => 'ترجمة آلية للحقول النصية من اللغة الأصلية (الفارسية) إلى اللغات الأخرى. تحتاج النتيجة إلى مراجعة بشرية قبل النشر.',
            'enabled' => 'تفعيل الترجمة بالذكاء الاصطناعي',
            'enabled_help' => 'عند الإيقاف، يُخفى إجراء «الترجمة بالذكاء الاصطناعي» من سير عمل الترجمة.',
            'provider' => 'خدمة الترجمة',
            'provider_help' => 'الخدمات الثلاث تستخدم البروتوكول نفسه (OpenAI). تُخزَّن لكل خدمة مفتاحها ونموذجها على حدة، فالتنقّل بينها لا يتطلّب إدخال المفتاح من جديد.',
            'model' => 'النموذج',
            'model_help' => 'اتركه فارغًا لاستخدام النموذج الافتراضي لخدمة :provider وهو :model. لاحظ أن معرّفات النماذج غير متوافقة بين الخدمات — انسخ المعرّف من لوحة الخدمة نفسها.',
            'api_key' => 'مفتاح واجهة :provider',
            'api_key_help' => 'يُخزَّن في قاعدة البيانات وليس في ملف env. مطلوب لتفعيل الترجمة بالذكاء الاصطناعي.',
            'api_key_help_set' => 'يوجد مفتاح مخزَّن. أدخل مفتاحًا جديدًا لاستبداله؛ وترك الحقل فارغًا يُبقي المفتاح الحالي.',
            'api_key_docs' => 'للحصول على مفتاح: :url',
            'api_key_clear' => 'حذف المفتاح المخزَّن',
            'api_key_cleared' => 'تم حذف المفتاح المخزَّن.',
        ],
    ],

    /*
     * The translation providers (App\Enums\AiProvider). The descriptions are what
     * make the choice meaningful to an administrator, so each one says what the
     * service is rather than just naming it.
     */
    'organisation_type' => [
        'Organization' => 'مؤسسة (عام)',
        'NewsMediaOrganization' => 'مؤسسة إعلامية خبرية',
        'Corporation' => 'شركة',
        'GovernmentOrganization' => 'جهة حكومية',
        'EducationalOrganization' => 'مؤسسة تعليمية',
        'NGO' => 'منظمة غير حكومية',
        'LocalBusiness' => 'نشاط تجاري محلي',
    ],

    'ai_provider' => [
        'openrouter' => 'OpenRouter',
        'openrouter_help' => 'بوّابة دولية بأوسع قائمة نماذج. الدفع بعملة أجنبية، والوصول إليها من إيران يحتاج عادةً إلى إعدادات شبكة.',
        'gapgpt' => 'GapGPT',
        'gapgpt_help' => 'بوّابة إيرانية متوافقة مع OpenAI. احصل على المفتاح وقائمة النماذج من لوحة GapGPT نفسها.',
        'chatqt' => 'ChatQT',
        'chatqt_help' => 'بوّابة إيرانية متوافقة مع OpenAI، بمحفظة بالريال ولوحة للمطوّرين. تذكر وثائقها أنها مصمّمة للوصول من إيران دون VPN.',
    ],

    'ai_translation' => [
        'confirm' => 'سيُترجَم هذا المحتوى بالكامل، بما في ذلك المتن الغني، من الفارسية إلى هذه اللغة ويُحفظ كترجمة آلية. تُحفَظ بنية المستند، لكن النتيجة تحتاج إلى مراجعة بشرية قبل النشر.',
        'confirm_outdated' => 'سبق أن رُوجعت هذه اللغة ثم تغيّر النص الفارسي بعد ذلك. إعادة الترجمة تستبدل النص الحالي، وتُلغي اعتماد المراجع السابق، وتُخرج هذه اللغة من خريطة موقعها حتى يراجعها إنسان مرة أخرى.',
        'success' => 'تمت الترجمة الآلية إلى :locale وهي بانتظار المراجعة.',
        'queued' => 'تمت إضافة الترجمة الآلية إلى :locale في قائمة الانتظار.',
        'queued_hint' => 'تُنفَّذ الترجمة في الخلفية وقد تستغرق بضع دقائق. ستظهر النتيجة — نجاحًا أو فشلًا — في جرس الإشعارات داخل اللوحة.',
        'skipped' => 'تم تخطّي الترجمة بالذكاء الاصطناعي',
        'failed' => 'فشلت الترجمة بالذكاء الاصطناعي',
        'error' => [
            'disabled' => 'الترجمة بالذكاء الاصطناعي غير مفعّلة. فعّلها من صفحة الإعدادات.',
            'missing_key' => 'لا يوجد مفتاح واجهة مُعد لخدمة الترجمة المختارة. أدخله في صفحة الإعدادات.',
            'request_failed' => 'تعذّر الوصول إلى خدمة الترجمة. يرجى المحاولة بعد قليل.',
            'empty_source' => 'لا يوجد نص أصلي للترجمة. أكمِل المحتوى الفارسي أولًا.',
            'already_reviewed' => 'سبق أن رُوجعت هذه اللغة؛ لذلك تم تخطّي الترجمة الآلية تفاديًا للكتابة فوق العمل البشري.',
            'source_too_long' => 'هذا المحتوى أطول من أن يُترجَم آليًا في جلسة واحدة. قسّمه إلى محتويات أقصر، أو ارفع حد الطلبات لكل محتوى في الإعدادات.',
        ],
    ],
];
