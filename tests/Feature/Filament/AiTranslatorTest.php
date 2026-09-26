<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;
use App\Filament\Pages\TranslationReview;
use App\Filament\RichContent\Blocks\CalloutBlock;
use App\Filament\Schemas\TranslatableTabs;
use App\Jobs\TranslateRecordJob;
use App\Models\Content;
use App\Models\Setting;
use App\Models\User;
use App\Services\Translation\AiTranslationException;
use App\Services\Translation\AiTranslator;
use App\Support\TipTap;
use Carbon\CarbonInterval;
use Filament\Actions\Testing\TestAction;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirement 5.3 — machine translation via OpenRouter. Every test mocks the
 * outbound call with Http::fake(); none performs a live request.
 */
function enableAiTranslation(string $key = 'sk-or-test-key'): void
{
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Setting::putSecret(Setting::OPENROUTER_API_KEY, $key);
}

/*
 * The retry policy waits between attempts. Faked so the suite asserts the policy
 * instead of serving it — a real 1s+2s backoff on every retryable-failure test is
 * three seconds of nothing, paid on every run, and a sleep the tests cannot see is a
 * sleep they cannot assert either.
 */
beforeEach(function (): void {
    Sleep::fake();
});

/**
 * Which batch a faked request carries.
 *
 * Every call the translator makes is now a batch — the plain fields in one request,
 * the body's prose leaves in one request per chunk — so the user message alone no
 * longer distinguishes them. The system prompt does: it tells the model WHAT the
 * strings are, and that distinction is real rather than a test hook. A meta
 * description is standalone metadata with a length budget; a prose leaf is a fragment
 * lifted mid-sentence out of a document and must come back as a fragment.
 */
function isFieldsBatch(Request $request): bool
{
    foreach ($request->data()['messages'] ?? [] as $message) {
        if (($message['role'] ?? null) === 'system') {
            return str_contains((string) ($message['content'] ?? ''), 'metadata fields');
        }
    }

    return false;
}

/**
 * The JSON array of strings a faked request is asking to have translated.
 *
 * @return list<string>
 */
function batchSegments(Request $request): array
{
    foreach ($request->data()['messages'] ?? [] as $message) {
        if (($message['role'] ?? null) !== 'user') {
            continue;
        }

        $decoded = json_decode((string) ($message['content'] ?? ''), true);

        if (is_array($decoded) && array_is_list($decoded)) {
            return array_map('strval', $decoded);
        }
    }

    return [];
}

/**
 * Reply with the object protocol the translator asks for.
 *
 * `{"translations": [...]}` rather than a bare array, because that is the shape
 * OpenAI-compatible JSON mode is defined over — a top-level array is not valid in
 * that mode. A separate test covers the bare-array reply that models ignoring
 * response_format send back.
 *
 * @param  list<string>  $translations
 */
function openRouterBatchReply(array $translations): PromiseInterface
{
    return Http::response([
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => (string) json_encode(['translations' => $translations], JSON_UNESCAPED_UNICODE),
            ],
        ]],
    ], 200);
}

/**
 * Fake OpenRouter for both batch kinds.
 *
 * Plain fields are answered with $translation for every element — which is exactly
 * what the previous one-request-per-field fake did, so the assertions built on it are
 * unchanged. Body prose is echoed through $segment (default: prefix "EN: ") so a test
 * can assert each leaf was replaced 1:1 while the structure is preserved.
 *
 * @param  callable(string): string|null  $segment
 */
function fakeOpenRouter(string $translation = 'Translated text', ?callable $segment = null): void
{
    $segment ??= static fn (string $s): string => 'EN: '.$s;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use ($translation, $segment): PromiseInterface {
            $segments = batchSegments($request);

            $translated = isFieldsBatch($request)
                ? array_fill(0, max(1, count($segments)), $translation)
                : array_map(static fn (string $element): string => $segment($element), $segments);

            return openRouterBatchReply(array_values($translated));
        },
    ]);
}

it('translates the source locale into a target locale and lands at ai_translated', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Persian first CMS');

    $content = Content::factory()->create(['title' => ['fa' => 'سی‌ام‌اس فارسی']]);

    // No English yet, so the row starts as not_translated.
    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated);

    $result = app(AiTranslator::class)->translate($content, 'en');

    $content->refresh();

    expect($result->locale)->toBe('en')
        ->and($result->fieldCount())->toBeGreaterThan(0)
        ->and($content->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Persian first CMS')
        ->and($content->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'openrouter.ai')
        && $request->hasHeader('Authorization', 'Bearer sk-or-test-key'));
});

it('sends the configured model, defaulting to openai/gpt-4o-mini', function (): void {
    enableAiTranslation();
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'openai/gpt-4o-mini');
});

it('honours an admin-chosen model over the default', function (): void {
    enableAiTranslation();
    Setting::put(Setting::AI_TRANSLATION_MODEL, 'anthropic/claude-3.5-sonnet');
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(fn (Request $request): bool => ($request->data()['model'] ?? null) === 'anthropic/claude-3.5-sonnet');
});

it('translates the TipTap body while preserving its structure', function (): void {
    enableAiTranslation();
    // Prefix every prose leaf so we can recognise a translated string and still
    // tell the leaves apart from one another.
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    // The factory body has: paragraph text, heading (level 2) text, another
    // paragraph text, and a callout custom block in the real Filament shape —
    // type `customBlock`, block id in attrs.id, prose in attrs.config.
    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    /** @var array<string, mixed> $sourceBody */
    $sourceBody = $content->getTranslation('body', 'fa', useFallbackLocale: false);

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    // Same top-level shape and node order/types.
    expect($enBody['type'])->toBe('doc')
        ->and(array_map(fn (array $n): string => $n['type'], $enBody['content']))
        ->toBe(array_map(fn (array $n): string => $n['type'], $sourceBody['content']));

    // Structural attrs are byte-identical — including the block id, which must
    // keep pointing at the same block class.
    expect($enBody['content'][1]['attrs'])->toBe(['level' => 2])
        ->and($enBody['content'][3]['attrs']['config']['tone'])->toBe('info')
        ->and($enBody['content'][3]['attrs']['id'])->toBe('callout');

    // Every prose leaf was replaced by the faked translation of its source,
    // including the prose inside the custom block's config.
    expect($enBody['content'][0]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][0]['content'][0]['text'])
        ->and($enBody['content'][1]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][1]['content'][0]['text'])
        ->and($enBody['content'][2]['content'][0]['text'])
        ->toBe('EN: '.$sourceBody['content'][2]['content'][0]['text'])
        ->and($enBody['content'][3]['attrs']['config']['body'])
        ->toBe('EN: '.$sourceBody['content'][3]['attrs']['config']['body']);
});

