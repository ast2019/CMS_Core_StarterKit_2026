<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Changelog;
use App\Models\SystemInfo;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * RULE #2 — "Semantic Versioning in `system_info`, shown in admin panel."
 *
 * Requirements 10.1, 10.2.
 *
 * Shown to every role, not just admins. The version is the first thing anyone needs
 * when reporting a problem, and an editor who cannot see it cannot tell support which
 * release they are on.
 */
class VersionWidget extends Widget
{
    protected string $view = 'filament.widgets.version';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 100;

    public function getVersion(): string
    {
        return SystemInfo::version();
    }

    public function getInstalledAt(): ?string
    {
        return SystemInfo::current()->installed_at?->format('Y-m-d');
    }

    /**
     * Recent releases, newest first.
     *
     * @return Collection<int, Changelog>
     */
    public function getRecentChangelogs(): Collection
    {
        return Changelog::query()->recent(5)->get();
    }
}
