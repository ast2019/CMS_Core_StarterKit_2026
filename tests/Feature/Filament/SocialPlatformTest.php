<?php

declare(strict_types=1);

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteIdentity;
use App\Support\SocialPlatform;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Social links on the Settings page say which network each URL is (SocialPlatform).
 *
 * Derived from the URL, never stored: the setting stays the flat list of strings that
 * SiteIdentity, `sameAs` and the Delivery API read.
 */
it('detects the platform from the host', function (?string $url, SocialPlatform $platform): void {
    expect(SocialPlatform::fromUrl($url))->toBe($platform);
})->with([
    'instagram' => ['https://instagram.com/example', SocialPlatform::Instagram],
    'instagram www' => ['https://www.instagram.com/example/', SocialPlatform::Instagram],
    'telegram t.me' => ['https://t.me/example', SocialPlatform::Telegram],
    'telegram.me' => ['https://telegram.me/example', SocialPlatform::Telegram],
    'x' => ['https://x.com/example', SocialPlatform::X],
    'twitter' => ['https://twitter.com/example', SocialPlatform::X],
    'twitter mobile' => ['https://mobile.twitter.com/example', SocialPlatform::X],
    'linkedin' => ['https://www.linkedin.com/company/example', SocialPlatform::LinkedIn],
    'youtube' => ['https://www.youtube.com/@example', SocialPlatform::YouTube],
    'youtu.be' => ['https://youtu.be/abc123', SocialPlatform::YouTube],
    'youtube mobile' => ['https://m.youtube.com/@example', SocialPlatform::YouTube],
    'facebook' => ['https://facebook.com/example', SocialPlatform::Facebook],
    'fb.com' => ['https://fb.com/example', SocialPlatform::Facebook],
    'whatsapp wa.me' => ['https://wa.me/989120000000', SocialPlatform::WhatsApp],
    'whatsapp channel' => ['https://whatsapp.com/channel/abc', SocialPlatform::WhatsApp],
    'github' => ['https://github.com/example', SocialPlatform::GitHub],
    'aparat' => ['https://www.aparat.com/example', SocialPlatform::Aparat],
    'eitaa' => ['https://eitaa.com/example', SocialPlatform::Eitaa],
    'bale' => ['https://ble.ir/example', SocialPlatform::Bale],
    'rubika' => ['https://rubika.ir/example', SocialPlatform::Rubika],
    'upper case host' => ['HTTPS://WWW.INSTAGRAM.COM/Example', SocialPlatform::Instagram],
    'no scheme' => ['instagram.com/example', SocialPlatform::Instagram],
    'trailing dot' => ['https://t.me./example', SocialPlatform::Telegram],
    // A lookalike domain is not the platform: suffix matching is on a label boundary.
    'lookalike' => ['https://notinstagram.com/example', SocialPlatform::Website],
    'platform in path' => ['https://example.test/instagram.com', SocialPlatform::Website],
    'platform as subdomain of another site' => ['https://instagram.com.example.test/', SocialPlatform::Website],
    'other site' => ['https://example.test', SocialPlatform::Website],
    'empty' => ['', SocialPlatform::Website],
    'null' => [null, SocialPlatform::Website],
    'garbage' => ['not a url', SocialPlatform::Website],
]);

it('labels and draws every platform from local resources', function (SocialPlatform $platform): void {
    foreach (['fa', 'en', 'ar'] as $locale) {
        expect(trans("cms.social_platform.{$platform->value}", [], $locale))->not->toStartWith('cms.');
    }

    // Resolves through Blade Icons, so a missing file or an unregistered set fails here
    // rather than as a broken Settings page.
    $svg = svg($platform->icon())->toHtml();

    expect($svg)->toStartWith('<svg')
        ->and(preg_replace('/xmlns="[^"]*"/', '', $svg))->not->toMatch('#https?://#');
})->with(fn (): array => array_map(
    static fn (SocialPlatform $platform): array => [$platform],
    SocialPlatform::cases(),
));

it('names the Persian platforms in Persian', function (): void {
    app()->setLocale('fa');

    expect(SocialPlatform::Aparat->label())->toBe('آپارات')
        ->and(SocialPlatform::Eitaa->label())->toBe('ایتا')
        ->and(SocialPlatform::Bale->label())->toBe('بله')
        ->and(SocialPlatform::Rubika->label())->toBe('روبیکا');
});

it('ships each brand mark as a clean 24x24 currentColor SVG', function (): void {
    foreach (glob(resource_path('svg/social/*.svg')) ?: [] as $file) {
        $svg = (string) file_get_contents($file);

        expect($svg)->toContain('viewBox="0 0 24 24"')
            ->toContain('fill="currentColor"')
            ->not->toContain('<title>');
    }

    expect(file_exists(resource_path('svg/social/README.md')))->toBeTrue();
});

it('shows the platform of a stored social link on the Settings page', function (): void {
    actingAs(User::factory()->admin()->create());

    Setting::put(Setting::SOCIAL_LINKS, ['https://www.instagram.com/example', 'https://t.me/example']);

    // Instagram and Telegram because their names appear nowhere else on the page; «بله»
    // (Bale) is also Persian for "yes", so seeing it would prove nothing.
    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSee(SocialPlatform::Instagram->label())
        ->assertSee(SocialPlatform::Telegram->label());
});

it('stores the links as the same flat list of URL strings', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(Settings::class)
        ->fillForm([
            'site_name' => ['fa' => 'سایت آزمایشی'],
            'social_links' => [['url' => 'https://t.me/example'], ['url' => 'https://aparat.com/example']],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get(Setting::SOCIAL_LINKS))->toBe(['https://t.me/example', 'https://aparat.com/example']);
});

it('loads stored links into the inputs as plain URLs and saves them back unchanged', function (): void {
    /*
     * The page used to fill the simple() repeater with pre-wrapped ['url' => …] rows,
     * which the repeater wrapped again: each input's state was an array, shown in the
     * browser as "[object Object]", and the platform prefix had no URL to read.
     */
    actingAs(User::factory()->admin()->create());

    $links = ['https://www.instagram.com/example', 'https://ble.ir/example'];
    Setting::put(Setting::SOCIAL_LINKS, $links);

    $page = Livewire::test(Settings::class);

    expect(array_values(array_map(
        static fn (array $row): mixed => $row['url'] ?? null,
        $page->get('data.social_links'),
    )))->toBe($links);

    $page->fillForm(['site_name' => ['fa' => 'سایت آزمایشی']])
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteIdentity::socialLinks())->toBe($links);
});