it('rebuilds a custom block cached label and preview from the translated config', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    $sourceConfig = ['tone' => 'info', 'title' => 'عنوان بلوک', 'body' => 'متن بلوک'];
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'customBlock',
                'attrs' => [
                    'config' => $sourceConfig,
                    'id' => 'callout',
                    'label' => CalloutBlock::getPreviewLabel($sourceConfig),
                    'preview' => base64_encode((string) CalloutBlock::toPreviewHtml($sourceConfig)),
                    'shouldApplyProseStylingToPreview' => false,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    /** @var array<string, mixed> $sourceBody */
    $sourceBody = $content->getTranslation('body', 'fa', useFallbackLocale: false);
    $sourceCallout = $sourceBody['content'][0]['attrs'];

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);
    $callout = $enBody['content'][0]['attrs'];

    /*
     * `label` and `preview` are SNAPSHOTS Filament derives from config at save
     * time. Translating config without rebuilding them would leave an editor
     * looking at the Persian preview above English content, and re-saving the
     * English document would write that stale Persian preview back as current.
     */
    $translatedBody = 'EN: '.$sourceCallout['config']['body'];

    expect($callout['config']['body'])->toBe($translatedBody)
        ->and($callout['preview'])->not->toBe($sourceCallout['preview'])
        ->and(base64_decode($callout['preview'], strict: true))->toContain($translatedBody)
        // Rebuilt through the block class, so it matches byte-for-byte what
        // CustomBlockAction writes when a human edits the block.
        ->and($callout['label'])->toBe(CalloutBlock::getPreviewLabel($callout['config']))
        ->and($callout['preview'])->toBe(base64_encode((string) CalloutBlock::toPreviewHtml($callout['config'])));
});

it('translates a body whose only prose lives in custom blocks', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // No paragraphs at all: every word in this document sits inside a block's
    // config. Under the old (imaginary) node shape nothing here was collected, so
    // the walk found zero prose and the block text stayed Persian for ever.
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'customBlock',
                'attrs' => [
                    'config' => ['tone' => 'warning', 'title' => 'توجه', 'body' => 'متن هشدار'],
                    'id' => 'callout',
                    'label' => 'هشدار: توجه',
                    'preview' => base64_encode('<div>متن هشدار</div>'),
                    'shouldApplyProseStylingToPreview' => false,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    $result = app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);
    $config = $enBody['content'][0]['attrs']['config'];

    expect($result->attributes)->toContain('body')
        ->and($config['title'])->toBe('EN: توجه')
        ->and($config['body'])->toBe('EN: متن هشدار')
        ->and($config['tone'])->toBe('warning');
});

it('preserves marks and structural block attrs while translating only prose', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    /*
     * A body with a marked (bold) text leaf and a hero block whose cta_url and
     * media_asset_id are structural and must survive byte-for-byte.
     *
     * The hero node is written the way Filament writes it — type `customBlock`,
     * id `hero`, fields in config. The earlier version of this test hand-built
     * `{"type":"hero","attrs":{...}}`, a node the editor cannot produce, so it
     * asserted that an unreachable code path worked.
     */
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'متن پررنگ',
                        'marks' => [['type' => 'bold']],
                    ],
                ],
            ],
            [
                'type' => 'customBlock',
                'attrs' => [
                    'config' => [
                        'heading' => 'سرتیتر',
                        'lead' => 'مقدمه',
                        'cta_label' => 'اقدام کنید',
                        'cta_url' => 'https://example.test/go',
                        'media_asset_id' => 42,
                    ],
                    'id' => 'hero',
                    'label' => 'قهرمان: سرتیتر',
                    'preview' => base64_encode('<section>سرتیتر</section>'),
                    'shouldApplyProseStylingToPreview' => false,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    $textNode = $enBody['content'][0]['content'][0];
    $hero = $enBody['content'][1];

    // The marked leaf: text translated, marks untouched.
    expect($textNode['text'])->toBe('EN: متن پررنگ')
        ->and($textNode['marks'])->toBe([['type' => 'bold']]);

    // Hero: prose config translated, structural config byte-identical, and the
    // node itself still a `customBlock` pointing at the hero block.
    expect($hero['type'])->toBe('customBlock')
        ->and($hero['attrs']['id'])->toBe('hero')
        ->and($hero['attrs']['config']['heading'])->toBe('EN: سرتیتر')
        ->and($hero['attrs']['config']['lead'])->toBe('EN: مقدمه')
        ->and($hero['attrs']['config']['cta_label'])->toBe('EN: اقدام کنید')
        ->and($hero['attrs']['config']['cta_url'])->toBe('https://example.test/go')
        ->and($hero['attrs']['config']['media_asset_id'])->toBe(42);
});

it('leaves a quote attribution untranslated while still indexing it', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'customBlock',
                'attrs' => [
                    'config' => [
                        'quote' => 'دانش، قدرت است.',
                        'attribution' => 'مریم احمدی',
                        'attribution_role' => 'وزیر بهداشت',
                    ],
                    'id' => 'quote',
                    'label' => 'دانش، قدرت است.',
                    'preview' => base64_encode('<blockquote>دانش، قدرت است.</blockquote>'),
                    'shouldApplyProseStylingToPreview' => true,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);
    $config = $enBody['content'][0]['attrs']['config'];

    /*
     * `attribution` is a person's NAME. A translation model does not translate a
     * name, it transliterates or "localises" it, which damages a factual
     * attribution and misattributes the quote. The role beside it IS a job title
     * and is translated. A human reviewer can still adjust the name by hand.
     */
    expect($config['quote'])->toBe('EN: دانش، قدرت است.')
        ->and($config['attribution_role'])->toBe('EN: وزیر بهداشت')
        ->and($config['attribution'])->toBe('مریم احمدی');

    // Excluded from TRANSLATION, not from SEARCH: looking up a speaker by name is
    // a real query, so the fa index still carries it.
    expect(TipTap::toPlainText($content->getTranslation('body', 'fa', useFallbackLocale: false)))
        ->toContain('مریم احمدی');
});

