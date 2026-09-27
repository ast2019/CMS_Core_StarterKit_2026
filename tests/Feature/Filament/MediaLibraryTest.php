<?php

declare(strict_types=1);

use App\Enums\MediaRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Contents\ContentResource;
use App\Filament\Resources\MediaAssets\Actions\ReplaceFileAction;
use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Content\UsageInspector;
use App\Services\Media\VideoMetadataExtractor;
use App\Support\Plural;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 12 — the media library knows where its files are used, and what they may be
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake((string) config('cms.media.disk', 'public'));
});

function setSiteLogo(MediaAsset $asset): void
{
    // Where the Settings page stores it.
    Setting::put(Setting::ORGANISATION_SCHEMA, ['logo_media_asset_id' => $asset->getKey()]);
}

function pdfUpload(string $name = 'report.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/**
 * A minimal .docx, written the way Word writes one: `[Content_Types].xml` first, which is what
 * libmagic keys on.
 */
function docxUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
    $zip->close();

    return UploadedFile::fake()->createWithContent('letter.docx', (string) file_get_contents($path));
}

// ---------------------------------------------------------------------------
// 1. The "unused media" filter
// ---------------------------------------------------------------------------

it('filters the library to assets nothing is attached to', function (): void {
    actingAs(User::factory()->admin()->create());

    $featured = MediaAsset::factory()->create();
    Content::factory()->create()->setFeaturedImage($featured);

    // An owner in the trash still holds its attachment, and restoring it must find its image.
    $inTrashedGallery = MediaAsset::factory()->create();
    $gallery = Gallery::factory()->create();
    $gallery->attachMediaAsset($inTrashedGallery, MediaRole::Gallery);
    $gallery->delete();

    $logo = MediaAsset::factory()->create();
    setSiteLogo($logo);

    $unused = MediaAsset::factory()->create();

    Livewire::test(ListMediaAssets::class)
        ->filterTable('unattached')
        ->assertCanSeeTableRecords([$unused])
        ->assertCanNotSeeTableRecords([$featured, $inTrashedGallery, $logo])
        ->assertSee(__('cms.filter.unattached_indicator'));
});

// ---------------------------------------------------------------------------
// 2. "Where is this used" on the asset's own page
// ---------------------------------------------------------------------------

it('lists every record an asset is attached to, with links', function (): void {
    actingAs(User::factory()->editor()->create());

    $asset = MediaAsset::factory()->create();

    $article = Content::factory()->create(['title' => ['fa' => 'خبر با تصویر شاخص']]);
    $article->setFeaturedImage($asset);

    $page = Page::factory()->create(['title' => ['fa' => 'برگهٔ درباره']]);
    $page->attachMediaAsset($asset, MediaRole::OgImage);

    $gallery = Gallery::factory()->create(['title' => ['fa' => 'گالری حذف‌شده']]);
    $gallery->attachMediaAsset($asset, MediaRole::Gallery);
    $gallery->delete();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertOk()
        ->assertSee(__('cms.media.usage.heading'))
        ->assertSee(__('cms.media.usage.caveat'))
        ->assertSee('خبر با تصویر شاخص')
        ->assertSee('برگهٔ درباره')
        ->assertSee('گالری حذف‌شده')
        ->assertSee(MediaRole::Featured->label())
        ->assertSee(MediaRole::OgImage->label())
        ->assertSee(MediaRole::Gallery->label())
        ->assertSee(__('cms.media.usage.trashed'))
        ->assertSeeHtml('href="'.e(ContentResource::getUrl('edit', ['record' => $article])).'"');
});

it('names the site logo among the uses, linking to Settings only for those who can open it', function (): void {
    $asset = MediaAsset::factory()->create();
    setSiteLogo($asset);

    actingAs(User::factory()->admin()->create());

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertSee(__('cms.media.usage.logo'))
        ->assertSeeHtml('href="'.e(Settings::getUrl()).'"');

    actingAs(User::factory()->editor()->create());

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertSee(__('cms.media.usage.logo'))
        ->assertDontSeeHtml('href="'.e(Settings::getUrl()).'"');
});

