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
    ],

    'media_role' => [
        'featured' => 'Featured image',
        'inline' => 'Inline',
        'gallery' => 'Gallery',
        'og_image' => 'Social share image',
    ],

    'validation' => [
        'featured_image_required' => 'A featured image is required.',
        'slides_max' => 'You may have at most :max slides.',
        'slug_unique' => 'This slug is already in use for the :locale locale.',
        'alt_text_required' => 'Alternative text is required for this image.',
        'invalid_transition' => 'Moving from ":from" to ":to" is not allowed.',
        'redirect_loop' => 'This redirect points back to itself and would create a loop.',
    ],

];