it('does not re-translate a locale a human already reviewed', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Should never be written');

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);

    // Establish known, human-owned English content, then sign it off.
    $content->setTranslation('title', 'en', 'Hand written English title');
    $content->setTranslation('body', 'en', [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hand written English body']]],
        ],
    ]);
    $content->saveQuietly();
    $content->markTranslationReviewed('en');

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);

    expect(fn () => app(AiTranslator::class)->translate($content->fresh(), 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.already_reviewed');

    // No HTTP call, and the reviewed content is untouched.
    Http::assertNothingSent();

    $fresh = $content->fresh();

    expect($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Hand written English title')
        ->and($fresh->getTranslation('body', 'en', useFallbackLocale: false)['content'][0]['content'][0]['text'])
        ->toBe('Hand written English body')
        ->and($fresh->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed);
});

it('drops an outdated locale out of sitemap eligibility when it is re-translated', function (): void {
    /*
     * Decision D-5: unreviewed machine output must never reach a sitemap.
     *
     * `outdated` IS sitemap-eligible — it was verified once, so serving slightly
     * stale text beats dropping the page. But the two guards used to disagree
     * about it: translate() refused only `reviewed`, so an outdated locale WAS
     * re-translated, while markTranslationAiTranslated() guarded on wasReviewed()
     * (true for `outdated` too) and left the status alone. The result was raw
     * machine output sitting in the sitemap under a status that claimed a human
     * had verified it, still credited to a named reviewer.
     */
    enableAiTranslation();
    fakeOpenRouter('Machine English title', static fn (string $s): string => 'EN: '.$s);

    $reviewer = User::factory()->admin()->create();
    $content = Content::factory()->multilingual()->create();

    $content->markTranslationReviewed('en', $reviewer->getKey());

    // The Persian source moves on, which flags the reviewed locale as outdated.
    $content->setTranslation('title', 'fa', 'عنوان تغییر یافته');
    $content->save();

    $content = $content->fresh();

    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::Outdated)
        ->and($content->isSitemapEligibleFor('en'))->toBeTrue();

    app(AiTranslator::class)->translate($content, 'en');

    $content = $content->fresh();
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated)
        // The point of the whole fix: machine text is out of the sitemap again.
        ->and($content->isSitemapEligibleFor('en'))->toBeFalse()
        ->and($content->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Machine English title')
        // Review provenance is cleared, so the backlog cannot credit a human for
        // text a machine wrote, and no stale hash claims to describe it.
        ->and($state->reviewed_by)->toBeNull()
        ->and($state->reviewed_at)->toBeNull()
        ->and($state->source_hash)->toBeNull();
});

it('offers the outdated-specific warning before re-translating a stale locale', function (): void {
    // The consequences of re-translating an outdated locale (sign-off discarded,
    // locale leaves its sitemap) have to be stated BEFORE the translator confirms.
    enableAiTranslation();

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');
    $content->setTranslation('title', 'fa', 'عنوان تغییر یافته');
    $content->save();

    $outdated = $content->translationStates()->where('locale', 'en')->firstOrFail();
    $untouched = $content->translationStates()->where('locale', 'ar')->firstOrFail();

    expect($outdated->status)->toBe(TranslationStatus::Outdated);

    // Still actionable: refreshing a stale translation is the main reason to
    // re-run the machine.
    $outdatedModal = Livewire::test(TranslationReview::class)
        ->assertActionVisible(TestAction::make('translateAi')->table($outdated))
        ->mountAction(TestAction::make('translateAi')->table($outdated))
        ->instance()
        ->getMountedAction()
        ?->getModalDescription();

    $normalModal = Livewire::test(TranslationReview::class)
        ->mountAction(TestAction::make('translateAi')->table($untouched))
        ->instance()
        ->getMountedAction()
        ?->getModalDescription();

    expect($outdatedModal)->toBe(__('cms.ai_translation.confirm_outdated'))
        ->and($normalModal)->toBe(__('cms.ai_translation.confirm'));
});

it('replaces rather than appends when re-translating an ai_translated locale', function (): void {
    enableAiTranslation();
    fakeOpenRouter('Machine title', static fn (string $s): string => 'EN: '.$s);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // First run: not_translated -> ai_translated.
    app(AiTranslator::class)->translate($content, 'en');

    $content = $content->fresh();
    /** @var array<string, mixed> $firstBody */
    $firstBody = $content->getTranslation('body', 'en', useFallbackLocale: false);

    expect($content->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated);

    // Second run over the now ai_translated locale is allowed.
    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $secondBody */
    $secondBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    // Node count/structure identical — the body was REPLACED, not appended.
    expect(count($secondBody['content']))->toBe(count($firstBody['content']))
        ->and(array_map(fn (array $n): string => $n['type'], $secondBody['content']))
        ->toBe(array_map(fn (array $n): string => $n['type'], $firstBody['content']));

    // The body is always re-translated from the fa source (not from the prior
    // English), so the leaf carries exactly ONE "EN: " prefix — never a doubled
    // "EN: EN: ..." from appending, and the paragraph still holds a single leaf.
    $leaf = $secondBody['content'][0]['content'][0]['text'];
    expect(substr_count($leaf, 'EN: '))->toBe(1)
        ->and($secondBody['content'][0]['content'])->toHaveCount(1)
        ->and($secondBody['content'][0]['content'][0]['text'])->toBe($firstBody['content'][0]['content'][0]['text']);
});

