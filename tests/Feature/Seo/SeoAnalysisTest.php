<?php

declare(strict_types=1);

use App\Contracts\HasSeoMetadata;
use App\Models\Category;
use App\Models\Content;
use App\Models\Page;
use App\Services\Seo\SeoAnalyser;
use App\Services\Seo\SeoAnalysisSubject;
use App\Services\Seo\SerpPreviewBuilder;
use App\Support\ScriptFolding;

/**
 * Focus-keyphrase analysis and the search-result preview (Requirement 7.1).
 *
 * Asserted against the SERVICE and the MODEL rather than against rendered HTML,
 * because that is where the behaviour is: the panel renders what the model reports,
 * and the Delivery API serves the same call. A test against markup would pass while
 * the two disagreed.
 */
$doc = function (array $blocks): array {
    return ['type' => 'doc', 'content' => $blocks];
};

$paragraph = fn (string $text): array => [
    'type' => 'paragraph',
    'content' => [['type' => 'text', 'text' => $text]],
];

$heading = fn (string $text, int $level = 2): array => [
    'type' => 'heading',
    'attrs' => ['level' => $level],
    'content' => [['type' => 'text', 'text' => $text]],
];

// ---------------------------------------------------------------------------
// Persian/Arabic folding — the part that decides whether any check can pass
// ---------------------------------------------------------------------------

it('matches the same phrase typed on a Persian and an Arabic keyboard', function (): void {
    /*
     * The failure this prevents: an editor types the keyphrase with the Persian yeh
     * and the title with the Arabic one (or the text was pasted from an Arabic
     * source). Byte-comparing them reports the keyphrase missing from the editor's
     * own title, which is how a whole panel gets ignored.
     */
    expect(ScriptFolding::contains('راهنماي خريد لپتاپ', 'راهنمای خرید'))->toBeTrue()
        ->and(ScriptFolding::contains('الشركة الكبرى', 'الشركة'))->toBeTrue();
});

it('sees through ZWNJ, diacritics and Persian digits', function (): void {
    expect(ScriptFolding::contains("به\u{200C}روزرسانی سامانه", 'به روزرسانی'))->toBeTrue()
        ->and(ScriptFolding::contains('مُحَمَّد', 'محمد'))->toBeTrue()
        ->and(ScriptFolding::contains('بودجهٔ ۱۴۰۴', '1404'))->toBeTrue();
});

it('does not match a phrase that is simply absent', function (): void {
    // The folding is lenient, not indiscriminate. A check that always passes is
    // worse than no check.
    expect(ScriptFolding::contains('گزارش جلسه هیئت مدیره', 'راهنمای خرید'))->toBeFalse()
        ->and(ScriptFolding::contains('', 'راهنما'))->toBeFalse()
        ->and(ScriptFolding::contains('راهنما', ''))->toBeFalse();
});

it('counts a ZWNJ-joined compound as two words, like the slug generator does', function (): void {
    expect(ScriptFolding::wordCount("به\u{200C}روزرسانی سامانه"))->toBe(3);
});

// ---------------------------------------------------------------------------
// The checks
// ---------------------------------------------------------------------------

it('passes every keyphrase check when the phrase is genuinely placed well', function () use ($doc, $paragraph, $heading): void {
    $body = $doc([
        $paragraph('راهنمای خرید لپتاپ '.str_repeat('متن نمونه برای رسیدن به طول کافی ', 40)),
        $heading('راهنمای خرید چه چیزهایی را پوشش میدهد؟'),
        $paragraph(str_repeat('پاسخ کوتاه و روشن ', 20).' راهنمای خرید'),
    ]);

    $analysis = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa',
        keyphrase: 'راهنمای خرید',
        metaTitle: 'راهنمای خرید لپتاپ در ۱۴۰۴',
        metaDescription: 'راهنمای خرید لپتاپ با بودجهٔ مشخص.',
        slug: 'راهنمای-خرید-لپتاپ',
        body: $body,
        hasBody: true,
    ));

    $statuses = collect($analysis['checks'])->pluck('status', 'id');

    expect($statuses['keyphrase_in_title'])->toBe(SeoAnalyser::STATUS_PASS)
        ->and($statuses['keyphrase_in_description'])->toBe(SeoAnalyser::STATUS_PASS)
        ->and($statuses['keyphrase_in_slug'])->toBe(SeoAnalyser::STATUS_PASS)
        ->and($statuses['keyphrase_in_opening'])->toBe(SeoAnalyser::STATUS_PASS)
        ->and($statuses['keyphrase_in_heading'])->toBe(SeoAnalyser::STATUS_PASS)
        ->and($analysis['band'])->toBe(SeoAnalyser::BAND_GOOD);
});