it('says plainly when an asset is attached to nothing', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->create();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertSee(__('cms.media.usage.none'))
        // The caveat stays: "attached to nothing" is not "used nowhere".
        ->assertSee(__('cms.media.usage.caveat'));
});

it('does not link a record the user may not edit, and does not audit deciding so', function (): void {
    $asset = MediaAsset::factory()->create();
    $colleagues = Content::factory()->create(['title' => ['fa' => 'خبر همکار']]);
    $colleagues->setFeaturedImage($asset);

    // An Author may view the library but may edit only their own articles.
    $author = User::factory()->author()->create();
    $asset->update(['uploaded_by' => $author->getKey()]);
    actingAs($author);

    $refusedEdits = fn (): int => Activity::query()
        ->where('event', 'denied')
        ->where('subject_type', $colleagues->getMorphClass())
        ->count();

    $before = $refusedEdits();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertSee('خبر همکار')
        ->assertDontSeeHtml('href="'.e(ContentResource::getUrl('edit', ['record' => $colleagues])).'"');

    // Scoped to the article: the page's own delete button is a separate matter (see PR #22).
    expect($refusedEdits())->toBe($before);
});

it('lists the first fifty uses by name and counts the rest', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->create();

    foreach (Gallery::factory()->count(52)->create() as $gallery) {
        $gallery->attachMediaAsset($asset, MediaRole::Gallery);
    }

    $references = app(UsageInspector::class)->mediaReferences($asset, 50);

    expect($references['total'])->toBe(52)
        ->and($references['items'])->toHaveCount(50);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertSee('۲ مورد دیگر');
});

it('costs the same number of queries however many records use the asset', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->create();

    $attach = function () use ($asset): void {
        Content::factory()->create()->setFeaturedImage($asset);
        Page::factory()->create()->attachMediaAsset($asset, MediaRole::OgImage);
        Gallery::factory()->create()->attachMediaAsset($asset, MediaRole::Gallery);
    };

    $count = function () use ($asset): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $attach();
    $count(); // Warm up: the first render also fills per-request caches (settings, permissions).
    $few = $count();

    foreach (range(1, 4) as $i) {
        $attach();
    }

    expect($count())->toBe($few);
});

// ---------------------------------------------------------------------------
// 3. Replacing the file
// ---------------------------------------------------------------------------

it('warns where the file is used before replacing it', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->withFile()->create();
    Content::factory()->create()->setFeaturedImage($asset);

    setSiteLogo($asset);

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->mountAction('replaceFile')
        ->assertActionMounted('replaceFile');

    // The modal's description, as the action builds it.
    expect(ReplaceFileAction::warning($asset))
        ->toContain(Plural::choice('cms.usage.label.attached_to_content', 1))
        ->toContain(__('cms.media.replace.logo'))
        ->toContain(__('cms.media.usage.caveat'))
        ->toContain(__('cms.media.replace.previous_deleted'))
        ->not->toContain(__('cms.media.replace.video'));
});

it('replaces the file, re-reads its measurements, deletes the old one and records it', function (): void {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $asset = MediaAsset::factory()->withFile()->create();
    $asset->syncFileMetadata();
    $old = $asset->getFirstMedia('file');
    $oldPath = $old->getPathRelativeToRoot();
    $oldUpdatedAt = $asset->fresh()->updated_at;

    $this->travel(1)->minute();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->callAction('replaceFile', ['file' => UploadedFile::fake()->image('new.jpg', 320, 200)])
        ->assertHasNoActionErrors()
        ->assertNotified(__('cms.media.replace.done'));

    $asset->refresh()->load('media');
    $new = $asset->getFirstMedia('file');

    expect($asset->getMedia('file'))->toHaveCount(1)
        ->and($new->getKey())->not->toBe($old->getKey())
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->width)->toBe(320)
        ->and($asset->height)->toBe(200)
        // The save that clears the Delivery cache and tells the frontend.
        ->and($asset->updated_at->greaterThan($oldUpdatedAt))->toBeTrue()
        ->and(Storage::disk($old->disk)->exists($oldPath))->toBeFalse();

    $audit = Activity::query()->where('description', 'MediaAsset.file_replaced')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->causer_id)->toBe($admin->getKey())
        ->and($audit->subject_id)->toBe($asset->getKey())
        ->and($audit->properties['attributes']['file'] ?? null)->toBe($new->file_name)
        ->and($audit->properties['old']['file'] ?? null)->toBe($old->file_name);
});

