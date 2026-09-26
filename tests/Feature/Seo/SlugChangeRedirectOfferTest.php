<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Concerns\OffersRedirectsForChangedSlugs;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Galleries\Pages\EditGallery;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use App\Services\Content\RedirectSuggestionService;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Requirement 7.5 — the 301 offer, on all four routable types.
 *
 * offerRedirectsForChangedSlugs() lived on EditContent, so renaming the slug of a
 * published Page, Gallery or Category broke its URL with no prompt: every one of them
 * is routable, every one is in the locale sitemaps, and RedirectSuggestionService was
 * already model-agnostic. The only thing tying the feature to articles was where the
 * method happened to be written.
 *
 * Asserted through the PANEL rather than through the service, because the service was
 * never the broken part — tests/Feature/Seo/RedirectEngineTest.php already covered it.
 * What was missing was the wiring, and only a page test can see that.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('offers a 301 when a published page changes slug', function (): void {
    actingAs($this->admin);

    $page = Page::factory()->create([
        'status' => ContentStatus::Published->value,
        'publish_date' => now()->subDay(),
        'slug' => ['fa' => 'درباره-ما'],
    ]);
    // RULE #7 — the form refuses to save without a featured image.
    $page->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['slug.fa' => 'درباره-شرکت'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('cms.redirect.slug_changed_title'));

    /*
     * A page's URL has no type segment (/fa/about, not /fa/page/about), which is why
     * it is the type most likely to be linked from print and email — and the one where
     * a silent rename costs most.
     */
    expect(app(RedirectSuggestionService::class)
        ->pendingFor($page->fresh(), ['fa' => 'درباره-ما']))
        ->toHaveKey('fa');
});

it('offers a 301 when a published gallery changes slug', function (): void {
    actingAs($this->admin);

    $gallery = Gallery::factory()->create([
        'status' => ContentStatus::Published->value,
        'publish_date' => now()->subDay(),
        'slug' => ['fa' => 'گالری-افتتاحیه'],
    ]);
    $gallery->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditGallery::class, ['record' => $gallery->getRouteKey()])
        ->fillForm(['slug.fa' => 'گالری-مراسم'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('cms.redirect.slug_changed_title'));
});

it('offers a 301 when a category changes slug, which has no draft state to hide in', function (): void {
    actingAs($this->admin);

    $category = Category::factory()->create(['slug' => ['fa' => 'اخبار-شهری']]);

    /*
     * A Category implements no publish workflow, so HasSeoMeta::isPubliclyVisible()
     * answers true for it — which is exactly right and is why the shared concern gates
     * on that rather than on isLive(). There is no draft state in which a category's
     * typo can be fixed for free: every rename is a live rename.
     */
    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['slug.fa' => 'اخبار-استان'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('cms.redirect.slug_changed_title'));
});

it('still offers a 301 for an article, which is where this started', function (): void {
    actingAs($this->admin);

    $content = Content::factory()->published()->create(['slug' => ['fa' => 'خبر-اول']]);
    $content->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm(['slug.fa' => 'خبر-دوم'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('cms.redirect.slug_changed_title'));
});

it('offers nothing when a DRAFT changes slug', function (): void {
    actingAs($this->admin);

    // Nothing ever resolved the old URL, so a redirect would be a dead row and the
    // prompt would be noise the editor learns to dismiss.
    $page = Page::factory()->create([
        'status' => ContentStatus::Draft->value,
        'slug' => ['fa' => 'پیشنویس'],
    ]);
    $page->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['slug.fa' => 'پیشنویس-دوم'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('cms.redirect.slug_changed_title'));
});

it('offers nothing when the HOMEPAGE changes slug', function (): void {
    actingAs($this->admin);

    /*
     * Stage 2's rule: the homepage answers at /{locale}, so its slug is not part of any
     * public URL and renaming it breaks nothing. A 301 from /fa/old-home would point
     * away from a URL that was never reachable, and a redirect table full of those is
     * how the engine loses an editor's trust.
     */
    $home = Page::factory()->homePage()->create(['slug' => ['fa' => 'صفحه-اصلی']]);
    $home->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditPage::class, ['record' => $home->getRouteKey()])
        ->fillForm(['slug.fa' => 'خانه'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('cms.redirect.slug_changed_title'));

    expect(Redirect::query()->count())->toBe(0);
});

it('creates the redirect only when the editor accepts the offer', function (): void {
    actingAs($this->admin);

    $category = Category::factory()->create(['slug' => ['fa' => 'ورزشی']]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['slug.fa' => 'ورزش'])
        ->call('save')
        ->assertHasNoFormErrors();

    /*
     * Offered, not created. A slug corrected three times while drafting would
     * otherwise leave two dead hops behind, and a redirect chain costs more for
     * crawlers and page speed than no redirect at all.
     */
    expect(Redirect::query()->count())->toBe(0);

    $created = app(RedirectSuggestionService::class)->create(
        $category->fresh(),
        ['fa' => ['from' => '/fa/category/ورزشی', 'to' => '/fa/category/ورزش']],
    );

    expect($created)->toBe(1)
        ->and(Redirect::query()->where('from_path', '/fa/category/ورزشی')->exists())->toBeTrue();
});

it('offers nothing for a type whose module is switched off', function (): void {
    /*
     * Requirement 1.1: a disabled module contributes nothing public, so a 301 for one
     * of its records would point from a 404 to a 404.
     *
     * Asserted against the concern's gate rather than through the panel, because with
     * the module off the resource itself is not registered — the Livewire component
     * cannot be mounted at all, which is a different (and already tested) behaviour.
     */
    $probe = new class
    {
        use OffersRedirectsForChangedSlugs;

        public function gate(Model $record): bool
        {
            return $this->shouldOfferRedirectsFor($record);
        }
    };

    $gallery = Gallery::factory()->create([
        'status' => ContentStatus::Published->value,
        'publish_date' => now()->subDay(),
    ]);

    expect($probe->gate($gallery))->toBeTrue();

    config()->set('cms.modules.gallery', false);

    expect($probe->gate($gallery))->toBeFalse();
});

it('gates on public visibility rather than on a status column', function (): void {
    $probe = new class
    {
        use OffersRedirectsForChangedSlugs;

        public function gate(Model $record): bool
        {
            return $this->shouldOfferRedirectsFor($record);
        }
    };

    /*
     * The three answers that make isPubliclyVisible() the right question:
     * a Category has no status and is always public; a draft article is not; and a
     * published article with a FUTURE publish date is scheduled, not live
     * (Requirement 3.6) — so its old URL was never reachable either.
     */
    expect($probe->gate(Category::factory()->create()))->toBeTrue()
        ->and($probe->gate(Content::factory()->create()))->toBeFalse()
        ->and($probe->gate(Content::factory()->scheduled()->create()))->toBeFalse()
        ->and($probe->gate(Content::factory()->published()->create()))->toBeTrue();
});
