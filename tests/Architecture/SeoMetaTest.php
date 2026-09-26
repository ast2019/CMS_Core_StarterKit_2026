<?php

declare(strict_types=1);

use App\Contracts\HasSeoMetadata;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Illuminate\Support\Facades\Schema;

/**
 * SEO metadata is per-locale, structurally.
 *
 * "i18n is structural… never add a field to a content model without deciding whether
 * it is translatable" — steering/architecture-rules.md, Standing Constraints.
 *
 * HasSeoMeta has declared SEO_TRANSLATABLE_ATTRIBUTES since it was written and
 * nothing asserted it, so the constant was documentation: a model could use the trait
 * with `meta_description` as a plain column and every locale would silently share one
 * Persian description. The failure is invisible in the panel — the field saves, the
 * form redisplays it — and only shows up as the same Persian snippet on the English
 * and Arabic pages in a search result.
 */
/**
 * Every model that promises SEO metadata.
 *
 * Listed rather than discovered by scanning the namespace, because the LIST is part
 * of the assertion: if a fifth SEO-bearing model appears, this test should be updated
 * deliberately, and the SeoSection tests below will fail until it is.
 *
 * @return list<class-string<HasSeoMetadata>>
 */
function seoBearingModels(): array
{
    return [Content::class, Page::class, Gallery::class, Category::class];
}

it('declares every required SEO attribute as translatable', function (): void {
    foreach (seoBearingModels() as $class) {
        $model = new $class;

        expect($model)->toBeInstanceOf(HasSeoMetadata::class);

        foreach (HasSeoMetadata::SEO_TRANSLATABLE_ATTRIBUTES as $attribute) {
            // assertContains, not Pest's toContain(): toContain is variadic over
            // NEEDLES, so a second string argument would be asserted as another
            // expected value rather than shown as the failure message.
            $this->assertContains(
                $attribute,
                $model->getTranslatableAttributes(),
                "{$class} must declare {$attribute} as translatable",
            );
        }
    }
});

it('declares every optional SEO column it actually has as translatable', function (): void {
    /*
     * The weaker, true rule. focus_keyphrase, og_title and og_description are on the
     * models where they mean something (Content, Page) and deliberately not on the
     * others — see HasSeoMetadata::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES. So the invariant
     * cannot be "every model has them"; it is "a model that HAS one shares it with no
     * other locale", which is the part that would break silently.
     */
    foreach (seoBearingModels() as $class) {
        $model = new $class;
        $table = $model->getTable();

        foreach (HasSeoMetadata::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES as $attribute) {
            if (! Schema::hasColumn($table, $attribute)) {
                continue;
            }

            $this->assertContains(
                $attribute,
                $model->getTranslatableAttributes(),
                "{$table}.{$attribute} exists, so {$class} must declare it translatable",
            );
        }
    }
});

it('keeps the optional SEO columns fillable, or the form silently drops them', function (): void {
    // A translatable attribute missing from $fillable saves nothing and reports no
    // error: the form posts it, the model ignores it, and the field appears to reset
    // itself on reload.
    foreach (seoBearingModels() as $class) {
        $model = new $class;

        foreach ($model->getTranslatableAttributes() as $attribute) {
            if (! in_array($attribute, HasSeoMetadata::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES, true)) {
                continue;
            }

            $this->assertContains(
                $attribute,
                $model->getFillable(),
                "{$class}::\$fillable must include {$attribute}",
            );
        }
    }
});

it('answers seoSupportsFocusKeyphrase() from the model rather than from a hardcoded list', function (): void {
    // This is what SeoSection and the API branch on, so the two cannot drift: adding
    // the column plus one line in $translatable is the whole change.
    expect((new Content)->seoSupportsFocusKeyphrase())->toBeTrue()
        ->and((new Page)->seoSupportsFocusKeyphrase())->toBeTrue()
        ->and((new Gallery)->seoSupportsFocusKeyphrase())->toBeFalse()
        ->and((new Category)->seoSupportsFocusKeyphrase())->toBeFalse();
});

it('reads an optional SEO attribute without throwing on a model that lacks it', function (): void {
    /*
     * The trap that turned a missing meta description on a Page into a 500: spatie
     * throws AttributeIsNotTranslatable for an attribute the model never registered,
     * so every optional-column read has to be guarded. A Gallery has no keyphrase and
     * no og_title, and asking for either must be a quiet empty answer.
     */
    $gallery = Gallery::factory()->create(['title' => ['fa' => 'گالری']]);

    expect($gallery->focusKeyphraseFor('fa'))->toBe('')
        ->and($gallery->ogTitleFor('fa'))->toBe($gallery->metaTitleFor('fa'))
        ->and($gallery->ogDescriptionFor('fa'))->toBe($gallery->metaDescriptionFor('fa'))
        ->and($gallery->seoAnalysisFor('fa'))->toHaveKeys(['keyphrase', 'score', 'band', 'checks']);
});