it('short-circuits when AI translation is disabled and makes no HTTP call', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, false);
    Setting::putSecret(Setting::OPENROUTER_API_KEY, 'sk-or-test-key');
    Http::fake();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.disabled');

    Http::assertNothingSent();
});

it('fails gracefully with no HTTP call when the API key is missing', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);
    Http::fake();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.missing_key');

    Http::assertNothingSent();
});

it('handles an OpenRouter error response gracefully', function (): void {
    enableAiTranslation();
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'upstream boom']], 500),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    // The failed attempt did not fabricate a translation.
    expect($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))->toBeEmpty();
});

it('rejects an empty-after-trim batch element rather than writing an invalid TipTap leaf', function (): void {
    enableAiTranslation();

    /*
     * The model returns an array of the SAME length for the BODY batch, but one
     * element is whitespace-only. An empty text-node `text` is an invalid
     * ProseMirror/TipTap leaf (RULE #6), so the batch path must reject it outright —
     * a hard request_failed, never a best-effort merge that writes the empty string
     * and leaves an unopenable document behind.
     *
     * Aimed at the prose batch specifically, so the fields batch answers normally and
     * the failure can only have come from the element under test.
     */
    Http::fake([
        'openrouter.ai/*' => function (Request $request): PromiseInterface {
            $segments = batchSegments($request);

            if (isFieldsBatch($request)) {
                return openRouterBatchReply(array_fill(0, count($segments), 'Machine title'));
            }

            $translated = array_map(static fn (string $element): string => 'EN: '.$element, $segments);
            $translated[0] = '   ';

            return openRouterBatchReply(array_values($translated));
        },
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    // No invalid body was written for the target locale.
    expect($content->fresh()->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty();
});

it('leaves the target body empty rather than copying the Persian source when there is no prose to translate', function (): void {
    /*
     * A body can be valid and hold no translatable prose at all: a lone
     * gallery_embed (it only references a gallery by id), an image-only body, or
     * nothing but empty paragraphs. The walk then collects zero segments, and the
     * translator used to write the UNTRANSLATED source document into the target
     * locale anyway — it only skipped the bookkeeping. The locale ended up holding
     * a verbatim Persian body that hasAnyTranslationFor() counted as present and
     * the review queue showed as finished work: a silent false success nobody goes
     * looking for.
     */
    enableAiTranslation();
    fakeOpenRouter('Machine English title');

    $content = Content::factory()->create(['title' => ['fa' => 'گزارش تصویری']]);

    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => TipTap::CUSTOM_BLOCK_NODE_TYPE,
                'attrs' => [
                    'config' => ['gallery_id' => 7, 'layout' => 'grid', 'max_items' => 12],
                    'id' => 'gallery_embed',
                    'label' => 'گالری',
                    'preview' => base64_encode('<div>gallery 7</div>'),
                    'shouldApplyProseStylingToPreview' => false,
                ],
            ],
            // A structurally-empty paragraph, which is what an editor leaves behind
            // by clicking into the field and out again.
            ['type' => 'paragraph'],
        ],
    ]);
    $content->saveQuietly();

    /** @var array<string, mixed> $sourceBody */
    $sourceBody = $content->getTranslation('body', 'fa', useFallbackLocale: false);

    // The title still IS translatable, so the run succeeds on its strength.
    $result = app(AiTranslator::class)->translate($content, 'en');

    $fresh = $content->fresh();

    expect($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Machine English title')
        // The point of the fix: no body at all for the target locale — NOT a copy
        // of the Persian one.
        ->and($fresh->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty()
        ->and($result->attributes)->not->toContain('body')
        // The Persian source is untouched, structural attrs included.
        ->and($fresh->getTranslation('body', 'fa', useFallbackLocale: false))->toBe($sourceBody);

    /*
     * Exactly ONE request was made — the batched plain fields — and no PROSE batch at
     * all, because there was nothing in the body to batch. Asserted on the kind rather
     * than on the message shape, since every call is a JSON array now.
     */
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => ! isFieldsBatch($request));
});

it('reports an empty source when a present body holds no translatable prose either', function (): void {
    /*
     * Coherence with the guard above. Once a prose-free body is no longer written,
     * a record whose ONLY content is such a body has nothing translatable
     * anywhere, and the honest answer is the same empty-source report a blank
     * record gets — not a success that translated not one word and still left the
     * locale sitting at ai_translated in the review queue.
     */
    enableAiTranslation();
    Http::fake();

    $content = Content::factory()->create([
        'title' => ['fa' => ''],
        'slug' => ['fa' => 'placeholder-slug'],
        'excerpt' => ['fa' => ''],
        'answer_paragraph' => ['fa' => ''],
        'meta_title' => ['fa' => ''],
        'meta_description' => ['fa' => ''],
    ]);

    $content->setTranslation('title', 'fa', '');
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [
            [
                'type' => TipTap::CUSTOM_BLOCK_NODE_TYPE,
                'attrs' => [
                    'config' => ['gallery_id' => 3, 'layout' => 'carousel'],
                    'id' => 'gallery_embed',
                    'label' => 'گالری',
                    'preview' => base64_encode('<div>gallery 3</div>'),
                    'shouldApplyProseStylingToPreview' => false,
                ],
            ],
        ],
    ]);
    $content->saveQuietly();

    expect(fn () => app(AiTranslator::class)->translate($content->fresh(), 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.empty_source');

    Http::assertNothingSent();

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated)
        ->and($content->fresh()->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty();
});

it('reports an empty source rather than calling the model', function (): void {
    enableAiTranslation();
    Http::fake();

    // A record whose translatable PROSE fields are all blank in the source, AND
    // whose body is empty. Only slug remains (deliberately skipped), so there is
    // nothing — plain field or body prose — to send to the model.
    $content = Content::factory()->create([
        'title' => ['fa' => 'موقت'],
        'slug' => ['fa' => 'placeholder-slug'],
        'excerpt' => ['fa' => ''],
        'answer_paragraph' => ['fa' => ''],
        'meta_title' => ['fa' => ''],
        'meta_description' => ['fa' => ''],
        'body' => ['fa' => null],
    ]);

    // Now blank the title too, leaving only skipped fields populated.
    $content->setTranslation('title', 'fa', '');
    $content->saveQuietly();

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.empty_source');

    Http::assertNothingSent();
});

