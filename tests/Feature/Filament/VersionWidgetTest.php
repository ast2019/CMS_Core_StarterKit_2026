<?php

declare(strict_types=1);

use App\Filament\Widgets\VersionWidget;
use App\Models\Changelog;
use App\Models\SystemInfo;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The About (دربارهٔ سیستم) card. Its long release list is folded into a
 * collapsible section that opens collapsed, so these tests guard that
 * collapsing never means "removed from the DOM": a collapsed Filament section
 * still renders its content.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();
});

it('shows the version and install date on the About card', function (): void {
    actingAs($this->admin);

    $info = SystemInfo::current();

    Livewire::test(VersionWidget::class)
        ->assertOk()
        ->assertSee(__('cms.system.about'))
        ->assertSee($info->version)
        ->assertSee(LocalizedDate::format($info->installed_at, 'date'));
});

it('keeps every recent release reachable even though the list starts collapsed', function (): void {
    actingAs($this->admin);

    $release = Changelog::query()->create([
        'version' => '9.9.9',
        'entries' => ['added' => ['A brand new headline capability']],
        'released_at' => now(),
    ]);

    Livewire::test(VersionWidget::class)
        ->assertOk()
        // The collapsed section still renders in the DOM, so the heading, the
        // SemVer token and the changelog entry must all be present.
        ->assertSee(__('cms.system.recent_changes'))
        ->assertSee($release->version)
        ->assertSee('A brand new headline capability');
});