it('clears a video duration that described the old file', function (): void {
    actingAs(User::factory()->admin()->create());

    // No ffprobe: nothing can read the new file, so the old value must not survive either.
    app()->instance(VideoMetadataExtractor::class, new class extends VideoMetadataExtractor
    {
        public function __construct() {}

        public function probe(string $absolutePath): array
        {
            return ['duration_seconds' => null, 'width' => null, 'height' => null];
        }
    });

    $asset = MediaAsset::factory()->create(['type' => 'video', 'mime_type' => 'video/mp4', 'duration_seconds' => 90]);

    Storage::disk((string) config('cms.media.disk', 'public'))
        ->put('media-library/uploads/clip.mp4', 'stand-in bytes');

    ReplaceFileAction::replace($asset, 'media-library/uploads/clip.mp4');

    expect($asset->refresh()->duration_seconds)->toBeNull();
});

it('keeps the measurements the video probe reads from the NEW file', function (): void {
    /*
     * The probe runs as soon as the media is added (synchronously here, on a worker in production)
     * and fills only empty fields. Clearing the old values after adding the media let it see them,
     * skip, and then lose everything.
     */
    actingAs(User::factory()->admin()->create());

    app()->instance(VideoMetadataExtractor::class, new class extends VideoMetadataExtractor
    {
        public function __construct() {}

        public function probe(string $absolutePath): array
        {
            return ['duration_seconds' => 42, 'width' => 1280, 'height' => 720];
        }
    });

    $asset = MediaAsset::factory()->create([
        'type' => 'video', 'mime_type' => 'video/mp4', 'duration_seconds' => 90, 'width' => 640, 'height' => 360,
    ]);

    Storage::disk((string) config('cms.media.disk', 'public'))
        ->put('media-library/uploads/clip.mp4', 'stand-in bytes');

    ReplaceFileAction::replace($asset, 'media-library/uploads/clip.mp4');

    $asset->refresh();

    expect($asset->duration_seconds)->toBe(42)
        ->and($asset->width)->toBe(1280)
        ->and($asset->height)->toBe(720);
});

it('keeps the new file when the form is saved after a replacement', function (): void {
    /*
     * The locked upload field still saves its relationship, and Filament deletes whatever media the
     * field's state no longer names. If the page kept the OLD file in that state, the next save would
     * delete the file that had just been put in.
     */
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->withFile()->create();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->callAction('replaceFile', ['file' => UploadedFile::fake()->image('new.jpg', 320, 200)])
        ->fillForm(['alt_text' => ['fa' => 'متن جایگزین تازه']])
        ->call('save')
        ->assertHasNoFormErrors();

    $asset->refresh()->load('media');

    expect($asset->getMedia('file'))->toHaveCount(1)
        ->and($asset->getFirstMedia('file')->mime_type)->toBe('image/jpeg')
        ->and($asset->getTranslation('alt_text', 'fa'))->toBe('متن جایگزین تازه');
});

it('refuses a replacement file the asset type cannot hold', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->withFile()->create();
    $before = $asset->getFirstMedia('file')->getKey();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->callAction('replaceFile', ['file' => pdfUpload()])
        ->assertHasActionErrors(['file']);

    expect($asset->refresh()->getFirstMedia('file')->getKey())->toBe($before);
});