it('exposes the Translate with AI action on the review page and translates through the queued job', function (): void {
    // End to end over the real dispatch path. QUEUE_CONNECTION=sync in the test
    // environment, so the job runs inline here and the assertions below are about
    // the RESULT of the queued flow, not about it being synchronous — the test
    // above it asserts that the action itself only dispatches.
    enableAiTranslation();
    fakeOpenRouter('Machine English title');

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('translateAi')->table($state));

    expect($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::AiTranslated)
        ->and($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))
        ->toBe('Machine English title');
});

it('queues the translation instead of running it inside the Livewire request', function (): void {
    /*
     * The whole point of the change. One run makes up to six sequential OpenRouter
     * calls at 30s each — around three minutes — which PHP's max_execution_time and
     * nginx's fastcgi_read_timeout kill first, losing work that has already been
     * paid for while holding a PHP-FPM worker throughout.
     */
    Queue::fake();
    enableAiTranslation();
    Http::fake();

    $user = User::factory()->admin()->create();
    actingAs($user);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان فارسی']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('translateAi')->table($state))
        // The user is TOLD it was queued rather than left to assume it happened.
        ->assertNotified(__('cms.ai_translation.queued', [
            'locale' => TranslatableTabs::localeLabel('en'),
        ]));

    Queue::assertPushed(
        TranslateRecordJob::class,
        // uniqueId() is the job's own public statement of what it will work on, so
        // asserting through it also pins the deduplication key.
        fn (TranslateRecordJob $job): bool => $job->uniqueId() === Content::class.':'.$content->getKey().':en',
    );

    // Nothing happened in the request itself: no API call, no write, and the row is
    // still in the backlog exactly as it was.
    Http::assertNothingSent();

    expect($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))->toBeEmpty()
        ->and($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated);
});

it('collapses a double-clicked action into a single queued translation', function (): void {
    /*
     * The action is a row button, and a double click used to fire two complete
     * translation runs: both paid for every field, both wrote the same locale, and
     * the later one won. ShouldBeUnique on (record, locale) makes the second
     * dispatch a no-op while the first is still outstanding.
     */
    Queue::fake();
    enableAiTranslation();

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $english = $content->translationStates()->where('locale', 'en')->firstOrFail();
    $arabic = $content->translationStates()->where('locale', 'ar')->firstOrFail();

    $page = Livewire::test(TranslationReview::class);

    $page->callAction(TestAction::make('translateAi')->table($english));
    $page->callAction(TestAction::make('translateAi')->table($english));

    Queue::assertPushed(TranslateRecordJob::class, 1);

    // Scoped to the locale, not to the record: Arabic is different work and must
    // still queue while English is outstanding.
    $page->callAction(TestAction::make('translateAi')->table($arabic));

    Queue::assertPushed(TranslateRecordJob::class, 2);
});

it('refuses fast, without queueing, when the feature is off or the key is missing', function (): void {
    /*
     * These two answers cost one query each. Queueing a job that can only report
     * them back minutes later would turn an immediate, actionable message ("turn it
     * on in Settings") into a notification about work that was never going to run.
     */
    Queue::fake();
    Http::fake();

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    // Enabled but no key: the action is visible (isEnabled() is true) and must
    // still refuse without queueing.
    Setting::put(Setting::AI_TRANSLATION_ENABLED, true);

    Livewire::test(TranslationReview::class)
        ->callAction(TestAction::make('translateAi')->table($state))
        ->assertNotified(__('cms.ai_translation.failed'));

    // assertNotPushed rather than assertNothingPushed: creating the article queued a
    // search-index sync, which is unrelated and expected.
    Queue::assertNotPushed(TranslateRecordJob::class);
    Http::assertNothingSent();
});

it('tells the user the outcome through a database notification', function (): void {
    /*
     * The request that queued the work is long gone by the time the work finishes,
     * so a flash notification cannot report it. Without a durable channel the
     * translator is told "queued" and never learns whether it worked.
     */
    enableAiTranslation();
    fakeOpenRouter('Machine English title');

    $user = User::factory()->admin()->create();
    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    TranslateRecordJob::dispatchSync(Content::class, $content->getKey(), 'en', $user->getKey());

    $notification = $user->notifications()->firstOrFail();

    expect($notification->data['title'] ?? null)->toBe(__('cms.ai_translation.success', [
        'locale' => TranslatableTabs::localeLabel('en'),
    ]));
});

it('reports a refusal to the user instead of retrying it', function (): void {
    /*
     * A retry re-translates from scratch, so every attempt costs another full set of
     * paid calls. A domain refusal is an ANSWER, not a transient fault: the job
     * reports it and returns, which is why it must never reach the queue's failure
     * handling.
     */
    enableAiTranslation();
    fakeOpenRouter('Should never be written');

    $user = User::factory()->admin()->create();
    $content = Content::factory()->multilingual()->create();
    $content->markTranslationReviewed('en');

    TranslateRecordJob::dispatchSync(Content::class, $content->getKey(), 'en', $user->getKey());

    $notification = $user->notifications()->firstOrFail();

    expect($notification->data['title'] ?? null)->toBe(__('cms.ai_translation.skipped'))
        ->and($notification->data['body'] ?? null)->toBe(__('cms.ai_translation.error.already_reviewed'))
        // A warning, not danger: the machine declining to overwrite signed-off text
        // is the policy working, and colouring it red would teach translators to
        // treat a correct refusal as a broken service.
        ->and($notification->data['status'] ?? null)->toBe('warning');

    Http::assertNothingSent();
});