it('warns for each place the keyphrase is missing from', function () use ($doc, $paragraph): void {
    $analysis = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa',
        keyphrase: 'راهنمای خرید',
        metaTitle: 'گزارش جلسه',
        metaDescription: 'خلاصهٔ جلسه.',
        slug: 'gozaresh-jalase',
        body: $doc([$paragraph(str_repeat('متن بیارتباط ', 30))]),
        hasBody: true,
    ));

    $statuses = collect($analysis['checks'])->pluck('status', 'id');

    expect($statuses['keyphrase_in_title'])->toBe(SeoAnalyser::STATUS_WARN)
        ->and($statuses['keyphrase_in_description'])->toBe(SeoAnalyser::STATUS_WARN)
        ->and($statuses['keyphrase_in_slug'])->toBe(SeoAnalyser::STATUS_WARN)
        ->and($statuses['keyphrase_in_heading'])->toBe(SeoAnalyser::STATUS_WARN)
        ->and($analysis['band'])->toBe(SeoAnalyser::BAND_POOR);
});

it('omits every keyphrase check when no keyphrase is set', function () use ($doc, $paragraph): void {
    /*
     * Omitted, not failed. Scoring an article 0/6 for a field the editor has not
     * filled in yet says nothing about the article, and it would make the score
     * unreadable for the (legitimate) case of a news flash with no target phrase.
     */
    $analysis = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa',
        keyphrase: '',
        metaTitle: 'گزارش جلسه',
        metaDescription: 'خلاصه.',
        slug: 'gozaresh',
        body: $doc([$paragraph(str_repeat('متن ', 400))]),
        hasBody: true,
    ));

    $ids = collect($analysis['checks'])->pluck('id');

    expect($ids)->not->toContain('keyphrase_in_title')
        ->and($ids)->toContain('content_length')
        ->and($ids)->toContain('heading_distribution');
});

it('scores nothing for a model with neither prose nor a keyphrase', function (): void {
    // A Category. Reporting 0% would be a claim about content that was never read.
    $analysis = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa',
        keyphrase: '',
        metaTitle: 'اخبار',
        metaDescription: 'دستهٔ اخبار.',
        slug: 'akhbar',
    ));

    expect($analysis['checks'])->toBe([])
        ->and($analysis['score'])->toBeNull()
        ->and($analysis['band'])->toBeNull();
});

it('flags a keyphrase stuffed past the density band', function () use ($doc, $paragraph): void {
    $analysis = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa',
        keyphrase: 'لپتاپ',
        metaTitle: 'لپتاپ',
        metaDescription: 'لپتاپ',
        slug: 'laptop',
        body: $doc([$paragraph(str_repeat('لپتاپ ', 100))]),
        hasBody: true,
    ));

    $density = collect($analysis['checks'])->firstWhere('id', 'keyphrase_density');

    expect($density['status'])->toBe(SeoAnalyser::STATUS_WARN)
        ->and($density['value'])->toBe('100.0%');
});

it('lets a short record off the heading requirement and holds a long one to it', function () use ($doc, $paragraph, $heading): void {
    $short = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa', keyphrase: '', metaTitle: 'خبر', metaDescription: 'خبر', slug: 'khabar',
        body: $doc([$paragraph(str_repeat('واژه ', 100))]), hasBody: true,
    ));

    $long = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa', keyphrase: '', metaTitle: 'خبر', metaDescription: 'خبر', slug: 'khabar',
        body: $doc([$paragraph(str_repeat('واژه ', 500))]), hasBody: true,
    ));

    $withHeadings = app(SeoAnalyser::class)->analyse(new SeoAnalysisSubject(
        locale: 'fa', keyphrase: '', metaTitle: 'خبر', metaDescription: 'خبر', slug: 'khabar',
        body: $doc([
            $heading('بخش اول'),
            $paragraph(str_repeat('واژه ', 250)),
            $heading('بخش دوم'),
            $paragraph(str_repeat('واژه ', 250)),
        ]),
        hasBody: true,
    ));

    expect(collect($short['checks'])->firstWhere('id', 'heading_distribution')['status'])
        ->toBe(SeoAnalyser::STATUS_PASS)
        ->and(collect($long['checks'])->firstWhere('id', 'heading_distribution')['status'])
        ->toBe(SeoAnalyser::STATUS_WARN)
        ->and(collect($withHeadings['checks'])->firstWhere('id', 'heading_distribution')['status'])
        ->toBe(SeoAnalyser::STATUS_PASS);
});

it('reads the keyphrase from the model per locale, with no fallback', function (): void {
    /*
     * Deliberately NOT falling back to the source locale: the English article targets
     * an English phrase, and measuring the English copy against the Persian keyphrase
     * would report five failures the editor cannot act on.
     */
    $content = Content::factory()->create([
        'focus_keyphrase' => ['fa' => 'راهنمای خرید'],
    ]);

    expect($content->focusKeyphraseFor('fa'))->toBe('راهنمای خرید')
        ->and($content->focusKeyphraseFor('en'))->toBe('')
        ->and($content->seoAnalysisFor('en')['keyphrase'])->toBe('');
});

