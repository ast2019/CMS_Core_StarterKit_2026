<?php

declare(strict_types=1);

use App\Models\Content;
use App\Services\Content\SlugGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision D-1 — per-locale slug uniqueness.
 *
 * Slugs are translatable, so they live inside a JSON document. MySQL cannot index a
 * JSON path directly; it needs a stored generated column per locale with the unique
 * index on that column. SQLite cannot express the same thing.
 *
 * So uniqueness has TWO layers, and this file tests them separately:
 *
 *  - the application check in HasSlug, which runs everywhere and produces a readable
 *    result;
 *  - the database index, which only exists on MySQL and is the backstop against two
 *    concurrent saves racing past the application check.
 *
 * The MySQL tests SKIP on SQLite rather than pretending to pass. A test that silently
 * verifies nothing on the developer's machine is worse than an absent one, because it
 * reads as coverage.
 */
function isMySql(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

it('appends a suffix rather than colliding on a duplicate slug', function (): void {
    // The application-level layer, which runs on every driver.
    $first = Content::factory()->create(['title' => ['fa' => 'گزارش ویژه']]);
    $second = Content::factory()->create(['title' => ['fa' => 'گزارش ویژه']]);

    expect($first->getTranslation('slug', 'fa'))->toBe('گزارش-ویژه')
        ->and($second->getTranslation('slug', 'fa'))->toBe('گزارش-ویژه-2');
});

it('keeps slugs independent across locales', function (): void {
    /*
     * The same slug string in two different locales is NOT a conflict: /fa/news/x and
     * /en/news/x are different URLs. A single global unique index on the JSON column
     * would wrongly reject this, which is why the generated columns are per locale.
     */
    $content = Content::factory()->create(['title' => ['fa' => 'تست']]);

    $content->setTranslation('slug', 'en', $content->getTranslation('slug', 'fa'));
    $content->save();

    expect($content->fresh()->getTranslation('slug', 'en'))
        ->toBe($content->getTranslation('slug', 'fa'));
});

it('detects an existing slug for a locale', function (): void {
    $content = Content::factory()->create(['title' => ['fa' => 'یکتا']]);
    $slug = $content->getTranslation('slug', 'fa');

    $other = new Content;

    expect($other->slugExistsForLocale($slug, 'fa'))->toBeTrue()
        // Same string, different locale: free.
        ->and($other->slugExistsForLocale($slug, 'en'))->toBeFalse();
});

it('normalises Arabic input so it cannot slip past the uniqueness check', function (): void {
    /*
     * «کتابی» typed on a Persian keyboard and «كتابي» typed on an Arabic one are
     * different byte sequences for the same word. Without normalisation both would pass
     * the uniqueness check and then compete for the same URL.
     */
    $generator = app(SlugGenerator::class);

    expect($generator->generate('كتابي', 'fa'))->toBe($generator->generate('کتابی', 'fa'));
});

it('creates a stored generated column and unique index per locale on MySQL', function (): void {
    if (! isMySql()) {
        $this->markTestSkipped('Generated columns are a MySQL feature; SQLite relies on the application-level check.');
    }

    foreach ((array) config('cms.locales.supported') as $locale) {
        expect(Schema::hasColumn('contents', "slug_{$locale}"))->toBeTrue(
            "Decision D-1 violated: contents.slug_{$locale} generated column is missing."
        );
    }

    $indexes = collect(DB::select('SHOW INDEX FROM contents'))
        ->pluck('Key_name')
        ->unique()
        ->all();

    foreach ((array) config('cms.locales.supported') as $locale) {
        expect($indexes)->toContain("contents_slug_{$locale}_unique");
    }
});

it('rejects a duplicate slug at the database level on MySQL', function (): void {
    if (! isMySql()) {
        $this->markTestSkipped('The unique index only exists on MySQL; SQLite has no equivalent.');
    }

    $content = Content::factory()->create(['title' => ['fa' => 'یگانه']]);
    $slug = $content->getTranslation('slug', 'fa');

    /*
     * Written with a raw INSERT to bypass HasSlug deliberately. Going through the model
     * would hit the application check and never reach the index, so this is the only
     * way to prove the backstop exists — which is what protects against two concurrent
     * saves both passing the check.
     */
    expect(fn () => DB::table('contents')->insert([
        'title' => json_encode(['fa' => 'دیگری'], JSON_UNESCAPED_UNICODE),
        'slug' => json_encode(['fa' => $slug], JSON_UNESCAPED_UNICODE),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows many records with no slug for a locale on MySQL', function (): void {
    if (! isMySql()) {
        $this->markTestSkipped('MySQL-specific index behaviour.');
    }

    /*
     * A unique index still permits many NULLs in MySQL, which is required here:
     * untranslated articles all have no English slug, and they must not collide with
     * each other.
     */
    Content::factory()->count(3)->create(['title' => ['fa' => 'بدون ترجمه']]);

    $withoutEnglish = DB::table('contents')->whereNull('slug_en')->count();

    expect($withoutEnglish)->toBe(3);
});
