<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\Contents\Pages\CreateContent;
use App\Filament\Resources\Galleries\Pages\CreateGallery;
use App\Filament\Resources\Galleries\Pages\EditGallery;
use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Schemas\MediaAssetPicker;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

/**
 * Inline featured-image upload from inside a content form.
 *
 * RULE #7, RULE #8, RULE #9 — Requirements 2.4, 2.7, 3.2, 3.3.
 *
 * The workflow this covers: an editor half-way through an article needs a new
 * image and must not have to leave the form (losing unsaved work) to create the
 * library asset. The risk it covers is the opposite one — that a shortcut past the
 * Media section also skips the invariants that section enforced, and the library
 * quietly fills with alt-less, owner-less assets.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();

    // The media disk is named in config, never literally (RULE #9), so the fake is
    // registered under whatever that config says.
    $this->disk = (string) config('cms.media.disk', 'public');
    Storage::fake($this->disk);
});

/**
 * The payload the create-option modal submits.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inlineUploadPayload(array $overrides = []): array
{
    return [
        // A single FileUpload dehydrates to a uuid-keyed map, not a bare string.
        'file' => ['01JQ' => 'media-library/uploads/inline.png'],
        'alt_text' => ['fa' => 'توضیح تصویر آزمایشی'],
        'caption' => ['fa' => 'زیرنویس'],
        ...$overrides,
    ];
}

/**
 * Put a real PNG where the FileUpload would have stored it.
 */
function stageInlineUpload(string $disk, string $path = 'media-library/uploads/inline.png'): void
{
    Storage::disk($disk)->put($path, UploadedFile::fake()->image('inline.png', 64, 48)->get());
}

it('creates a real library asset from the inline upload', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    $id = MediaAssetPicker::createAsset(inlineUploadPayload());

    $asset = MediaAsset::findOrFail($id);

    /*
     * Decision D-3 — a library record, not a file bolted onto the article. If the
     * uploader had been bound to Content (which is not HasMedia) the image would
     * be invisible to the media module and unusable by a second article.
     */
    expect($asset->type)->toBe('image')
        ->and($asset->getTranslation('alt_text', 'fa'))->toBe('توضیح تصویر آزمایشی')
        ->and($asset->getFirstMedia('file'))->not->toBeNull()
        // RULE #9 — on the configured local disk, not a cloud bucket.
        ->and($asset->getFirstMedia('file')?->disk)->toBe($this->disk);
});

it('moves the staged file into the library rather than leaving a copy behind', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    MediaAssetPicker::createAsset(inlineUploadPayload());

    // Otherwise the staging directory grows by one orphaned copy of every image
    // ever uploaded inline, on the same disk that serves the site.
    expect(Storage::disk($this->disk)->exists('media-library/uploads/inline.png'))->toBeFalse();
});

it('records the file\'s own dimensions on the asset', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    $asset = MediaAsset::findOrFail(MediaAssetPicker::createAsset(inlineUploadPayload()));

    // Requirement 7.6 — the frontend reserves space from these, and the panel used
    // to leave them null for every upload it made.
    expect($asset->width)->toBe(64)
        ->and($asset->height)->toBe(48)
        ->and($asset->mime_type)->toBe('image/png')
        ->and($asset->size)->toBeGreaterThan(0);
});

it('refuses an inline upload with no alt text in the source locale', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    /*
     * Requirement 2.7. The modal marks the field required, but that is a schema —
     * the write path refuses too, because alt text is the one field nobody ever
     * goes back and fills in for a library of two thousand images.
     */
    expect(fn (): int => MediaAssetPicker::createAsset(inlineUploadPayload(['alt_text' => ['fa' => '']])))
        ->toThrow(ValidationException::class);

    expect(MediaAsset::count())->toBe(0);
});

it('refuses an inline upload with no file', function (): void {
    actingAs($this->admin);

    expect(fn (): int => MediaAssetPicker::createAsset(inlineUploadPayload(['file' => []])))
        ->toThrow(ValidationException::class);
});

it('stamps the acting user as the uploader', function (): void {
    $author = User::factory()->role(UserRole::Author)->withMfaEnrolled()->create();

    actingAs($author);
    stageInlineUpload($this->disk);

    $asset = MediaAsset::findOrFail(MediaAssetPicker::createAsset(inlineUploadPayload()));

    /*
     * MediaAssetPolicy::ownerColumn() is `uploaded_by`. Left null — as the panel
     * left it before this change — `media.update.own` matches nobody, so an Author
     * cannot correct the alt text of an image they uploaded a minute ago.
     */
    expect($asset->uploaded_by)->toBe($author->getKey())
        ->and($author->can('update', $asset))->toBeTrue();
});

it('drops locales the editor left blank', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    $asset = MediaAsset::findOrFail(MediaAssetPicker::createAsset(inlineUploadPayload([
        'alt_text' => ['fa' => 'توضیح فارسی', 'en' => '', 'ar' => null],
        'caption' => ['fa' => 'زیرنویس', 'en' => ''],
    ])));

    /*
     * A modal action does not inherit the page hooks in
     * InteractsWithTranslatableRecord, so without replicating the filter an
     * untouched English tab would persist as `['en' => null]` — which
     * hasAnyTranslationFor() reads as a real translation and the review queue then
     * lists as work already done.
     */
    expect(array_keys($asset->getTranslations('alt_text')))->toBe(['fa'])
        ->and(array_keys($asset->getTranslations('caption')))->toBe(['fa']);
});

