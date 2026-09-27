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
        'slug_changed_body' => 'The slug changed for one locale. Create a 301 redirect to avoid 404s on the old URL.|The slug changed for :count locales. Create a 301 redirect to avoid 404s on the old URL.',
        'create_action' => 'Create 301 redirect',
        'created' => 'Created one redirect.|Created :count redirects.',
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
        'form' => 'Form',
        'forms' => 'Forms',
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
        'diagnostics' => 'Diagnostics',
    ],

    'field' => [
        'two_factor' => 'Two-factor',
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
        'spam_reason' => 'Spam reason',
        'last_login' => 'Last sign-in',
        'never_signed_in' => 'Never signed in',
        'preview' => 'Preview',
        'read_status' => 'Read status',
        'user_agent' => 'User agent',
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

    'dashboard' => [
        'title' => 'Dashboard',
        'today' => 'Today, :date',

        'live' => 'Published',
        'live_this_month' => ':count this month',
        'in_progress' => 'In progress',
        'in_progress_breakdown' => 'Drafts: :drafts — in review: :review',
        'scheduled' => 'Scheduled',
        'next_publish' => 'Next: :date',
        'nothing_scheduled' => 'Nothing scheduled',
        // Item 18 — replaces the promised publish date when cron is not running.
        'scheduler_stopped' => 'The scheduler is stopped — these will not publish.',
        // Raised for records whose time has ALREADY passed while cron was down: they are
        // live by the database's reckoning but the Delivery cache was never refreshed, so
        // they are probably not on the public site.
        'scheduler_missed' => 'The scheduler is stopped and one record fell due — it is probably not on the site.|The scheduler is stopped and :count records fell due — they are probably not on the site.',
        'unread_messages' => 'Unread messages',
        'inbox_clear' => 'All messages read',

        'publishing_activity' => 'Publishing activity',
        'publishing_activity_description' => 'Articles published per month',
        'published_count' => 'Published',

        'translation_progress' => 'Translation progress',
        'reviewed_ratio' => ':reviewed of :total reviewed',
        'no_translation_records' => 'Nothing tracked for this locale yet',

        'recent_activity' => 'Recent activity',
    ],

    /*
     * Date picker chrome. Month and weekday names come from ICU, not from here.
     */

    'date' => [
        'today' => 'Today',
        'clear' => 'Clear',
        'previous_month' => 'Previous month',
        'next_month' => 'Next month',
    ],

    'category' => [
        'cannot_detach_primary' => 'This is the article’s primary category, which decides its canonical URL and breadcrumb trail. Change the primary category on the article first, then detach it here.',
        'primary_skipped' => 'One article was kept because this is its primary category.|:count articles were kept because this is their primary category.',
    ],
    'table' => [
        'primary' => 'Primary',
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
        'unattached' => 'Not attached to anything',
        'unattached_indicator' => 'Not attached — images inside body text are not checked',
        'active' => 'Active',
        'spam' => 'Spam',
        'spam_all' => 'All messages',
        'spam_only' => 'Spam only',
        'spam_excluded' => 'Excluding spam',
    ],

    'action' => [
        'detach_selected' => 'Detach selected from this category',
        'detach_selected_done' => 'Detached one article from this category.|Detached :count articles from this category.',
        'publish_selected' => 'Publish selected',
        'publish_selected_confirm' => 'The selected records will be published. Any without a publish date get the current time; an existing future date is left alone.',
        'publish_selected_done' => 'Published one record.|Published :count records.',
        'unpublish_selected' => 'Unpublish selected',
        'unpublish_selected_confirm' => 'The selected records return to draft and leave the public site.',
        'unpublish_selected_done' => 'Unpublished one record.|Unpublished :count records.',
        'bulk_skipped' => 'One record was left unchanged because you may not edit it.|:count records were left unchanged because you may not edit them.',
        // Already in the requested status. Distinct from bulk_skipped: nothing was refused,
        // there was simply nothing to do — and reporting it as a refusal would send an
        // editor looking for a permission problem that does not exist.
        'bulk_unchanged' => 'One record was already in that status.|:count records were already in that status.',
        'mark_read_selected' => 'Mark as read',
        'mark_read_selected_done' => 'Marked one message as read.|Marked :count messages as read.',
        'mark_spam' => 'Move to spam',
        // Item 11 — the bulk delete reports a COUNT because it may have kept some of the
        // selection back; "deleted" with no number would hide that.
        'delete_selected_done' => 'Moved one record to the trash.|Moved :count records to the trash.',
        'delete_selected_blocked' => 'Part of the selection was kept back',
        'mark_not_spam' => 'Not spam',
        'reset_two_factor' => 'Reset two-factor authentication',
        'reset_two_factor_confirm' => 'This clears the user’s authenticator secret and recovery codes; they will set two-factor up again at their next sign-in. This is what somebody who lost their phone needs.',
        'reset_two_factor_done' => 'Reset two-factor authentication for :name.',
        'activate_selected' => 'Activate selected users',
        'activate_selected_done' => 'Activated one user.|Activated :count users.',
        'deactivate_selected' => 'Deactivate selected users',
        'deactivate_selected_done' => 'Deactivated one user.|Deactivated :count users.',
        'own_account_skipped' => 'Your own account was left unchanged — deactivating yourself signs you out of this screen.',
        'edit' => 'Edit',
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

        // Item 55 — these were rendered as the raw English keys (`image`, `video`, `document`)
        // in a panel that is otherwise Persian, Arabic or English throughout.
        'type' => [
            'image' => 'Image',
            'video' => 'Video',
            'document' => 'Document',
        ],
        'size' => 'Size',
        'size_kb' => ':size KB',
        'size_mb' => ':size MB',

        // Item 12.
        'usage' => [
            'heading' => 'Where this is used',
            'caveat' => 'Images placed inside body text (hero blocks and inline uploads) are not tracked and are not listed here.',
            'none' => 'Not attached to any record, and not the site logo.',
            'summary' => 'Used in one place:|Used in :count places:',
            'logo' => 'Site logo',
            'trashed' => 'In the trash',
            'more' => '…and one more.|…and :count more.',
        ],
        'replace' => [
            'action' => 'Replace file',
            'heading' => 'Replace this file everywhere it is used',
            'submit' => 'Replace file',
            'done' => 'File replaced',
            'locked_hint' => 'To swap the file, use “Replace file” at the top of the page. It shows where the file is used first.',
            'unused' => 'This file is not attached to any record.',
            'used' => 'This file is used by :usage, and all of them will show the new file straight away, published pages included.',
            'logo' => 'It is also the site logo.',
            'previous_deleted' => 'The current file is deleted and cannot be restored.',
            'video' => 'The duration and dimensions are cleared and read again from the new file where the server can; check the poster frame, which is kept.',
        ],
        'validation' => [
            'file_extension' => 'A :type must be one of: :extensions.',
            'file_type' => 'This file’s content (:mime) is not an accepted :type.',
            'type_mismatch' => 'A :type cannot hold this file (:mime). Choose the type that matches the file, or upload a different file.',
        ],
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
            'media_featured' => 'This is the featured image of one record (drafts and trashed ones count too); deleting it would leave it with none. Set a different featured image on that record first.|This is the featured image of :count records, drafts and trashed ones included; deleting it would leave them with none. Set a different featured image on those records first.',
            'category_primary' => 'This is the primary category of one article and decides its canonical URL and breadcrumb trail. Change the primary category on that article first — check the Deleted filter on the article list too.|This is the primary category of :count articles and decides their canonical URL and breadcrumb trail. Change the primary category on those articles first — check the Deleted filter on the article list too.',
            // The site logo lives as an id inside a Setting document, not as an attachment row, so
            // nothing else here can see it — and losing it drops `logo` from the Organization JSON-LD.
            'media_logo' => 'This is the site logo; deleting it removes `logo` from the Organization structured data. Choose a different logo in Settings first.',
            // Refused only for PERMANENT deletion: something in the trash still needs this, so
            // destroying it would make that record come back wrong rather than not come back.
            'restorable_dependents' => 'One record in the trash still depends on this, so destroying it would make that record come back incomplete. Restore or permanently delete it first.|:count records in the trash still depend on this, so destroying it would make them come back incomplete. Restore or permanently delete those first.',
        ],
        /*
         * Item 56 — each label carries its own number and its own plural: "1 article", not
         * "1 article(s)". Two forms, singular|plural, chosen by trans_choice().
         */
        'label' => [
            'articles' => ':count article|:count articles',
            'child_categories' => ':count child category|:count child categories',
            'navigation_links' => ':count menu or slide link|:count menu or slide links',
            'menu_children' => ':count menu child item|:count menu child items',
            'attached_to_content' => ':count article attachment|:count article attachments',
            'attached_to_page' => ':count page attachment|:count page attachments',
            'attached_to_gallery' => ':count gallery item — the gallery will show one image fewer|:count gallery items — the gallery will show that many fewer images',
            'attached_to_slide' => ':count slide attachment|:count slide attachments',
            'attached_to_other' => ':count other attachment|:count other attachments',
        ],
        'in_use' => 'This record is in use: :usage. Deleting it takes it away from them.',
    ],

    /*
    | Item 10 — the trash itself.
    */
    'trash' => [
        'filter' => 'Deleted',
        'cascade' => 'Deleting this also moves one item below it to the trash; restoring it brings that back.|Deleting this also moves :count items below it to the trash; restoring it brings them back.',
        'only_trashed' => 'Deleted only',
        'without_trashed' => 'Excluding deleted',
        'with_trashed' => 'All, including deleted',
        'pruned' => 'Permanently removed one record that had been in the trash longer than the retention period (:days days).|Permanently removed :count records that had been in the trash longer than the retention period (:days days).',
        'nothing_pruned' => 'Nothing in the trash had reached the retention limit.',
        'prune_blocked' => 'Kept one record that is still in use; it stays in the trash.|Kept :count records that are still in use; they stay in the trash.',
        // A different fact from `prune_blocked`: that was a decision, this was a surprise.
        'prune_failed' => 'One record could not be destroyed and stays in the trash; the error is listed above.|:count records could not be destroyed and stay in the trash; each error is listed above.',
    ],

    'system' => [
        'version' => 'Version',
        'changelog' => 'Changelog',
        'recent_changes' => 'Recent changes',
        'installed_at' => 'Installed at',
        'about' => 'About this system',
        'no_changelog' => 'No releases recorded yet.',

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
            'scheduler' => 'Scheduler (cron)',
            'queue' => 'Queue worker',
            'cache_store' => 'Cache store',
            'running' => 'Running',
            'stopped' => 'Stopped',
            'unknown' => 'Unknown',
            'last_seen' => 'Last reported :ago',
            'queue_lag' => 'Queue lag: :seconds second(s)',
            'scheduler_stopped_help' => 'Laravel’s scheduler is not running, so scheduled publishing is not happening either. See the crontab in docs/deployment.md.',
            'queue_stopped_help' => 'No worker is consuming the queue, so translations, search indexing and webhooks are piling up. Start the queue:work service.',
            'queue_unknown_help' => 'Nothing is dispatched while the scheduler is stopped, so the queue cannot be measured. Fix cron first.',
            'cache_tags_ok' => 'Tagging supported — cache invalidation is targeted.',
            'cache_tags_missing' => 'This store has no tag support, so every publish flushes the whole cache, rate-limiter counters included. Use Redis in production.',
            // The web process and the cron process disagree about which cache they use. Each
            // writes where the other never reads, so an editor's publish clears a store the
            // API does not consult and the site serves stale pages indefinitely.
            'cache_store_mismatch' => 'Configuration mismatch: the scheduler uses the “:store” store and the web process uses another. Cache invalidation never reaches the site. Align the environment of both.',
            'contact_protection' => 'Contact form protection',
            'contact_no_submissions' => 'Nothing has been submitted yet, so the honeypot cannot be confirmed working.',
            'contact_honeypot_missing' => 'Recent submissions arrive without the honeypot field, so the frontend is not sending it and this defence is off. Align the field name with the frontend.',
        ],
    ],

    'slide' => [
        // Requirement 7.6 — the first active slide's image is preloaded. This was the English word
        // `preload`, hardcoded in the slides table.
        'preloaded' => 'Preloaded on the homepage',
    ],

    /*
    | Item 35 — someone else saved the record while this form was open.
    */
    'concurrency' => [
        'title' => 'This record was changed while you were editing it',
        'body' => ':who saved it :when. Saving now would erase their changes. Reload to see what changed, or save over it if you are sure.',
        'reload' => 'Reload',
        'overwrite' => 'Save over their changes',
        // No audit row explains the change: an unaudited record, a quiet save or a background job.
        'background' => 'A background process',
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

        // Item 55 — the event filter and badge showed the raw event names in English. `restored`
        // and `destroyed` are new with the trash (items 10/11); without labels they appeared in the
        // log with no filter option to find them by.
        'context' => [
            'ip' => 'IP address',
            'user_agent' => 'Browser',
            'reason' => 'Reason',
            'via_cookie' => 'Signed in',
        ],
        'via_cookie_yes' => 'From a “Remember me” cookie',
        'via_cookie_no' => 'By entering the password',
        'failure_reason' => [
            'wrong_password' => 'Wrong password',
            'account_disabled' => 'Account disabled (the password was correct)',
            'wrong_second_factor' => 'Wrong two-factor code (the password was correct)',
        ],
        'events' => [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Moved to trash',
            'restored' => 'Restored',
            'destroyed' => 'Permanently deleted',
            'published' => 'Published',
            'archived' => 'Archived',
            'denied' => 'Access denied',
            'login' => 'Signed in',
            'login_failed' => 'Failed sign-in',
        ],
    ],

    'version' => [
        'history' => 'Version history',
        'select' => 'Choose a version',
        'empty' => 'No versions recorded yet.',
        'restore_warning' => 'The current content will be replaced by the selected version. The current state is itself saved as a new version, so this is reversible.',
        'restored' => 'Restored version :number.',
        'not_found' => 'The selected version could not be found.',
        'keep_notice' => 'Only the most recent version is kept.|Only the most recent :count versions are kept.',
        // cms.versions.keep <= 0 means no pruning at all (HasContentVersions::pruneVersions).
        'keep_all' => 'Every version is kept.',
    ],

    'contact' => [
        'received' => 'Your message has been received. Thank you for getting in touch.',

        // Why a submission was flagged (item 16). `manual` is set by an editor, the rest
        // by App\Services\Contact\SpamInspector.
        'spam_reason' => [
            'honeypot' => 'Hidden field was filled',
            'too_fast' => 'Submitted instantly',
            'missing_timing' => 'No form timing value',
            'manual' => 'Flagged by an editor',
        ],
    ],

    // Item 15 — the form builder and the submission payload it produces.
    'forms' => [
        'form' => 'Form',
        'submission' => 'Submission',
        'summary' => 'Summary',
        'section_details' => 'Form',
        'section_fields' => 'Fields',
        'contact_locked' => 'This is the built-in contact form. Its fields are the fixed contract of POST /api/v1/contact, so only their wording and order can change here, and it is switched on and off with the contact module. To collect different fields, create a new form.',
        'key' => 'Key',
        'key_help' => 'What the frontend fetches this form by, e.g. contact. Lowercase letters, digits and hyphens; do not change it once a frontend uses it.',
        'title' => 'Title',
        'is_active' => 'Active',
        'is_active_help' => 'An inactive form is not served by the API and refuses submissions.',
        'add_field' => 'Add field',
        'field_key' => 'Field key',
        'field_key_help' => 'Lowercase letters, digits and underscores. Submissions are stored under this key.',
        'key_reserved' => 'This name is used by the spam checks and cannot be a field key.',
        'field_type' => 'Type',
        'max_length' => 'Maximum length',
        'max_length_help' => 'Leave empty for the default (:default characters); at most :ceiling.',
        'required' => 'Required',
        'label' => 'Label',
        'placeholder' => 'Placeholder',
        'help' => 'Help text',
        'options' => 'Options',
        'add_option' => 'Add option',
        'option_value' => 'Value',
        'option_value_help' => 'Stored with the submission; keep it stable.',
        'option_label' => 'Label (:locale)',
        'fields_count' => 'Fields',
        'submissions_count' => 'Submissions',
        'updated_at' => 'Last updated',
        'delete_blocked' => 'This form cannot be deleted: it is the built-in contact form or it has submissions. Deactivate it instead.',
        'value_yes' => 'Yes',
        'value_no' => 'No',
        'type' => [
            'text' => 'Text',
            'email' => 'Email',
            'tel' => 'Phone number',
            'textarea' => 'Long text',
            'select' => 'Drop-down list',
            'checkbox' => 'Checkbox',
        ],
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

    'editorial_calendar' => [
        'title' => 'Editorial calendar',
        'intro' => 'Articles, pages and galleries by the day they go out. Times are in :timezone.',
        'previous' => 'Previous month',
        'next' => 'Next month',
        'today' => 'This month',
        'state' => [
            'scheduled' => 'Scheduled — goes live on its own',
            'unapproved' => 'Not published yet — will not go live until approved',
            'missed' => 'Missed — its time today has passed and it is not on the site',
            'published' => 'Published',
        ],
        'more' => 'One more|:count more',
        'empty' => 'Nothing is scheduled or published in this month.',
        'unavailable' => 'The calendar configured for this language cannot be shown as a month grid.',
    ],

    'settings' => [
        'title' => 'Settings',
        'save' => 'Save settings',
        'saved' => 'Settings saved.',
        'validation_failed' => 'Settings were not saved',
        'validation_failed_body' => 'Some fields are invalid and nothing was saved. Check these tabs: :tabs',

        'general' => [
            'tab' => 'General',
            'identity' => 'Site identity',
            'identity_help' => 'The site name is published to the frontend over the API, used as the Organization name in structured data, and shown in this panel’s own header.',
            'site_name' => 'Site name (:locale)',
            'social' => 'Social profiles',
            'social_help' => 'Full https URLs. These are published to the frontend and emitted in the sameAs property of the structured data.',
            'social_links' => 'Profile URLs',
            'social_add' => 'Add a URL',
        ],

        'organisation' => [
            'section' => 'Organisation identity (structured data)',
            'section_help' => 'These describe the site’s publisher to search engines and appear as the publisher in every article’s JSON-LD. All optional; anything left blank is not emitted at all.',
            'type' => 'Organisation type',
            'type_help' => 'A news agency, a company, a government body and a university are four different entities to a search engine. Leave it as “Organisation (general)” if unsure.',
            'logo' => 'Organisation logo',
            'logo_help' => 'The most valuable field here. Google uses the publisher logo for article rich results and for a brand’s knowledge panel.',
            'legal_name' => 'Legal (registered) name',
            'legal_name_help' => 'If it differs from the site’s trading name.',
            'founding_date' => 'Founding date',
            'founding_date_help' => 'As YYYY-MM-DD, because schema.org expects an ISO date.',
            'alternate_name' => 'Alternative name (:locale)',
            'description' => 'Organisation description (:locale)',
        ],
        'discovery' => [
            'tab' => 'Analytics & verification',
            'analytics' => 'Analytics',
            'analytics_help' => 'These ids are only stored and handed to the frontend to render. They are never loaded by this panel — the backoffice deliberately makes no external requests.',
            'ga' => 'Google Analytics id',
            'gtm' => 'Google Tag Manager id',
            'verification' => 'Site ownership verification',
            'verification_help' => 'Verification tokens for the frontend to place in a meta tag. They belong to the public site, not to this host.',
            'gsc' => 'Google Search Console token',
            'bing' => 'Bing Webmaster token',
        ],

        'contact' => [
            'tab' => 'Contact',
            'details' => 'Contact details',
            'details_help' => 'These values are served to the frontend over the API for it to render on its contact page.',
            'phone' => 'Phone',
            'email' => 'Email',
            'address' => 'Address (:locale)',
            'office_hours' => 'Office hours (:locale)',
            'map' => 'Map location',
            'map_help' => 'Fill both or neither. With both, LocalBusiness structured data is emitted.',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'form_labels' => 'Contact form labels',
            'form_labels_help' => 'The keys are consumed by the frontend, so use the keys it expects (for example name, email, message).',
            'form_labels_locale' => 'Labels (:locale)',
            'form_labels_key' => 'Key',
            'form_labels_value' => 'Label',
        ],

        'maintenance' => [
            'tab' => 'Maintenance mode',
            'section' => 'Maintenance mode',
            'section_help' => 'This switch affects the Delivery API only and leaves this panel reachable; it is not the same as “php artisan down”, which takes the whole application offline.',
            'enabled' => 'Enable maintenance mode',
            'enabled_help' => 'While on, the Delivery API answers every request with 503 and a Retry-After header, so the public site goes down. 503 tells a crawler to come back rather than to de-index the page.',
            'active_title' => 'The site is in maintenance mode',
            'active_body' => 'Until this switch is turned off, the Delivery API answers the frontend with 503 and the public site is unavailable.',
        ],

        'ai' => [
            'tab' => 'AI translation',
            'section' => 'AI translation',
            'section_help' => 'Machine-translate text fields from the source locale (Persian) into other locales. The result still needs a human review before it is published.',
            'enabled' => 'Enable AI translation',
            'enabled_help' => 'When off, the "Translate with AI" action is hidden from the translation workflow.',
            'provider' => 'Translation service',
            'provider_help' => 'All three speak the same (OpenAI) protocol. Each service stores its own key and model, so switching between them does not mean entering a key again.',
            'model' => 'Model',
            'model_help' => 'Leave blank to use :provider’s default, :model. Note that model ids are not portable between services — copy the id from that service’s own console.',
            'api_key' => ':provider API key',
            'api_key_help' => 'Stored in the database, not in the env file. Required to enable AI translation.',
            'api_key_help_set' => 'A key is stored. Enter a new one to replace it; leaving it blank keeps the current key.',
            'api_key_docs' => 'Get a key: :url',
            'api_key_clear' => 'Remove the stored key',
            'api_key_cleared' => 'The stored key was removed.',
        ],
    ],

    /*
     * The translation providers (App\Enums\AiProvider). The descriptions are what
     * make the choice meaningful to an administrator, so each one says what the
     * service is rather than just naming it.
     */
    'organisation_type' => [
        'Organization' => 'Organisation (general)',
        'NewsMediaOrganization' => 'News media organisation',
        'Corporation' => 'Corporation',
        'GovernmentOrganization' => 'Government organisation',
        'EducationalOrganization' => 'Educational organisation',
        'NGO' => 'NGO',
        'LocalBusiness' => 'Local business',
    ],

    'ai_provider' => [
        'openrouter' => 'OpenRouter',
        'openrouter_help' => 'International gateway with the widest model catalogue. Billed in foreign currency, and reaching it from Iran usually needs network configuration.',
        'gapgpt' => 'GapGPT',
        'gapgpt_help' => 'Iranian OpenAI-compatible gateway. Take the key and the model list from GapGPT’s own console.',
        'chatqt' => 'ChatQT',
        'chatqt_help' => 'Iranian OpenAI-compatible gateway with a rial wallet and a developer console. Its documentation states it is reachable from Iran without a VPN.',
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
            'missing_key' => 'No API key is configured for the selected translation service. Enter it on the Settings page.',
            'request_failed' => 'The translation service could not be reached. Please try again shortly.',
            'empty_source' => 'There is no source text to translate. Complete the Persian content first.',
            'already_reviewed' => 'This locale has already been reviewed; automatic translation was skipped to avoid overwriting human work.',
            'source_too_long' => 'This record is too long to machine-translate in one run. Split it into shorter records, or raise the per-record request limit in the configuration.',
        ],
    ],
];