it('offers replacement only to someone who may edit the asset', function (): void {
    $asset = MediaAsset::factory()->withFile()->create();

    actingAs(User::factory()->viewer()->create());

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertForbidden();

    actingAs(User::factory()->editor()->create());

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertActionVisible('replaceFile');
});

it('locks the file field on an existing asset, so the warning cannot be skipped', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->withFile()->create();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->assertFormFieldIsDisabled('file');

    Livewire::test(CreateMediaAsset::class)
        ->assertFormFieldIsEnabled('file');
});

// ---------------------------------------------------------------------------
// 4. Upload MIME types follow the asset type
// ---------------------------------------------------------------------------

it('accepts a file only when its content matches the chosen type', function (string $type, Closure $file, bool $accepted): void {
    actingAs(User::factory()->admin()->create());

    $test = Livewire::test(CreateMediaAsset::class)
        ->fillForm([
            'type' => $type,
            'file' => $file(),
            'alt_text' => ['fa' => 'توضیح آزمایشی'],
        ])
        ->call('create');

    $accepted
        ? $test->assertHasNoFormErrors()
        : $test->assertHasFormErrors(['file']);
})->with([
    'png as image' => ['image', fn () => UploadedFile::fake()->image('photo.png'), true],
    'pdf as document' => ['document', fn () => pdfUpload(), true],
    'pdf as image' => ['image', fn () => pdfUpload(), false],
    'png as document' => ['document', fn () => UploadedFile::fake()->image('photo.png'), false],
    'text renamed .png' => ['image', fn () => UploadedFile::fake()->createWithContent('photo.png', 'not an image at all'), false],
    'txt as document' => ['document', fn () => UploadedFile::fake()->createWithContent('notes.txt', 'plain notes'), true],
    'csv as document' => ['document', fn () => UploadedFile::fake()->createWithContent('rows.csv', "a,b\n1,2\n"), true],
    'docx as document' => ['document', fn () => docxUpload(), true],
    // Content that libmagic calls text/plain, under a name the web server would serve as a page.
    'html named text as document' => ['document', fn () => UploadedFile::fake()->createWithContent(
        'evil.html',
        str_repeat("plain words\n", 500).'<script>alert(1)</script>',
    ), false],
    'png named .html as image' => ['image', fn () => UploadedFile::fake()->createWithContent(
        'photo.html',
        UploadedFile::fake()->image('x.png')->get(),
    ), false],
    'svg as image' => ['image', fn () => UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    ), false],
]);

it('refuses to change the type of an asset to one its file does not fit', function (): void {
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->withFile()->create();

    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->fillForm(['type' => 'document'])
        ->call('save')
        ->assertHasFormErrors(['type']);

    expect($asset->refresh()->type)->toBe('image');

    // The same rule for writers that are not the form.
    expect(fn () => $asset->update(['type' => 'video']))->toThrow(ValidationException::class);
});

it('lets an old row with a mismatched file be edited as long as the type is left alone', function (): void {
    actingAs(User::factory()->admin()->create());

    // An SVG uploaded before the allowlist existed.
    $asset = MediaAsset::factory()->create(['type' => 'image', 'mime_type' => 'image/svg+xml']);

    $asset->update(['caption' => ['fa' => 'زیرنویس تازه']]);

    expect($asset->refresh()->getTranslation('caption', 'fa'))->toBe('زیرنویس تازه');

    // And through the form, which validates the type field on every save.
    Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()])
        ->fillForm(['alt_text' => ['fa' => 'متن جایگزین تازه']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($asset->refresh()->getTranslation('alt_text', 'fa'))->toBe('متن جایگزین تازه');
});

// ---------------------------------------------------------------------------
// Found on the way: the site logo was not protected at all
// ---------------------------------------------------------------------------

it('refuses to trash the logo chosen on the Settings page', function (): void {
    $asset = MediaAsset::factory()->create();
    setSiteLogo($asset);

    expect(fn () => $asset->delete())->toThrow(ValidationException::class)
        ->and($asset->refresh()->trashed())->toBeFalse();
});
