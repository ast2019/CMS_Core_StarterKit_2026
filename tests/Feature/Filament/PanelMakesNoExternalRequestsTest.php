<?php

declare(strict_types=1);

use App\Filament\Pages\AuditLog;
use App\Filament\Pages\EditorialCalendar;
use App\Filament\Pages\Settings;
use App\Filament\Pages\TranslationReview;
use App\Filament\Resources\Forms\FormResource;
use App\Models\Form;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/**
 * The admin panel makes no request to, and links to no, host but its own.
 *
 * architecture-rules.md: "No external network calls from the admin panel." It has to work
 * on a network with no outbound access, and every external reference is also a disclosure:
 * Filament's default avatar provider put the admin's initials into an https://ui-avatars.com
 * URL on every authenticated page, the MFA set-up page included, and the Settings page
 * linked each AI provider's documentation site.
 *
 * NoExternalCdnTest greps built assets for known CDN hosts. That could not have caught
 * either leak — both are generated at render time, and neither host is a CDN — so this
 * test renders the pages themselves and rejects ANY host but the application's own.
 */
beforeEach(function (): void {
    $this->seed();

    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

/**
 * Every URL the markup would make a browser request or offer as a link.
 *
 * Attribute names ENDING in src/href/action/poster/srcset, so Alpine's `x-load-src` and
 * SVG's `xlink:href` are covered as well as the plain ones, plus CSS url(). `xmlns`
 * attributes are namespace identifiers the browser never fetches, and do not match.
 *
 * @return list<string>
 */
function referencedUrls(string $html): array
{
    $urls = [];

    preg_match_all('/[\s"\'][\w:.-]*(?:src|href|action|poster)\s*=\s*(["\'])(.*?)\1/is', $html, $matches);
    array_push($urls, ...$matches[2]);

    preg_match_all('/[\s"\'][\w:.-]*srcset\s*=\s*(["\'])(.*?)\1/is', $html, $matches);

    foreach ($matches[2] as $srcset) {
        foreach (explode(',', $srcset) as $candidate) {
            $urls[] = strtok(trim($candidate), ' ') ?: '';
        }
    }

    preg_match_all('/url\(\s*(?:&quot;|["\'])?([^"\')&\s]+)/i', $html, $matches);
    array_push($urls, ...$matches[1]);

    return array_values(array_map(
        static fn (string $url): string => html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5),
        $urls,
    ));
}

/**
 * The URLs in $html pointing at a host other than the application's.
 *
 * @return list<string>
 */
function externalReferences(string $html): array
{
    $ownHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    return array_values(array_unique(array_filter(
        referencedUrls($html),
        static function (string $url) use ($ownHost): bool {
            // Absolute (scheme://host) or protocol-relative (//host). Everything else —
            // relative paths, fragments, data:, mailto:, javascript: — names no host.
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url) !== 1) {
                return false;
            }

            $host = strtolower((string) parse_url(str_starts_with($url, '//') ? 'http:'.$url : $url, PHP_URL_HOST));

            return $host !== $ownHost;
        },
    )));
}

function assertNoExternalReferences(string $html, string $where): void
{
    $external = externalReferences($html);

    expect($external)->toBe([], "{$where} references an external host: ".implode(', ', $external));
}

it('recognises an external reference when it sees one', function (): void {
    // The detector itself, so a regex that matched nothing could not pass the tests below.
    $html = '<img src="https://ui-avatars.com/api/?name=A"><a href="//cdn.example/x">x</a>'
        .'<div style="background: url(&quot;https://evil.example/a.png&quot;)"></div>'
        .'<img srcset="/local.png 1x, https://img.example/b.png 2x">'
        .'<svg xmlns="http://www.w3.org/2000/svg"></svg>'
        .'<img src="'.url('/ok.png').'"><img src="data:image/svg+xml;base64,AAAA"><a href="#top">t</a>';

    expect(externalReferences($html))->toBe([
        'https://ui-avatars.com/api/?name=A',
        '//cdn.example/x',
        'https://img.example/b.png',
        'https://evil.example/a.png',
    ]);
});

it('references no external host on the login page', function (): void {
    $response = $this->get(Filament::getPanel('admin')->getLoginUrl())->assertOk();

    assertNoExternalReferences($response->getContent() ?: '', 'The login page');
});

it('references no external host on the MFA set-up page', function (): void {
    // A user who has not enrolled is diverted here before anything else (RULE #5), so it
    // is the first authenticated page every new admin sees — avatar included.
    $user = User::factory()->admin()->create();

    $redirect = $this->actingAs($user)->get(Filament::getPanel('admin')->getUrl());
    $redirect->assertRedirect();

    $setUp = (string) $redirect->headers->get('Location');

    expect($setUp)->toContain('multi-factor');

    $response = $this->actingAs($user)->get($setUp)->assertOk();

    assertNoExternalReferences($response->getContent() ?: '', 'The MFA set-up page');
});

it('references no external host on the dashboard, its widgets or any custom page', function (): void {
    $this->actingAs($this->admin);

    $pages = Filament::getPanel('admin')->getPages();

    // The four custom pages by name, so dropping one from discovery fails loudly here
    // rather than quietly shrinking what this test covers.
    expect($pages)->toContain(Settings::class, AuditLog::class, TranslationReview::class, EditorialCalendar::class);

    foreach ($pages as $page) {
        $response = $this->get($page::getUrl())->assertOk();

        assertNoExternalReferences($response->getContent() ?: '', $page);
    }

    // Dashboard widgets load lazily, after the page, so their markup is not in the page
    // response above and is rendered on its own.
    foreach (Filament::getPanel('admin')->getWidgets() as $widget) {
        assertNoExternalReferences(Livewire::test($widget)->html(), $widget);
    }
});

it('references no external host on any resource page', function (): void {
    $this->actingAs($this->admin);

    $visited = 0;
    $unvisited = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        // Not every module is seeded (redirects and contact submissions arrive at
        // runtime); a factory record makes sure their edit/view pages are covered too.
        $record = $model::query()->first()
            ?? (method_exists($model, 'factory') ? $model::factory()->create() : null);

        foreach (array_keys($resource::getPages()) as $name) {
            if (in_array($name, ['index', 'create'], true)) {
                $url = $resource::getUrl($name);
            } elseif ($record !== null) {
                $url = $resource::getUrl($name, ['record' => $record]);
            } else {
                $unvisited[] = "{$resource} / {$name}";

                continue;
            }

            $response = $this->get($url)->assertOk();

            assertNoExternalReferences($response->getContent() ?: '', "{$resource} / {$name}");
            $visited++;
        }
    }

    // A resource with neither a seeded row nor a factory would otherwise have its record
    // pages skipped silently; give it a factory, or seed it.
    expect($unvisited)->toBe([], 'No record to render: '.implode(', ', $unvisited))
        ->and($visited)->toBeGreaterThan(20);
});

it('references no external host in the editor of a custom form', function (): void {
    /*
     * The resource loop reaches the Forms editor through the seeded `contact` form, whose structure
     * is locked, so the controls a custom form renders (type select, select options, add and remove)
     * are never checked there. The factory's form has a select and a checkbox.
     */
    $this->actingAs($this->admin);

    $form = Form::factory()->create();

    $response = $this->get(FormResource::getUrl('edit', ['record' => $form]))->assertOk();

    assertNoExternalReferences($response->getContent() ?: '', 'custom form editor');
});