it('does not overwrite a locale a human signs off while the translation is running', function (): void {
    /*
     * The guard→write TOCTOU window. The up-front reviewed check runs before the
     * first request and the write lands up to three minutes later, so a translator
     * who finishes reviewing the locale in between used to have their work silently
     * replaced by machine output.
     *
     * The sign-off is performed from INSIDE the faked HTTP call, which is exactly
     * where it would happen in production: mid-run.
     */
    enableAiTranslation();

    // No body, so the run is a single batched plain-field request and the fake below
    // can be one side-effecting handler.
    $content = Content::factory()->create([
        'title' => ['fa' => 'عنوان فارسی'],
        'body' => ['fa' => null],
    ]);

    Http::fake(function (Request $request) use ($content): PromiseInterface {
        // A different instance, as a concurrent request would be.
        Content::query()->findOrFail($content->getKey())->markTranslationReviewed('en');

        return openRouterBatchReply(
            array_fill(0, count(batchSegments($request)), 'Machine English title'),
        );
    });

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.already_reviewed');

    $fresh = $content->fresh();

    // The human's sign-off survives, and not one machine-translated field was
    // written — the transaction rolled the whole attempt back.
    expect($fresh->translationStatusFor('en'))->toBe(TranslationStatus::Reviewed)
        ->and($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBeEmpty();
});

it('writes the translated content and its status row in one transaction', function (): void {
    /*
     * They used to be two independent writes. A failure in between left translated
     * text on the record while the status row still said not_translated, so the
     * review queue kept advertising work that was already done and the next
     * translator paid for it again.
     */
    enableAiTranslation();
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // RefreshDatabase already holds a transaction, so the baseline is 1, not 0.
    $baseline = DB::transactionLevel();
    $writeLevels = [];

    DB::listen(function (QueryExecuted $query) use (&$writeLevels): void {
        $sql = strtolower(trim($query->sql));

        if (! str_starts_with($sql, 'update') && ! str_starts_with($sql, 'insert')) {
            return;
        }

        if (str_contains($sql, 'contents') || str_contains($sql, 'translation_states')) {
            $writeLevels[] = DB::transactionLevel();
        }
    });

    app(AiTranslator::class)->translate($content, 'en');

    expect($writeLevels)->not->toBeEmpty()
        ->and(min($writeLevels))->toBeGreaterThan($baseline);
});

it('hides the Translate with AI action when the feature is disabled', function (): void {
    Setting::put(Setting::AI_TRANSLATION_ENABLED, false);

    actingAs(User::factory()->admin()->create());

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $state = $content->translationStates()->where('locale', 'en')->firstOrFail();

    Livewire::test(TranslationReview::class)
        ->assertActionHidden(TestAction::make('translateAi')->table($state));
});

/**
 * A TipTap document of $count paragraphs, each with one numbered prose leaf.
 *
 * Numbered rather than identical so a mis-ordered or duplicated translation is
 * visible in an assertion rather than hidden behind matching strings — which is the
 * entire risk chunking introduces.
 *
 * @return array<string, mixed>
 */
function proseDocument(int $count): array
{
    $nodes = [];

    foreach (range(1, $count) as $index) {
        $nodes[] = [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => "بند شمارهٔ {$index}"]],
        ];
    }

    return ['type' => 'doc', 'content' => $nodes];
}

it('sends every plain field in one request instead of one request each', function (): void {
    /*
     * The change this stage is about. Five plain fields used to be five POSTs: five
     * copies of the system prompt billed, five entries against the provider's rate
     * limit, five sequential timeouts to wait through — for text that is already a
     * list of strings, which is exactly the batch protocol the body was using.
     */
    enableAiTranslation();
    fakeOpenRouter('Machine English');

    $content = Content::factory()->create([
        'title' => ['fa' => 'عنوان'],
        'excerpt' => ['fa' => 'خلاصه'],
        'answer_paragraph' => ['fa' => 'پاسخ کوتاه'],
        'meta_title' => ['fa' => 'عنوان سئو'],
        'meta_description' => ['fa' => 'توضیح سئو'],
        'body' => ['fa' => null],
    ]);

    $result = app(AiTranslator::class)->translate($content, 'en');

    // All five fields translated, in ONE round trip.
    expect($result->attributes)->toContain('title', 'excerpt', 'answer_paragraph', 'meta_title', 'meta_description');

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        $segments = batchSegments($request);

        return isFieldsBatch($request)
            && $segments === ['عنوان', 'خلاصه', 'پاسخ کوتاه', 'عنوان سئو', 'توضیح سئو'];
    });

    $fresh = $content->fresh();

    // And each field got its own element of the reply, not a shared one by accident:
    // the positional mapping is against the same array that built the request.
    foreach (['title', 'excerpt', 'answer_paragraph', 'meta_title', 'meta_description'] as $attribute) {
        expect($fresh->getTranslation($attribute, 'en', useFallbackLocale: false))->toBe('Machine English');
    }
});