it('analyses a Page through its blocks column, not a body it does not have', function (): void {
    // Content calls its prose `body` and Page calls it `blocks`. Reaching for either
    // unconditionally throws AttributeIsNotTranslatable on the other model.
    $page = Page::factory()->create([
        'focus_keyphrase' => ['fa' => 'درباره ما'],
        'blocks' => ['fa' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'درباره ما و تاریخ شرکت.']],
        ]]]],
    ]);

    $ids = collect($page->seoAnalysisFor('fa')['checks'])->pluck('id');

    expect($ids)->toContain('keyphrase_in_opening')
        ->and($ids)->toContain('content_length');
});

// ---------------------------------------------------------------------------
// The search-result preview
// ---------------------------------------------------------------------------

it('cuts the preview at the advisory limit and reports what was lost', function (): void {
    $title = str_repeat('واژه ', 30); // Far past 60 characters.

    $content = Content::factory()->published()->create([
        'meta_title' => ['fa' => $title],
    ]);

    $preview = app(SerpPreviewBuilder::class)->for($content, 'fa');

    expect(mb_strlen($preview['title']))->toBeLessThanOrEqual(HasSeoMetadata::META_TITLE_ADVISORY_LIMIT)
        // The overflow is what makes the limit an argument rather than a number.
        ->and($preview['title_overflow'])->not->toBe('')
        // Nothing is invented and nothing silently disappears.
        ->and(trim($preview['title'].' '.$preview['title_overflow']))->toBe(trim($title));
});

it('cuts on a word boundary rather than mid-word', function (): void {
    $title = 'یک عنوان فارسی نسبتاً بلند که از محدودهٔ توصیه شدهٔ شصت نویسه فراتر میرود';

    $content = Content::factory()->published()->create(['meta_title' => ['fa' => $title]]);

    $preview = app(SerpPreviewBuilder::class)->for($content, 'fa');

    // The character immediately after the kept part, in the ORIGINAL string, is the
    // space the cut landed on. A cut that appears to slice a Persian word in half
    // reads as a rendering bug rather than as advice.
    $nextCharacter = mb_substr($title, mb_strlen($preview['title']), 1);

    expect($nextCharacter)->toBe(' ')
        ->and($preview['title'])->not->toEndWith(' ')
        ->and($preview['title_overflow'])->not->toStartWith(' ');
});

it('leaves a title inside the limit untouched', function (): void {
    $content = Content::factory()->published()->create([
        'meta_title' => ['fa' => 'عنوانی کوتاه'],
    ]);

    $preview = app(SerpPreviewBuilder::class)->for($content, 'fa');

    expect($preview['title'])->toBe('عنوانی کوتاه')
        ->and($preview['title_overflow'])->toBe('');
});

it('previews the homepage at the locale root, not under its slug', function (): void {
    /*
     * Stage 2 made the homepage own /{locale}. A preview built from the slug would
     * advertise /fa/{slug} — a URL the sitemap does not list and the frontend does not
     * serve — which is precisely the disagreement UrlBuilder exists to prevent.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'home-page']]);

    $preview = app(SerpPreviewBuilder::class)->for($home, 'fa');

    expect($preview['url'])->toEndWith('/fa')
        ->and($preview['url'])->not->toContain('home-page');
});

it('reports a locale with no slug as having no result at all', function (): void {
    $content = Content::factory()->published()->create(['slug' => ['fa' => 'یک-خبر']]);

    // null rather than a guessed path: a canonical that resolves elsewhere is worse
    // than none.
    expect(app(SerpPreviewBuilder::class)->for($content, 'en')['url'])->toBeNull();
});

it('says when the record will not be indexed, whatever the editor typed', function (): void {
    // A draft. robotsMetaFor() noindexes it on its own initiative, so without this the
    // editor would polish a snippet for a result that is never shown.
    $draft = Content::factory()->create(['meta_title' => ['fa' => 'عنوان']]);

    $preview = app(SerpPreviewBuilder::class)->for($draft, 'fa');

    expect($preview['indexable'])->toBeFalse()
        ->and($preview['robots'])->toBe('noindex, nofollow');
});

it('previews a category, which has no publish workflow and is always indexable', function (): void {
    $category = Category::factory()->create(['name' => ['fa' => 'اخبار شهری']]);

    $preview = app(SerpPreviewBuilder::class)->for($category, 'fa');

    expect($preview['indexable'])->toBeTrue()
        ->and($preview['title'])->toBe('اخبار شهری')
        ->and($preview['url'])->toContain('/fa/category/');
});
