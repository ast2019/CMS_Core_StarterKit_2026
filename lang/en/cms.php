<?php

declare(strict_types=1);

return [

    'status' => [
        'draft' => 'Draft',
        'review' => 'In review',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'translation' => [
        'not_translated' => 'Not translated',
        'ai_translated' => 'Machine translated',
        'reviewed' => 'Reviewed',
        'outdated' => 'Outdated',
    ],

    'role' => [
        'admin' => 'Administrator',
        'editor' => 'Editor',
        'author' => 'Author',
        'viewer' => 'Viewer',
    ],

    'redirect' => [
        'permanent' => 'Permanent (301)',
        'temporary' => 'Temporary (302)',
        'slug_changed_title' => 'This record’s URL changed',
        'slug_changed_body' => 'The slug changed for :count locale(s). Create a 301 redirect to avoid 404s on the old URL.',
        'create_action' => 'Create 301 redirect',
        'created' => 'Created :count redirect(s).',
    ],

    /*
     * Structured-data type of an article (App\Enums\ArticleSchemaType). The type NAME
     * is emitted verbatim in the JSON-LD, so it is not translated — only the
     * explanation is.
     */
    'schema_type' => [
        'Article' => 'General article (Article)',
        'Article_help' => 'For content whose publication date is not decisive: guides, explainers, evergreen pieces.',
        'NewsArticle' => 'News (NewsArticle)',
        'NewsArticle_help' => 'For a news story. It claims recency, so it is the wrong choice for evergreen content.',
        'BlogPosting' => 'Blog post (BlogPosting)',
        'BlogPosting_help' => 'For a personal or blog-style piece written in an author\'s voice.',
    ],

    'media_role' => [
        'featured' => 'Featured image',
        'inline' => 'Inline',
        'gallery' => 'Gallery',
        'og_image' => 'Social share image',
    ],

    'locale' => [
        'tabs' => 'Languages',
        'source_badge' => 'Source',
    ],

    'nav' => [
        'content' => 'Content',
        'taxonomy' => 'Taxonomy',
        'media' => 'Media',
        'appearance' => 'Appearance',
        'system' => 'System',
    ],

    'resource' => [
        'content' => 'Article',
        'contents' => 'News',
        'category' => 'Category',
        'categories' => 'Categories',
        'tag' => 'Tag',
        'tags' => 'Tags',
        'gallery' => 'Gallery',
        'galleries' => 'Galleries',
        'page' => 'Page',
        'pages' => 'Pages',
        'slide' => 'Slide',
        'slides' => 'Slideshow',
        'media_asset' => 'Media file',
        'media_assets' => 'Media library',
        'menu_item' => 'Menu item',
        'menu_items' => 'Navigation',
        'redirect' => 'Redirect',
        'redirects' => 'Redirects',
        'contact_submission' => 'Contact message',
        'contact_submissions' => 'Contact messages',
        'user' => 'User',
        'users' => 'Users',
    ],

    'section' => [
        'seo' => 'SEO and metadata',
        'publishing' => 'Publishing',
        'featured_image' => 'Featured image',
        'media' => 'File',
        'link' => 'Link',
        'appearance' => 'Appearance',
        'identity' => 'Site identity',
        'analytics' => 'Analytics and verification',
        'social_card' => 'Social share card (optional)',
        'social_card_help' => 'Left blank, the meta title and description are used for social networks.',
    ],

    'field' => [
        'title' => 'Title',
        'name' => 'Name',
        'slug' => 'Slug',
        'slug_help' => 'Generated from the title when left empty. Changing it on a published record changes the public URL.',
        'excerpt' => 'Excerpt',
        'description' => 'Description',
        'body' => 'Body',
        'blocks' => 'Content',
        'answer_paragraph' => 'Short answer (GEO)',
        'answer_paragraph_help' => 'A self-contained paragraph answering the main question without the rest of the article. Answer engines quote this.',
        'meta_title' => 'Meta title',
        'meta_title_help' => 'Falls back to the title. Around 60 characters is recommended.',
        'meta_description' => 'Meta description',
        'meta_description_help' => 'Falls back to the excerpt. Around 155 characters is recommended.',
        'robots_meta' => 'Robots directive',
        'robots_meta_help' => 'Drafts and unreviewed translations are set to noindex automatically.',
        'focus_keyphrase' => 'Focus keyphrase',
        'focus_keyphrase_help' => 'The phrase you want this record found by IN THIS LOCALE. Set per locale — it is not a translation of the Persian one.',
        'og_title' => 'Social card title',
        'og_title_help' => 'Left blank, the meta title is used.',
        'og_description' => 'Social card description',
        'og_description_help' => 'Left blank, the meta description is used.',
        'schema_type' => 'Structured-data type',
        'schema_type_help' => 'Announced verbatim in the page JSON-LD, and the same in every locale.',
        'status' => 'Status',
        'publish_date' => 'Publish date',
        'publish_date_help' => 'A future date means scheduled publication; the record stays hidden from the public until then.',
        'primary_category' => 'Primary category',
        'primary_category_help' => 'Drives the canonical URL and the breadcrumb trail.',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'author' => 'Author',
        'featured_image' => 'Featured image',
        'featured_image_help' => 'Choose from the media library. A featured image is required.',
        'gallery_items_help' => 'The order you choose is the order the images appear in the gallery.',
        'translation_status' => 'Translation status',
        'parent' => 'Parent',
        'parent_help' => 'Leave empty to keep this item at the top level. A menu may be at most :depth levels deep.',
        'position' => 'Order',
        'is_active' => 'Active',
        'link' => 'Link',
        'menu_key' => 'Menu',
        'menu_key_help' => 'Menu locations are declared in the site configuration. This list is what the frontend can actually render.',
        'target' => 'Target',
        'target_help' => 'Type a few letters of the title to search. The URL is built per locale from the target\'s slug in that locale.',
        'opens_in_new_tab' => 'Open in a new tab',
        'alt_text' => 'Alternative text',
        'alt_text_help' => 'Required for accessibility and image SEO.',
        'caption' => 'Caption',
        'type' => 'Type',
        'file' => 'File',
        'duration_seconds' => 'Duration (seconds)',
        'external_embed_url' => 'External embed URL',
        'video_thumbnail' => 'Video thumbnail',
        'video_thumbnail_help' => 'Required for the video sitemap. Must be uploaded manually when ffprobe is unavailable.',
        'from_path' => 'From path',
        'to_path' => 'To path',
        'redirect_type' => 'Redirect type',
        'hits' => 'Hits',
        'subtitle' => 'Subtitle',
        'cta_label' => 'Button label',
        'image_dimensions' => 'Image dimensions',
        'image_dimensions_help' => 'Required to prevent layout shift (CLS).',
        'read_at' => 'Read at',
        'message' => 'Message',
        'email' => 'Email',
        'phone' => 'Phone',
        'subject' => 'Subject',
        'page_role' => 'Page role',
        'page_role_help' => 'A role marks a page the application resolves by name rather than by slug. The homepage is served at /fa (the locale root), not at /fa/slug. Only one page can be the homepage.',
        'system_key' => 'System key',
        'password' => 'Password',
        'role' => 'Role',
    ],

    'table' => [
        'scheduled' => 'Scheduled',
        'unread' => 'Unread',
        'items' => 'items',
        'no_alt_text' => 'No alternative text',
    ],

    'filter' => [
        'needs_translation' => 'Needs translation',
        'scheduled' => 'Scheduled',
        'unread' => 'Unread',
        'missing_alt_text' => 'Missing alternative text',
        'active' => 'Active',
    ],

    'action' => [
        'preview' => 'Preview',
        'publish' => 'Publish',
        'archive' => 'Archive',
        'mark_read' => 'Mark as read',
        'review_translation' => 'Approve translation',
        'restore_version' => 'Restore this version',
        'translate_ai' => 'Translate with AI',
    ],

    'blocks' => [
        'callout' => [
            'label' => 'Callout',
            'description' => 'A highlighted aside for a note, warning or extra detail.',
            'tone' => 'Tone',
            'tone_info' => 'Info',
            'tone_success' => 'Success',
            'tone_warning' => 'Warning',
            'tone_danger' => 'Danger',
            'title' => 'Callout title',
            'body' => 'Callout body',
        ],
        'hero' => [
            'label' => 'Hero',
            'description' => 'A full-width banner with a heading, lead text and a button.',
            'heading' => 'Heading',
            'lead' => 'Lead text',
            'image' => 'Image',
            'image_help' => 'Chosen from the media library so alt text and local storage are preserved.',
            'cta_label' => 'Button label',
            'cta_url' => 'Button URL',
        ],
        'quote' => [
            'label' => 'Quote',
            'description' => 'A pull quote with attribution.',
            'quote' => 'Quote',
            'attribution' => 'Attributed to',
            'attribution_role' => 'Role or title',
        ],
        'gallery_embed' => [
            'label' => 'Gallery',
            'description' => 'Embeds an existing gallery by reference, so later edits appear everywhere it is used.',
            'gallery' => 'Gallery',
            'layout' => 'Layout',
            'layout_grid' => 'Grid',
            'layout_carousel' => 'Carousel',
            'layout_masonry' => 'Masonry',
            'max_items' => 'Maximum items',
            'max_items_help' => 'Leave empty to show every image.',
            'missing' => 'The referenced gallery has been deleted.',
            'empty' => 'This gallery has no images.',
        ],
    ],

    'page' => [
        'homepage' => 'Homepage',
        'role_none' => 'Ordinary page',
        'system_role' => 'System page (:key)',
    ],

    'menu' => [
        /*
         * Labels for the menu locations declared in `cms.menus.locations`.
         * A location with no entry here falls back to its own key, so a client site
         * can add one to config and ship without editing three lang files.
         */
        'location' => [
            'header' => 'Header menu',
            'footer' => 'Footer menu',
            'sidebar' => 'Sidebar menu',
        ],
        'target_not_live' => 'not published',
    ],

    'media' => [
        'inline_upload' => 'Upload a new image',
        'inline_upload_heading' => 'Upload an image to the media library',
        'inline_upload_description' => 'The image is added to the media library and selected here, without leaving this page.',
        'inline_upload_submit' => 'Upload and select',
    ],

    'seo' => [
        'warnings' => 'SEO check',
        'no_warnings' => 'No warnings.',
        'character_count' => ':count of :limit characters',

        'preview' => [
            'label' => 'Search-result preview',
            'no_url' => 'No slug in this locale, so it will not appear in this locale\'s results.',
            'empty_title' => '(nothing to show as a title)',
            'empty_description' => '(nothing to show as a description)',
            'cut_hint' => 'This part is not shown in search results.',
            'noindex' => 'In this locale the directive «:robots» keeps this record out of the index. Being a draft, or having an unreviewed translation, has the same effect on its own.',
        ],

        'analysis' => [
            'label' => 'Focus keyphrase check',
            'no_keyphrase' => 'Set a focus keyphrase to run the keyphrase checks.',
            'caveat' => 'These checks match the phrase LITERALLY: word stems, plural forms and synonyms in Persian and Arabic are not analysed, and no readability score is computed. Checks that read the text itself refresh after saving.',
            'band' => [
                'good' => 'Looking good',
                'fair' => 'Could be better',
                'poor' => 'Needs work',
            ],
            'check' => [
                'keyphrase_in_title' => [
                    'pass' => 'The keyphrase is in the meta title.',
                    'warn' => 'The keyphrase is not in the meta title, which is the place it matters most.',
                ],
                'keyphrase_in_description' => [
                    'pass' => 'The keyphrase is in the meta description.',
                    'warn' => 'The keyphrase is not in the meta description. That does not affect ranking, but it is bolded in the snippet and lifts click-through.',
                ],
                'keyphrase_in_slug' => [
                    'pass' => 'The keyphrase is in the slug.',
                    'warn' => 'The keyphrase is not in the slug.',
                ],
                'keyphrase_in_opening' => [
                    'pass' => 'The keyphrase appears in the opening of the text (first ~:opening_words words).',
                    'warn' => 'The keyphrase does not appear in the opening of the text (first ~:opening_words words).',
                ],
                'keyphrase_in_heading' => [
                    'pass' => 'The keyphrase appears in at least one heading.',
                    'warn' => 'The keyphrase appears in no heading.',
                ],
                'keyphrase_density' => [
                    'pass' => 'Keyphrase density is :value, inside the recommended :density_min%-:density_max% band.',
                    'warn' => 'Keyphrase density is :value; the recommended band is :density_min% to :density_max%.',
                ],
                'content_length' => [
                    'pass' => 'The text is :value words long.',
                    'warn' => 'The text is :value words long, under the recommended :min_words.',
                ],
                'heading_distribution' => [
                    'pass' => 'Headings break the text into readable sections.',
                    'warn' => 'One run of :value words has no heading; a heading roughly every :section_words words is recommended.',
                ],
            ],
        ],
        'warning' => [
            'missing_title' => 'The meta title is empty and no fallback was found for it.',
            'title_too_long' => 'The meta title is longer than the recommended :title_limit characters and Google will truncate it.',
            'missing_description' => 'The meta description is empty and no fallback was found for it.',
            'description_too_long' => 'The meta description is longer than the recommended :description_limit characters and Google will truncate it.',
        ],
    ],

    'validation' => [
        'media_file_required' => 'An image file is required.',
        'featured_image_required' => 'A featured image is required.',
        'slides_max' => 'You may have at most :max slides.',
        'slug_unique' => 'This slug is already in use for the :locale locale.',
        'alt_text_required' => 'Alternative text is required for this image.',
        'invalid_transition' => 'Moving from ":from" to ":to" is not allowed.',
        'redirect_loop' => 'This redirect points back to itself and would create a loop.',
        'video_thumbnail_required' => 'A thumbnail must be uploaded before a locally hosted video can be published.',
        'link_target_required' => 'Pick exactly one destination: either a manual link or a target inside the site.',
        'menu_target_required' => 'A menu item needs exactly one destination: either a manual link or a target inside the site.',
        'menu_key_unknown' => 'The location [:key] is not declared in this site’s configuration. Available locations: :locations',
        'system_key_taken' => 'The [:key] role already belongs to the page “:title”. Clear it there first, then save this page — otherwise two pages compete for the same URL.',
        'menu_target_missing' => 'The chosen target does not exist, or is not of that type. Pick another one.',
        'menu_parent_missing' => 'The chosen parent does not exist.',
        'menu_parent_cycle' => 'An item cannot sit under itself or under one of its own children — the whole branch would disappear from the menu.',
        'menu_depth' => 'A menu may be at most :depth levels deep, and this would go deeper.',
    ],

    'system' => [
        'version' => 'Version',
        'changelog' => 'Changelog',
        'recent_changes' => 'Recent changes',
        'installed_at' => 'Installed at',
        'about' => 'About this system',
        'no_changelog' => 'No releases recorded yet.',
    ],

    'audit' => [
        'title' => 'Audit log',
        'intro' => 'Every write action in the panel is recorded automatically, with no opt-out. This log is read-only.',
        'when' => 'When',
        'who' => 'User',
        'system' => 'System',
        'event' => 'Event',
        'subject' => 'Subject',
        'description' => 'Description',
        'view_changes' => 'View changes',
        'changes_heading' => 'Recorded changes',
        'attribute' => 'Attribute',
        'before' => 'Before',
        'after' => 'After',
        'no_changes' => 'No changes recorded.',
        'denials' => 'Denied attempts',
    ],

    'version' => [
        'history' => 'Version history',
        'select' => 'Choose a version',
        'empty' => 'No versions recorded yet.',
        'restore_warning' => 'The current content will be replaced by the selected version. The current state is itself saved as a new version, so this is reversible.',
        'restored' => 'Restored version :number.',
        'not_found' => 'The selected version could not be found.',
        'keep_notice' => 'Only the most recent :count versions are kept.',
    ],

    'contact' => [
        'received' => 'Your message has been received. Thank you for getting in touch.',
    ],

    'user' => [
        'password_help' => 'When editing, leave this blank to keep the current password.',
        'role_locked' => 'You cannot change your own role; only an administrator may assign roles to others.',
        'role_guidance' => 'What each role can do',
        // Human-readable labels for the abilities in App\Enums\UserRole. The role
        // guidance in the form is built from the enum matrix and mapped through
        // these keys, so a change to the matrix is reflected without touching prose.
        'ability' => [
            'content.view' => 'View content',
            'content.create' => 'Create content',
            'content.update.own' => 'Edit their own content',
            'content.update.any' => 'Edit anyone\'s content',
            'content.delete' => 'Delete content',
            'content.publish' => 'Publish content',
            'content.restore' => 'Restore content versions',
            'media.view' => 'View media',
            'media.upload' => 'Upload media',
            'media.update.own' => 'Edit their own media',
            'media.update.any' => 'Edit anyone\'s media',
            'media.delete' => 'Delete media',
            'translation.view' => 'View translations',
            'translation.review' => 'Review and approve translations',
            'redirect.manage' => 'Manage redirects',
            'menu.manage' => 'Manage navigation menus',
            'settings.manage' => 'Manage site settings',
            'contact.view' => 'View contact messages',
            'user.manage' => 'Manage users',
            'audit.view' => 'View the audit log',
            'release.manage' => 'Manage releases',
        ],
    ],

    'translation_review' => [
        'title' => 'Translation review',
        'intro' => 'Each row is one locale of one record that needs work. The source locale is excluded, because it is the reference rather than a translation.',
        'locale' => 'Locale',
        'source_text' => 'Source text',
        'last_reviewed_by' => 'Last reviewed by',
        'open' => 'Edit record',
        'confirm' => 'Approving marks this translation reviewed and makes it eligible for that locale\'s sitemap. If the source text changes later it is flagged as outdated automatically.',
        'reviewed' => 'Marked the :locale translation as reviewed.',
        'nothing_to_review' => 'Nothing to review',
        'nothing_to_review_hint' => 'This locale has no text yet. Enter the translation first.',
        'orphaned' => 'The record this translation belongs to could not be found.',
        'empty' => 'Every translation is reviewed',
        'empty_hint' => 'No locale is waiting for translation or an update.',
    ],

    'settings' => [
        'title' => 'Settings',
        'save' => 'Save settings',
        'saved' => 'Settings saved.',
        'ai' => [
            'section' => 'AI translation',
            'section_help' => 'Machine-translate text fields from the source locale (Persian) into other locales via OpenRouter. The result still needs a human review before it is published.',
            'enabled' => 'Enable AI translation',
            'enabled_help' => 'When off, the "Translate with AI" action is hidden from the translation workflow.',
            'model' => 'Model',
            'model_help' => 'Defaults to openai/gpt-4o-mini and you can change it. Any valid OpenRouter model id is accepted.',
            'api_key' => 'OpenRouter API key',
            'api_key_help' => 'Stored in the database, not in the env file. Required to enable AI translation.',
            'api_key_help_set' => 'A key is stored. Enter a new one to replace it; leaving it blank keeps the current key.',
        ],
    ],

    'ai_translation' => [
        'confirm' => 'This whole record, including the rich body, will be translated from Persian into this locale and saved as machine-translated. The document structure is preserved, but the result needs human review before publishing.',
        'confirm_outdated' => 'This locale was reviewed once and the Persian source has changed since. Re-translating overwrites the existing text, discards the previous reviewer sign-off, and removes this locale from its sitemap until a human reviews it again.',
        'success' => 'Machine-translated into :locale and awaiting review.',
        'queued' => 'Machine translation into :locale has been queued.',
        'queued_hint' => 'The translation runs in the background and may take a few minutes. The outcome — success or failure — will appear in the panel notification bell.',
        'skipped' => 'AI translation skipped',
        'failed' => 'AI translation failed',
        'error' => [
            'disabled' => 'AI translation is not enabled. Turn it on from the Settings page.',
            'missing_key' => 'The OpenRouter API key is not configured. Enter it on the Settings page.',
            'request_failed' => 'The translation service could not be reached. Please try again shortly.',
            'empty_source' => 'There is no source text to translate. Complete the Persian content first.',
            'already_reviewed' => 'This locale has already been reviewed; automatic translation was skipped to avoid overwriting human work.',
            'source_too_long' => 'This record is too long to machine-translate in one run. Split it into shorter records, or raise the per-record request limit in the configuration.',
        ],
    ],
];