it('logs the inline upload in the audit trail', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    $id = MediaAssetPicker::createAsset(inlineUploadPayload());

    // RULE #8 — no opt-out. The asset is created through Eloquent precisely so
    // IsAuditable sees it; a raw insert would have been invisible here.
    expect(Activity::query()
        ->where('subject_type', MediaAsset::class)
        ->where('subject_id', $id)
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

it('denies the inline upload to a user without the media.upload ability', function (): void {
    $viewer = User::factory()->role(UserRole::Viewer)->withMfaEnrolled()->create();

    actingAs($viewer);
    stageInlineUpload($this->disk);

    expect($viewer->role->hasAbility('media.upload'))->toBeFalse()
        ->and(MediaAssetPicker::canUpload())->toBeFalse();

    // Hidden in the UI is not enough: the modal is reachable over the wire by
    // anyone who can render the form, so the writing method refuses as well.
    expect(fn (): int => MediaAssetPicker::createAsset(inlineUploadPayload()))
        ->toThrow(HttpException::class);

    expect(MediaAsset::count())->toBe(0);
});

it('offers the inline uploader on the content form to a user who may upload', function (): void {
    actingAs($this->admin);

    Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertFormComponentActionVisible('featured_media_asset_id', 'createOption');
});

it('keeps the uploader gated on media.upload rather than on content access', function (): void {
    /*
     * The hole this closes: the content form must not become a way around
     * MediaAssetPolicy::create for anyone allowed to edit an article.
     *
     * Today no role in the D-10 matrix separates the two — every role that can
     * create content can also upload — so the gate cannot be observed through the
     * UI. Pinning that fact here is the point: if a role is ever added that writes
     * content without `media.upload`, this fails and whoever added it is told to
     * assert the action is hidden for them as well.
     */
    foreach (UserRole::cases() as $role) {
        if (! $role->hasAbility('content.create')) {
            continue;
        }

        expect($role->hasAbility('media.upload'))->toBeTrue(
            "Role {$role->value} can create content; add a hidden-uploader assertion for it.",
        );
    }

    // And the gate itself is the media ability, not a content one.
    actingAs(User::factory()->role(UserRole::Viewer)->withMfaEnrolled()->create());

    expect(MediaAssetPicker::canUpload())->toBeFalse();
});

it('attaches an inline-created asset as the featured image through the pivot', function (): void {
    actingAs($this->admin);
    stageInlineUpload($this->disk);

    $assetId = MediaAssetPicker::createAsset(inlineUploadPayload());

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'مطلبی با تصویر بارگذاریشده',
            'status' => ContentStatus::Draft->value,
            'featured_media_asset_id' => $assetId,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $content = Content::query()->firstOrFail();

    /*
     * Through setFeaturedImage(), so the singular-role replacement in
     * HasFeaturedImage governs it. The pivot is asserted directly because that is
     * where RULE #7's "exactly one" actually lives (Decision D-3).
     */
    expect($content->featuredImage()?->getKey())->toBe($assetId)
        ->and($content->mediaAssets()->wherePivot('role', MediaRole::Featured->value)->count())->toBe(1);
});

it('stamps the uploader on an asset created through the media resource', function (): void {
    actingAs($this->admin);

    Livewire::test(CreateMediaAsset::class)
        ->fillForm([
            'type' => 'image',
            'alt_text.fa' => 'تصویری از کتابخانه',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // The same defect as the inline path had: the library form never set it
    // either, so every asset in the panel had a NULL owner.
    expect(MediaAsset::query()->firstOrFail()->uploaded_by)->toBe($this->admin->getKey());
});

it('persists the gallery item list an editor chose', function (): void {
    actingAs($this->admin);

    $cover = MediaAsset::factory()->create();
    $first = MediaAsset::factory()->create();
    $second = MediaAsset::factory()->create();

    /*
     * Decision D-4. Before ManagesGalleryItems existed the field was
     * dehydrated(false) and nothing read it back, so an editor could pick twenty
     * images, save, and get an empty gallery with no error to explain it.
     */
    Livewire::test(CreateGallery::class)
        ->fillForm([
            'title.fa' => 'گالری آزمایشی',
            'status' => ContentStatus::Draft->value,
            'featured_media_asset_id' => $cover->getKey(),
            'gallery_item_ids' => [$second->getKey(), $first->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $gallery = Gallery::query()->firstOrFail();

    expect($gallery->cover()?->getKey())->toBe($cover->getKey())
        // Order is the data: the pivot position carries what the editor arranged.
        ->and($gallery->items()->pluck('media_assets.id')->all())
        ->toBe([$second->getKey(), $first->getKey()]);
});

it('replaces the gallery item list on edit rather than appending to it', function (): void {
    actingAs($this->admin);

    $cover = MediaAsset::factory()->create();
    $kept = MediaAsset::factory()->create();
    $removed = MediaAsset::factory()->create();

    $gallery = Gallery::factory()->create();
    $gallery->setFeaturedImage($cover);
    $gallery->attachMediaAsset($kept, MediaRole::Gallery, 0);
    $gallery->attachMediaAsset($removed, MediaRole::Gallery, 1);

    Livewire::test(EditGallery::class, ['record' => $gallery->getRouteKey()])
        ->assertOk()
        ->fillForm(['gallery_item_ids' => [$kept->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($gallery->fresh()?->items()->pluck('media_assets.id')->all())->toBe([$kept->getKey()]);
});
