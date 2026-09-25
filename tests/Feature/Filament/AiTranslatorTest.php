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
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

/**
 * Fake OpenRouter for BOTH call shapes the translator now makes:
 *
 *  - Plain fields (title, excerpt, ...) go through translateText(), whose user
 *    message is a bare string; the model must answer with a bare string.
 *  - The TipTap body goes through translateBatch(), whose user message is a JSON
 *    array of prose segments; the model must answer with a JSON array of the
 *    SAME length.
 *
 * The single string helper answers every plain call with $translation. For the
 * body batch it echoes each segment through $segment (default: prefix "EN: ") so
 * a test can assert each leaf was replaced 1:1 while structure is preserved.
 *
 * @param  callable(string): string|null  $segment
 */
function fakeOpenRouter(string $translation = 'Translated text', ?callable $segment = null): void
{
    $segment ??= static fn (string $s): string => 'EN: '.$s;

    Http::fake([
        'openrouter.ai/*' => function (Request $request) use ($translation, $segment) {
            $messages = $request->data()['messages'] ?? [];
            $user = '';

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'user') {
                    $user = (string) ($message['content'] ?? '');
                }
            }

            $decoded = json_decode($user, true);

            // A JSON array user message is the body batch: answer with a JSON
            // array of the SAME length, translating each element.
            if (is_array($decoded) && array_is_list($decoded)) {
                $translated = array_map(
                    static fn ($element): string => $segment((string) $element),
                    $decoded,
                );

                $content = (string) json_encode($translated, JSON_UNESCAPED_UNICODE);
            } else {
                $content = $translation;
            }

            return Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $content]],
                ],
            ], 200);
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

    // The model returns a JSON array of the SAME length for the body batch, but
    // one element is whitespace-only. An empty text-node `text` is an invalid
    // ProseMirror/TipTap leaf (RULE #6), so the batch path must reject it the
    // same way translateText() rejects empty single-field output — a hard
    // request_failed, never a best-effort merge that writes the empty string.
    Http::fake([
        'openrouter.ai/*' => function (Request $request) {
            $messages = $request->data()['messages'] ?? [];
            $user = '';

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'user') {
                    $user = (string) ($message['content'] ?? '');
                }
            }

            $decoded = json_decode($user, true);

            // Body batch: answer with a same-length array whose first element is
            // whitespace-only.
            if (is_array($decoded) && array_is_list($decoded)) {
                $translated = array_map(
                    static fn ($element): string => 'EN: '.(string) $element,
                    $decoded,
                );
                $translated[0] = '   ';

                $content = (string) json_encode($translated, JSON_UNESCAPED_UNICODE);
            } else {
                $content = 'Machine title';
            }

            return Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $content]],
                ],
            ], 200);
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
     * The plain fields were sent one at a time as bare strings; no BATCH call (a
     * JSON-array user message, which is the body path's shape) was made at all,
     * because there was nothing in the body to batch.
     */
    Http::assertNotSent(function (Request $request): bool {
        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $decoded = json_decode((string) ($message['content'] ?? ''), true);

            if (is_array($decoded) && array_is_list($decoded)) {
                return true;
            }
        }

        return false;
    });
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

    // No body, so every call is a plain-field call answering with a bare string —
    // the fake below can then be a single side-effecting handler rather than having
    // to distinguish the batch shape.
    $content = Content::factory()->create([
        'title' => ['fa' => 'عنوان فارسی'],
        'body' => ['fa' => null],
    ]);

    Http::fake(function () use ($content) {
        // A different instance, as a concurrent request would be.
        Content::query()->findOrFail($content->getKey())->markTranslationReviewed('en');

        return Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Machine English title']]],
        ], 200);
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
