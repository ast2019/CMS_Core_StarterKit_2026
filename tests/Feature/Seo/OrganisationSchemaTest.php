<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Filament\Pages\Settings;
use App\Models\ContactSetting;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Setting;
use App\Models\User;
use App\Services\Seo\SchemaBuilder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The publisher's identity in the JSON-LD (Requirement 7.3).
 *
 * `organisation_schema` was a constant on the Setting model that nothing read: the
 * Organization node was built from `site_name` and `social_links` alone, so every
 * install published a name-only publisher — which SchemaBuilder's own comment called
 * the weakest publisher signal there is. These tests cover the setting becoming real,
 * and the type becoming the administrator's choice rather than a value baked into a
 * Core that is copied per client.
 */
beforeEach(function (): void {
    Setting::put(Setting::SITE_NAME, ['fa' => 'خبرگزاری نمونه'], isTranslatable: true);
});

it('emits a plain Organization until an administrator chooses otherwise', function (): void {
    /*
     * The no-op guarantee. Shipping this feature must not restate what any existing
     * site claims about itself, so an install that has never opened the new section
     * emits exactly the @type it emitted before.
     */
    expect(Setting::get(Setting::ORGANISATION_SCHEMA))->toBeNull();

    $organization = app(SchemaBuilder::class)->organization('fa');

    expect($organization)->not->toBeNull()
        ->and($organization['@type'])->toBe('Organization')
        ->and($organization['name'])->toBe('خبرگزاری نمونه');
});

it('emits the organisation type the administrator picked', function (OrganisationType $type): void {
    Setting::put(Setting::ORGANISATION_SCHEMA, ['type' => $type->value]);

    $organization = app(SchemaBuilder::class)->organization('fa');

    expect($organization['@type'])->toBe($type->value);
})->with(fn (): array => array_map(
    static fn (OrganisationType $type): array => [$type],
    OrganisationType::cases(),
));

it('falls back to a valid type when the stored value is not a known one', function (): void {
    // A hand-edited row, or a type dropped in a later release. Degrading beats throwing
    // a ValueError while rendering a page's SEO.
    Setting::put(Setting::ORGANISATION_SCHEMA, ['type' => 'NotARealSchemaType']);

    expect(app(SchemaBuilder::class)->organization('fa')['@type'])
        ->toBe(OrganisationType::default()->value);
});

it('emits the publisher logo as a measurable ImageObject', function (): void {
    $logo = MediaAsset::factory()->withFile()->create();

    Setting::put(Setting::ORGANISATION_SCHEMA, [
        'type' => OrganisationType::NewsMediaOrganization->value,
        'logo_media_asset_id' => $logo->getKey(),
    ]);

    $organization = app(SchemaBuilder::class)->organization('fa');

    /*
     * An ImageObject rather than a bare URL string: both validate, but a bare URL makes
     * the consumer fetch the file to learn its dimensions. This is the property Google
     * asks for on article markup and uses for a brand's knowledge panel.
     */
    expect($organization)->toHaveKey('logo')
        ->and($organization['logo']['@type'])->toBe('ImageObject')
        ->and($organization['logo']['url'])->toBeString();
});

it('omits the logo when the chosen asset has been deleted', function (): void {
    $logo = MediaAsset::factory()->withFile()->create();

    Setting::put(Setting::ORGANISATION_SCHEMA, ['logo_media_asset_id' => $logo->getKey()]);

    // MediaAsset is not soft-deleted, so an id stored here can genuinely stop existing.
    $logo->delete();

    expect(app(SchemaBuilder::class)->organization('fa'))->not->toHaveKey('logo');
});

it('omits every optional property that was left blank', function (): void {
    Setting::put(Setting::ORGANISATION_SCHEMA, ['type' => OrganisationType::Corporation->value]);

    $organization = app(SchemaBuilder::class)->organization('fa');

    /*
     * "Omit rather than guess", the rule SchemaBuilder follows throughout: an empty
     * property is reported as incomplete markup, which is worse than an absent one.
     */
    foreach (['logo', 'legalName', 'alternateName', 'description', 'foundingDate'] as $property) {
        expect($organization)->not->toHaveKey($property);
    }
});

