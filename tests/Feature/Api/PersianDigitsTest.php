<?php

declare(strict_types=1);

use App\Enums\RedirectType;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\Redirect;
use App\Providers\CmsServiceProvider;
use Filament\Support\Facades\FilamentAsset;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * «۱۴۰۳» and «1403» are one number to the person typing it.
 *
 * Wherever a digit is part of an identifier — a slug, a path, a phone number, a page
 * number, a search term, the sign-in code — both spellings must behave as one. Editorial
 * text keeps the digits its author typed.
 */
function persianDigits(string $ascii): string
{
    return strtr($ascii, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

it('stores a typed slug with ASCII digits, the same URL as a generated one', function (): void {
    $typed = Content::factory()->published()->create(['slug' => ['fa' => 'خبر-'.persianDigits('1403')]]);
    $generated = Content::factory()->published()->create(['title' => ['fa' => 'گزارش '.persianDigits('1403')]]);

    expect($typed->getTranslation('slug', 'fa'))->toBe('خبر-1403')
        ->and($generated->getTranslation('slug', 'fa'))->toBe('گزارش-1403');
});

it('does not rewrite a stored slug on a save that leaves the slug alone', function (): void {
    // A live URL must not move just because some other field was edited.
    $content = Content::factory()->published()->create(['slug' => ['fa' => 'قدیمی-1']]);
    Content::query()->whereKey($content->getKey())->update(['slug' => json_encode(['fa' => 'قدیمی-'.persianDigits('1')])]);

    $content = $content->fresh();
    $content->setTranslation('title', 'fa', 'عنوان تازه');
    $content->save();

    expect($content->fresh()->getTranslation('slug', 'fa'))->toBe('قدیمی-'.persianDigits('1'));
});

it('finds an article whichever digits the URL was typed with', function (): void {
    Content::factory()->published()->create(['title' => ['fa' => 'خبر 1403']]);

    getJson('/api/v1/news/'.rawurlencode('خبر-1403'))->assertOk();
    getJson('/api/v1/news/'.rawurlencode('خبر-'.persianDigits('1403')))->assertOk();
    getJson('/api/v1/news/'.rawurlencode('خبر-'.persianDigits('1403')).'/seo')->assertOk();
});

it('filters the news listing by a category slug typed with Persian digits', function (): void {
    $category = Category::factory()->create(['slug' => ['fa' => 'سال-1403']]);
    $article = Content::factory()->published()->create();
    $article->categories()->attach($category);

    $ids = getJson('/api/v1/news?category='.rawurlencode('سال-'.persianDigits('1403')))->json('data.*.id');

    expect($ids)->toBe([$article->getKey()]);
});

it('stores a phone number with ASCII digits whichever keyboard typed it', function (): void {
    postJson('/api/v1/contact', [
        'name' => 'علی',
        'phone' => persianDigits('0912 345 6789'),
        'message' => 'سلام، لطفا با من تماس بگیرید.',
    ])->assertCreated();

    $submission = ContactSubmission::query()->sole();

    expect($submission->phone)->toBe('0912 345 6789')
        ->and($submission->payload['phone'])->toBe('0912 345 6789')
        // Free text keeps what the visitor wrote.
        ->and($submission->payload['name'])->toBe('علی');
});

it('matches a search whichever digits the article or the query used', function (): void {
    $article = Content::factory()->published()->create(['title' => ['fa' => 'بودجه '.persianDigits('1403')]]);

    // The index copy is folded; the article itself keeps its Persian digits.
    expect($article->forSearchLocale('fa')->toSearchableArray()['title'])->toBe('بودجه 1403')
        ->and($article->fresh()->getTranslation('title', 'fa'))->toBe('بودجه '.persianDigits('1403'));

    expect(getJson('/api/v1/search?q=1403')->json('data.*.id'))->toBe([$article->getKey()])
        ->and(getJson('/api/v1/search?q='.rawurlencode(persianDigits('1403')))->json('data.*.id'))->toBe([$article->getKey()]);
});

it('reads a page number sent with Persian digits', function (): void {
    Content::factory()->published()->count(3)->create();

    $response = getJson('/api/v1/news?per_page='.rawurlencode(persianDigits('1')).'&page='.rawurlencode(persianDigits('2')))->assertOk();

    expect($response->json('meta.current_page'))->toBe(2)
        ->and($response->json('meta.per_page'))->toBe(1);
});

it('matches a redirect typed with Persian digits against the real ASCII URL', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/news/خبر-'.persianDigits('1403'),
        'to_path' => '/fa/news/خبر-'.persianDigits('1404'),
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/news/'.rawurlencode('خبر-1403'))
        ->assertStatus(301)
        ->assertRedirect('/fa/news/خبر-1404');
});

it('loads the Persian-digit input fix on every panel page, sign-in included', function (): void {
    /*
     * Filament's one-time-code input keeps only ASCII \d, so without this script an editor on
     * a Persian keyboard could not type the sign-in code at all. Verified in a browser when
     * written; asserted here so the script cannot be dropped from the panel silently.
     */
    expect(FilamentAsset::getScriptSrc(CmsServiceProvider::PERSIAN_DIGITS_ASSET_ID, 'cms'))->not->toBeEmpty();

    get('/admin/login')
        ->assertOk()
        ->assertSee('persian-digits.js', escape: false);
});
