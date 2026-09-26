<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Gallery;
use Carbon\CarbonImmutable;

use function Pest\Laravel\getJson;

/**
 * The frontend half of localised dates.
 *
 * The frontend is not part of this repository and may be written in anything
 * (steering/product.md), so it cannot be assumed to own a Persian calendar. Each
 * ISO-8601 date in a Delivery payload therefore carries a sibling `*_display`
 * string already rendered in the calendar of the locale the request resolved to,
 * plus `meta.calendar` and `meta.timezone` saying how to read it.
 *
 * The ISO value is the contract and must never move; these tests assert both
 * halves, because dropping the machine-readable one in favour of a pretty string
 * would break sorting and caching for every consumer.
 */
beforeEach(function (): void {
    // Frozen AFTER the publication instant: the Delivery API serves only live
    // records, and a publish_date in the future makes the article scheduled
    // (Requirement 3.6) and therefore a 404.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));

    $this->publishedAt = CarbonImmutable::parse('2026-09-26 09:00:00', 'UTC');

    $this->content = Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => $this->publishedAt,
        'slug' => ['fa' => 'خبر-آزمایشی', 'en' => 'a-test-story', 'ar' => 'خبر-تجريبي'],
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders a Persian date for a Persian request and keeps the ISO value', function (): void {
    $payload = getJson('/api/v1/news/'.urlencode($this->content->getTranslation('slug', 'fa')).'?locale=fa')
        ->assertOk()
        ->json('data');

    expect($payload['publish_date'])->toBe($this->publishedAt->toIso8601String())
        // 09:00 UTC is 12:30 in Tehran on 4 Mehr 1405. The long form is used
        // because a display date is prose, not a column.
        ->and($payload['publish_date_display'])->toBe('۴ مهر ۱۴۰۵')
        ->and($payload['meta']['calendar'])->toBe('persian')
        ->and($payload['meta']['timezone'])->toBe('Asia/Tehran');
});

it('renders the same instant differently for each locale', function (
    string $locale,
    string $slugLocale,
    string $display,
    string $calendar,
): void {
    $slug = urlencode($this->content->getTranslation('slug', $slugLocale));

    $payload = getJson("/api/v1/news/{$slug}?locale={$locale}")
        ->assertOk()
        ->json('data');

    expect($payload['publish_date'])->toBe($this->publishedAt->toIso8601String())
        ->and($payload['publish_date_display'])->toBe($display)
        ->and($payload['meta']['calendar'])->toBe($calendar);
})->with([
    'Persian, Jalali calendar' => ['fa', 'fa', '۴ مهر ۱۴۰۵', 'persian'],
    'English, Gregorian' => ['en', 'en', '26 September 2026', 'gregorian'],
    // Arabic is a language, not a calendar: Gregorian months in Arabic words with
    // Arabic-Indic digits, which is how Arabic-language news is dated.
    'Arabic, Gregorian in Arabic' => ['ar', 'ar', '٢٦ سبتمبر ٢٠٢٦', 'gregorian'],
]);

it('reports a Hijri calendar when a site configures one', function (): void {
    config()->set('cms.dates.calendars.ar', 'islamic-umalqura');

    $slug = urlencode($this->content->getTranslation('slug', 'ar'));

    $payload = getJson("/api/v1/news/{$slug}?locale=ar")->assertOk()->json('data');

    expect($payload['publish_date_display'])->toBe('١٥ ربيع الآخر ١٤٤٨')
        // The frontend is told which calendar it got, so it can label it.
        ->and($payload['meta']['calendar'])->toBe('islamic-umalqura');
});

it('honours the configured display pattern', function (): void {
    config()->set('cms.dates.api.pattern', 'date_time');

    $slug = urlencode($this->content->getTranslation('slug', 'fa'));

    expect(getJson("/api/v1/news/{$slug}?locale=fa")->json('data.publish_date_display'))
        ->toBe('۱۴۰۵/۰۷/۰۴ ۱۲:۳۰');
});

it('leaves a display date null when there is no date', function (): void {
    $content = Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => null,
        'slug' => ['fa' => 'بدون-تاریخ'],
    ]);

    $payload = getJson('/api/v1/news/'.urlencode($content->getTranslation('slug', 'fa')).'?locale=fa')
        ->assertOk()
        ->json('data');

    expect($payload['publish_date'])->toBeNull()
        ->and($payload['publish_date_display'])->toBeNull();
});

it('localises gallery dates too', function (): void {
    $gallery = Gallery::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => $this->publishedAt,
        'slug' => ['fa' => 'گالری-آزمایشی'],
    ]);

    $payload = getJson('/api/v1/galleries/'.urlencode($gallery->getTranslation('slug', 'fa')).'?locale=fa')
        ->assertOk()
        ->json('data');

    expect($payload['publish_date'])->toBe($this->publishedAt->toIso8601String())
        ->and($payload['publish_date_display'])->toBe('۴ مهر ۱۴۰۵')
        ->and($payload['meta']['calendar'])->toBe('persian');
});

it('localises dates inside a listing, not just a single record', function (): void {
    $payload = getJson('/api/v1/news?locale=fa')->assertOk()->json('data');

    expect($payload)->not->toBeEmpty()
        ->and($payload[0]['publish_date_display'])->toBe('۴ مهر ۱۴۰۵');
});