it('emits the optional properties that were filled, per locale', function (): void {
    Setting::put(Setting::ORGANISATION_SCHEMA, [
        'type' => OrganisationType::NewsMediaOrganization->value,
        'legal_name' => 'مؤسسهٔ فرهنگی نمونه',
        'founding_date' => '2014-03-21',
        'alternate_name' => ['fa' => 'نمونه', 'en' => 'Sample'],
        'description' => ['fa' => 'یک خبرگزاری آزمایشی', 'en' => 'A sample news agency'],
    ]);

    $fa = app(SchemaBuilder::class)->organization('fa');
    $en = app(SchemaBuilder::class)->organization('en');

    expect($fa['legalName'])->toBe('مؤسسهٔ فرهنگی نمونه')
        ->and($fa['foundingDate'])->toBe('2014-03-21')
        ->and($fa['alternateName'])->toBe('نمونه')
        ->and($fa['description'])->toBe('یک خبرگزاری آزمایشی')
        // The same node in another locale carries that locale's strings.
        ->and($en['alternateName'])->toBe('Sample')
        ->and($en['description'])->toBe('A sample news agency');
});

it('builds a contactPoint from the contact settings rather than a second phone field', function (): void {
    ContactSetting::current()->update(['phone' => '+982112345678']);

    Setting::put(Setting::ORGANISATION_SCHEMA, ['type' => OrganisationType::Organization->value]);

    $organization = app(SchemaBuilder::class)->organization('fa');

    expect($organization['contactPoint']['telephone'])->toBe('+982112345678')
        ->and($organization['contactPoint']['@type'])->toBe('ContactPoint');
});

it('omits the contactPoint when no phone is configured', function (): void {
    ContactSetting::current()->update(['phone' => null]);

    expect(app(SchemaBuilder::class)->organization('fa'))->not->toHaveKey('contactPoint');
});

it('carries the configured publisher into an article graph', function (): void {
    $logo = MediaAsset::factory()->withFile()->create();

    Setting::put(Setting::ORGANISATION_SCHEMA, [
        'type' => OrganisationType::NewsMediaOrganization->value,
        'logo_media_asset_id' => $logo->getKey(),
    ]);

    $content = Content::factory()->published()->create();

    $graph = app(SchemaBuilder::class)->forArticle($content->fresh(), 'fa');

    $article = collect($graph)->firstWhere(
        fn (array $node): bool => in_array($node['@type'] ?? null, ['Article', 'NewsArticle', 'BlogPosting'], true),
    );

    $publisher = collect($graph)->firstWhere(
        fn (array $node): bool => ($node['@type'] ?? null) === 'NewsMediaOrganization',
    );

    expect($article)->not->toBeNull()
        ->and($publisher)->not->toBeNull()
        /*
         * The article REFERENCES the publisher by @id rather than repeating it — the
         * point of shipping a @graph, and why the Organization is anchored to the locale
         * home instead of to any one article. So the properties are asserted on the
         * standalone node, which is where a consumer resolves them from.
         */
        ->and($article['publisher']['@id'])->toBe($publisher['@id'])
        // The whole point: the publisher is no longer a name on its own.
        ->and($publisher)->toHaveKey('logo')
        ->and($publisher['logo']['@type'])->toBe('ImageObject');
});

it('persists the organisation profile from the Settings page', function (): void {
    actingAs(User::factory()->admin()->create());

    $logo = MediaAsset::factory()->withFile()->create();

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'خبرگزاری نمونه'],
            'organisation' => [
                'type' => OrganisationType::NewsMediaOrganization->value,
                'logo_media_asset_id' => $logo->getKey(),
                'legal_name' => 'مؤسسهٔ نمونه',
                'founding_date' => '2014-03-21',
                'alternate_name' => ['fa' => 'نمونه'],
                'description' => ['fa' => 'توضیح'],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::ORGANISATION_SCHEMA))->toBe([
        'type' => OrganisationType::NewsMediaOrganization->value,
        'logo_media_asset_id' => $logo->getKey(),
        'legal_name' => 'مؤسسهٔ نمونه',
        'founding_date' => '2014-03-21',
        'alternate_name' => ['fa' => 'نمونه'],
        'description' => ['fa' => 'توضیح'],
    ]);
});

it('refuses a founding date that is not an ISO date', function (): void {
    actingAs(User::factory()->admin()->create());

    /*
     * schema.org's foundingDate is a Date. A Jalali string would be emitted verbatim as
     * an invalid one, which is exactly the confidently-wrong output this kit avoids
     * elsewhere, so the field refuses it rather than publishing it.
     */
    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'خبرگزاری نمونه'],
            'organisation' => ['founding_date' => '۱۳۹۲/۱۲/۲۹'],
        ])
        ->call('save')
        ->assertHasFormErrors(['organisation.founding_date']);
});
