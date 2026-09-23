<?php

declare(strict_types=1);

use App\Enums\RedirectType;
use App\Models\Content;
use App\Models\Redirect;
use App\Services\Content\RedirectSuggestionService;

use function Pest\Laravel\get;

/**
 * Requirement 7.5.
 */
it('redirects a stored path with its configured status code', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/old-path',
        'to_path' => '/fa/news/new-path',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/old-path')
        ->assertStatus(301)
        ->assertRedirect('/fa/news/new-path');
});

it('honours a temporary redirect', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/campaign',
        'to_path' => '/fa/news/launch',
        'type' => RedirectType::Temporary,
    ]);

    get('/fa/campaign')->assertStatus(302);
});

it('normalises the stored path so a trailing slash still matches', function (): void {
    // Editors paste inconsistently; a redirect that works for /x but not /x/ is a
    // support ticket waiting to happen.
    Redirect::query()->create([
        'from_path' => 'https://example.test/fa/pasted-full-url/',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    $redirect = Redirect::query()->firstOrFail();

    // The host is stripped: storing it would break every redirect on a domain change.
    expect($redirect->from_path)->toBe('/fa/pasted-full-url');

    get('/fa/pasted-full-url')->assertStatus(301);
});

it('collapses a chain into a single hop', function (): void {
    /*
     * Chains happen legitimately when a slug changes twice. Each hop costs the
     * visitor a round trip and crawlers stop following after a few, so the chain is
     * resolved server-side and the visitor is sent straight to the end.
     */
    Redirect::query()->create(['from_path' => '/fa/a', 'to_path' => '/fa/b', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/b', 'to_path' => '/fa/c', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/c', 'to_path' => '/fa/final', 'type' => RedirectType::Permanent]);

    get('/fa/a')
        ->assertStatus(301)
        ->assertRedirect('/fa/final');
});

it('does not loop when a cycle exists in the data', function (): void {
    /*
     * The form validation refuses a self-referential redirect, but a cycle can still
     * be assembled from two separately-valid rows. Serving the request normally is the
     * only safe outcome: following it would loop the browser, and a 500 would take
     * down a URL that might otherwise resolve.
     */
    Redirect::query()->create(['from_path' => '/fa/x', 'to_path' => '/fa/y', 'type' => RedirectType::Permanent]);
    Redirect::query()->create(['from_path' => '/fa/y', 'to_path' => '/fa/x', 'type' => RedirectType::Permanent]);

    $response = get('/fa/x');

    expect($response->status())->not->toBe(301)
        ->and($response->status())->not->toBe(302);
});

it('preserves the incoming query string', function (): void {
    // Dropping it would break campaign tracking and paginated links across every
    // renamed URL — a wide regression that is hard to attribute.
    Redirect::query()->create([
        'from_path' => '/fa/promo',
        'to_path' => '/fa/news/offer',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/promo?utm_source=newsletter&page=2')
        ->assertRedirect('/fa/news/offer?utm_source=newsletter&page=2');
});

it('does not override a query string the target already defines', function (): void {
    Redirect::query()->create([
        'from_path' => '/fa/legacy',
        'to_path' => '/fa/news/index?sort=latest',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/legacy?sort=oldest')->assertRedirect('/fa/news/index?sort=latest');
});

it('counts hits without firing model events', function (): void {
    $redirect = Redirect::query()->create([
        'from_path' => '/fa/counted',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    get('/fa/counted');
    get('/fa/counted');

    $redirect->refresh();

    // Makes dead redirects visible so the table can be pruned on evidence.
    expect($redirect->hits)->toBe(2)
        ->and($redirect->last_hit_at)->not->toBeNull();
});

it('leaves unmatched paths alone', function (): void {
    get('/fa/no-redirect-here')->assertNotFound();
});

it('can be switched off with the module toggle', function (): void {
    // Requirement 1.1.
    Redirect::query()->create([
        'from_path' => '/fa/toggled',
        'to_path' => '/fa/news/target',
        'type' => RedirectType::Permanent,
    ]);

    config()->set('cms.modules.redirect', false);
    Redirect::forgetCache();

    get('/fa/toggled')->assertNotFound();
});

it('suggests a 301 when a published article changes slug', function (): void {
    $content = Content::factory()->published()->create(['title' => ['fa' => 'عنوان اول']]);
    $before = $content->getTranslations('slug');

    $content->setTranslation('slug', 'fa', 'عنوان-دوم');
    $content->save();

    $pending = app(RedirectSuggestionService::class)->pendingFor($content, $before);

    expect($pending)->toHaveKey('fa')
        ->and($pending['fa']['from'])->toBe('/fa/news/'.$before['fa'])
        ->and($pending['fa']['to'])->toBe('/fa/news/عنوان-دوم');

    $created = app(RedirectSuggestionService::class)->create($content, $pending);

    expect($created)->toBe(1)
        ->and(Redirect::query()->where('from_path', '/fa/news/'.$before['fa'])->exists())->toBeTrue();
});

it('does not re-suggest a redirect that already exists', function (): void {
    // Otherwise every subsequent save raises the same notification forever.
    $content = Content::factory()->published()->create(['title' => ['fa' => 'عنوان اول']]);
    $before = $content->getTranslations('slug');

    $content->setTranslation('slug', 'fa', 'عنوان-دوم');
    $content->save();

    $service = app(RedirectSuggestionService::class);
    $service->create($content, $service->pendingFor($content, $before));

    expect($service->pendingFor($content, $before))->toBeEmpty();
});

it('treats a first-time slug as no change', function (): void {
    // There was no old URL to redirect from.
    $content = Content::factory()->published()->create();

    $pending = app(RedirectSuggestionService::class)->pendingFor($content, ['fa' => null]);

    expect($pending)->toBeEmpty();
});