it('maps each plain field to its own translation rather than sharing one', function (): void {
    // The failure a positional 1:1 mapping can hide: five fields, five DIFFERENT
    // translations, each landing on the right attribute.
    enableAiTranslation();

    Http::fake([
        'openrouter.ai/*' => fn (Request $request): PromiseInterface => openRouterBatchReply(
            array_map(
                static fn (int $index): string => 'EN-'.$index,
                range(1, count(batchSegments($request))),
            ),
        ),
    ]);

    $content = Content::factory()->create([
        'title' => ['fa' => 'عنوان'],
        'excerpt' => ['fa' => 'خلاصه'],
        'body' => ['fa' => null],
    ]);

    app(AiTranslator::class)->translate($content, 'en');

    $fresh = $content->fresh();

    // getTranslatableAttributes() order is what built the request, and the same array
    // is what consumed the reply, so position 1 is `title` and position 2 is `excerpt`.
    expect($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBe('EN-1')
        ->and($fresh->getTranslation('excerpt', 'en', useFallbackLocale: false))->toBe('EN-2');
});

it('splits a long body into bounded chunks and still maps every leaf 1:1', function (): void {
    /*
     * The ceiling batching alone does not remove: a body of hundreds of prose leaves
     * sent as one request exceeds the model's OUTPUT token limit, the JSON truncates
     * mid-string, json_decode fails, and the editor is told only "request_failed".
     *
     * With the segment bound at 3, a nine-leaf body must become three prose requests —
     * and, crucially, the nine translations must land in the nine original nodes in
     * the original order. The numbered leaves are what makes a mis-ordered merge
     * visible instead of invisible.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_segments_per_request', 3);
    fakeOpenRouter('Machine title');

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $content->setTranslation('body', 'fa', proseDocument(9));
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    // One request for the fields plus three for the body.
    Http::assertSentCount(4);

    $proseRequestSizes = [];

    Http::recorded(function (Request $request) use (&$proseRequestSizes): void {
        if (! isFieldsBatch($request)) {
            $proseRequestSizes[] = count(batchSegments($request));
        }
    });

    expect($proseRequestSizes)->toBe([3, 3, 3])
        ->and($enBody['content'])->toHaveCount(9);

    // Every leaf holds the translation of ITS OWN source, in order. A chunk boundary
    // that lost, duplicated or re-ordered an element fails right here.
    foreach (range(1, 9) as $index) {
        expect($enBody['content'][$index - 1]['content'][0]['text'])->toBe("EN: بند شمارهٔ {$index}");
    }
});

it('bounds a chunk by characters as well as by segment count', function (): void {
    /*
     * Either bound alone is escapable: forty short captions and four enormous
     * paragraphs are the same segment count and nothing like the same token count, and
     * it is tokens the model's output limit is measured in.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_segments_per_request', 50);
    config()->set('cms.ai.translation.max_characters_per_request', 30);
    fakeOpenRouter('Machine title');

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);

    // Four leaves of ~20 characters each: well under the segment bound, well over the
    // character bound if they travel together.
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => array_map(
            fn (int $i): array => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => str_repeat('ا', 19).$i]],
            ],
            range(1, 4),
        ),
    ]);
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    $proseRequests = 0;

    Http::recorded(function (Request $request) use (&$proseRequests): void {
        if (! isFieldsBatch($request)) {
            $proseRequests++;
        }
    });

    // Two leaves per request at most, so four leaves cost four requests — not one.
    expect($proseRequests)->toBe(4);
});

it('never splits a single segment, even one over the character bound', function (): void {
    /*
     * A segment is atomic. Splitting one would break the 1:1 mapping back to the node
     * it came from — the invariant that keeps the document's structure intact — and it
     * would cut a sentence in half mid-translation. An oversized leaf therefore gets a
     * request of its own, which is fine: it is a paragraph, not an article.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_characters_per_request', 5);
    fakeOpenRouter('Machine title');

    $long = str_repeat('ب', 200);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $content->setTranslation('body', 'fa', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $long]]]],
    ]);
    $content->saveQuietly();

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(fn (Request $request): bool => ! isFieldsBatch($request)
        // The whole leaf, in one piece.
        && batchSegments($request) === [$long]);

    /** @var array<string, mixed> $enBody */
    $enBody = $content->fresh()->getTranslation('body', 'en', useFallbackLocale: false);

    expect($enBody['content'][0]['content'][0]['text'])->toBe('EN: '.$long);
});

it('refuses an over-long record before spending anything, with an actionable reason', function (): void {
    /*
     * Bounded means bounded. Chunking is computed from the already-collected segments
     * and makes no request, so a document that cannot be translated within the
     * configured budget is refused for free — and refused with its OWN reason, because
     * "the service could not be reached, try again shortly" invites the editor to retry
     * something that will fail identically for ever.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_segments_per_request', 2);
    config()->set('cms.ai.translation.max_requests_per_record', 3);
    Http::fake();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    // Ten leaves at two per request is five prose requests, plus one for the fields:
    // six, against a cap of three.
    $content->setTranslation('body', 'fa', proseDocument(10));
    $content->saveQuietly();

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.source_too_long');

    // Nothing was paid for, and nothing was written.
    Http::assertNothingSent();

    expect($content->fresh()->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty()
        ->and($content->fresh()->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated);

    // The message is a real one in every locale, not a missing key rendered raw.
    foreach (['fa', 'en', 'ar'] as $locale) {
        expect(__('cms.ai_translation.error.source_too_long', [], $locale))
            ->not->toBe('cms.ai_translation.error.source_too_long');
    }
});

it('discards the whole body when one chunk comes back the wrong length', function (): void {
    /*
     * The hard-fail contract, now with more than one chunk to get wrong. A short reply
     * from chunk two must not shift chunk three's translations up into chunk two's
     * nodes — which is exactly what a best-effort merge would do, producing a document
     * that is structurally valid and semantically scrambled, the failure nobody notices
     * until a reader does.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_segments_per_request', 2);

    $call = 0;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use (&$call): PromiseInterface {
            $segments = batchSegments($request);

            if (isFieldsBatch($request)) {
                return openRouterBatchReply(array_fill(0, count($segments), 'Machine title'));
            }

            $call++;

            $translated = array_map(static fn (string $s): string => 'EN: '.$s, $segments);

            // Second prose chunk answers one element short.
            if ($call === 2) {
                array_pop($translated);
            }

            return openRouterBatchReply(array_values($translated));
        },
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان']]);
    $content->setTranslation('body', 'fa', proseDocument(6));
    $content->saveQuietly();

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    // Not a partial body, and not a partial record either: the plain fields the first
    // request DID translate are rolled back with it, because the save never happened.
    $fresh = $content->fresh();

    expect($fresh->getTranslation('body', 'en', useFallbackLocale: false))->toBeEmpty()
        ->and($fresh->getTranslation('title', 'en', useFallbackLocale: false))->toBeEmpty()
        ->and($fresh->translationStatusFor('en'))->toBe(TranslationStatus::NotTranslated);
});

it('sends a deterministic, JSON-constrained, attributed request', function (): void {
    /*
     * Request hygiene, all asserted together because they are one decision — "ask
     * properly" — and each was absent:
     *
     *  - temperature 0: translation is not a creative task, and at a non-zero
     *    temperature the same article re-translated after a small edit comes back
     *    reworded throughout, so a reviewer re-checks paragraphs they already passed;
     *  - response_format json_object: cheap insurance against the most common
     *    malformed reply, a correct JSON body wrapped in a ```json fence;
     *  - max_tokens: a runaway response cannot bill for tokens nobody asked for;
     *  - HTTP-Referer / X-Title: OpenRouter's recommended attribution headers, taken
     *    from per-deployment config because a reusable Core must not bake a client's
     *    name in.
     */
    enableAiTranslation();
    fakeOpenRouter();

    config()->set('app.url', 'https://panel.example.test');
    config()->set('app.name', 'Example CMS');

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    app(AiTranslator::class)->translate($content, 'en');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return ($data['temperature'] ?? null) === 0
            && ($data['response_format']['type'] ?? null) === 'json_object'
            && ($data['max_tokens'] ?? null) === 4096
            && $request->hasHeader('HTTP-Referer', 'https://panel.example.test')
            && $request->hasHeader('X-Title', 'Example CMS');
    });
});

