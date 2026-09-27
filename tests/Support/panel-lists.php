<?php

declare(strict_types=1);

use App\Enums\MediaRole;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Filament\Resources\Galleries\Pages\ListGalleries;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Filament\Resources\Slides\Pages\ListSlides;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Slide;
use App\Models\Tag;
use App\Models\User;

/*
 * Every resource list, with a factory that adds one row to it. Shared by the tests that hold a rule
 * for EVERY list (PanelQueryBudgetTest, AuthorisationProbeTest), so a new resource is added once.
 */

/**
 * @return array<string, array{class-string, Closure(): mixed}>
 */
function listPagesWithRowFactories(): array
{
    return [
        'articles' => [ListContents::class, function (): void {
            Content::factory()->create()->setFeaturedImage(MediaAsset::factory()->create());
        }],
        'pages' => [ListPages::class, fn () => Page::factory()->create()],
        'galleries' => [ListGalleries::class, function (): void {
            Gallery::factory()->create()->attachMediaAsset(MediaAsset::factory()->create(), MediaRole::Gallery);
        }],
        'slides' => [ListSlides::class, fn () => Slide::factory()->create(['is_active' => false])],
        'media' => [ListMediaAssets::class, fn () => MediaAsset::factory()->create()],
        'categories' => [ListCategories::class, fn () => Category::factory()->create([
            'parent_id' => Category::factory()->create()->getKey(),
        ])],
        'tags' => [ListTags::class, fn () => Tag::factory()->create()],
        'menu' => [ListMenuItems::class, fn () => MenuItem::factory()->create(['menu_key' => 'header'])],
        'inbox' => [ListContactSubmissions::class, fn () => ContactSubmission::factory()->create()],
        'users' => [ListUsers::class, fn () => User::factory()->editor()->create()],
        'redirects' => [ListRedirects::class, fn () => Redirect::factory()->create()],
    ];
}