it('names the languages the way a person would, not as ISO codes', function (): void {
    /*
     * The prompt interpolated raw codes — "from fa to ar" — while the panel already had
     * human names for exactly these locales. "ar" read cold is ambiguous (it is also a
     * country code); the endonym is not. Both are sent, and they come from
     * TranslatableTabs::localeLabel() so the prompt cannot drift from the form.
     */
    enableAiTranslation();
    fakeOpenRouter();

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    app(AiTranslator::class)->translate($content, 'ar');

    Http::assertSent(function (Request $request): bool {
        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'system') {
                continue;
            }

            $system = (string) ($message['content'] ?? '');

            return str_contains($system, TranslatableTabs::localeLabel('fa').' (fa)')
                && str_contains($system, TranslatableTabs::localeLabel('ar').' (ar)');
        }

        return false;
    });
});

it('accepts a bare array from a model that ignores JSON mode', function (): void {
    /*
     * The model is administrator-chosen from OpenRouter's whole catalogue
     * (Requirement 1.2) and plenty of them ignore response_format. Hard-failing on one
     * that answered correctly but unwrapped would make the feature unusable on it for
     * no gain — and unwrapping a container is not a best-effort merge: the length and
     * element-type contract below is untouched.
     */
    enableAiTranslation();

    Http::fake([
        'openrouter.ai/*' => fn (Request $request) => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    // A top-level array, not {"translations": [...]}.
                    'content' => (string) json_encode(
                        array_map(static fn (string $s): string => 'EN: '.$s, batchSegments($request)),
                        JSON_UNESCAPED_UNICODE,
                    ),
                ],
            ]],
        ], 200),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    app(AiTranslator::class)->translate($content, 'en');

    expect($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))->toBe('EN: عنوان');
});

it('retries a rate-limited request and waits as long as the provider asked', function (): void {
    /*
     * A 429 used to be treated exactly like a 500, which was in turn treated like a
     * 401: one attempt, then failure. A 429 is the provider saying "not yet", usually
     * saying WHEN in a Retry-After header — the one number better informed than any
     * backoff curve we could invent.
     */
    enableAiTranslation();

    $attempt = 0;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use (&$attempt): PromiseInterface {
            $attempt++;

            if ($attempt === 1) {
                return Http::response(['error' => 'slow down'], 429, ['Retry-After' => '7']);
            }

            return openRouterBatchReply(
                array_fill(0, count(batchSegments($request)), 'Machine title'),
            );
        },
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    app(AiTranslator::class)->translate($content, 'en');

    expect($content->fresh()->getTranslation('title', 'en', useFallbackLocale: false))->toBe('Machine title');

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 7);
});

it('refuses a Retry-After longer than the cap instead of holding the worker', function (): void {
    /*
     * The header is honoured up to a ceiling and no further. A queued job that sleeps
     * for ten minutes because a third party said so is occupying a worker the rest of
     * the queue needs, and the queue's own retry is the right place to wait that long.
     */
    enableAiTranslation();
    config()->set('cms.ai.translation.max_retry_delay', 5);

    $attempt = 0;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use (&$attempt): PromiseInterface {
            $attempt++;

            if ($attempt === 1) {
                return Http::response(['error' => 'slow down'], 429, ['Retry-After' => '600']);
            }

            return openRouterBatchReply(
                array_fill(0, count(batchSegments($request)), 'Machine title'),
            );
        },
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    app(AiTranslator::class)->translate($content, 'en');

    // Fell back to the capped backoff curve rather than to the 600 seconds requested.
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds <= 5);
});

it('does not retry a configuration error', function (): void {
    /*
     * A bad key or an unknown model will be just as bad on the third attempt. Retrying
     * only triples the latency before the editor sees the real problem — and it is the
     * editor, not the provider, who has to act.
     */
    enableAiTranslation();

    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'No auth credentials found']], 401),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('gives up after the configured number of attempts on a failing provider', function (): void {
    // A 5xx IS retried — it is a transient fault — but not for ever: each attempt costs
    // another paid call, and there is no partial-progress checkpoint to resume from.
    enableAiTranslation();
    config()->set('cms.ai.translation.max_attempts', 2);

    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => 'upstream boom'], 500),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    expect(fn () => app(AiTranslator::class)->translate($content, 'en'))
        ->toThrow(AiTranslationException::class, 'cms.ai_translation.error.request_failed');

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('keeps the API key out of every failure it reports', function (): void {
    /*
     * RULE #8. The retry path now reads status codes and headers and loops, which is
     * more code between the credential and the error the user sees — so the property is
     * pinned rather than assumed. Asserted against the exception the job surfaces AND
     * against a response body that deliberately echoes the key back, which is the way a
     * naive "include the upstream error" would leak it.
     */
    $key = 'sk-or-super-secret-9876543210';

    enableAiTranslation($key);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => ['message' => "Invalid credentials: {$key}"],
        ], 400),
    ]);

    $content = Content::factory()->create(['title' => ['fa' => 'عنوان'], 'body' => ['fa' => null]]);

    try {
        app(AiTranslator::class)->translate($content, 'en');
        $thrown = null;
    } catch (AiTranslationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('cms.ai_translation.error.request_failed')
        ->and(str_contains((string) $thrown, $key))->toBeFalse()
        // The trace carries argument values on some configurations; the rendered
        // string a log line would write must still not contain the key.
        ->and(str_contains($thrown->getTraceAsString(), $key))->toBeFalse();
});
