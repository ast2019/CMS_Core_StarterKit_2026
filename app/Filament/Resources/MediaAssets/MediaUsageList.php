<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets;

use App\Filament\Pages\Settings;
use App\Models\MediaAsset;
use App\Services\Content\UsageInspector;
use App\Support\AuthorisationProbe;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Item 12 — the rows of an asset's "where is this used" list, ready for the view.
 *
 * UsageInspector decides WHAT references the asset; this decides how each reference reads in the
 * panel: the record type, its title in the panel's locale, the role the asset plays there, whether
 * the record is in the trash, and a link only when following it would work.
 */
final class MediaUsageList
{
    /**
     * How many references are listed by name. The total is always exact; past this, the list says
     * how many more there are rather than rendering a thousand rows.
     */
    public const LIMIT = 50;

    /**
     * @return array{total: int, hidden: int, logo: ?array{url: ?string}, items: list<array{type: string, title: string, role: string, trashed: bool, url: ?string}>}
     */
    public static function for(MediaAsset $asset): array
    {
        $references = app(UsageInspector::class)->mediaReferences($asset, self::LIMIT);
        $locale = app()->getLocale();
        $source = (string) config('cms.locales.source', 'fa');

        $items = [];

        foreach ($references['items'] as ['record' => $record, 'role' => $role]) {
            /** @var class-string<FilamentResource>|null $resource */
            $resource = Filament::getModelResource($record);

            $items[] = [
                'type' => $resource !== null ? $resource::getModelLabel() : class_basename($record),
                'title' => self::titleOf($record, $locale, $source),
                'role' => $role->label(),
                'trashed' => method_exists($record, 'trashed') && $record->trashed(),
                /*
                 * Linked only for someone who may edit it; Filament's edit page authorises `update`.
                 * Asked quietly: deciding whether to draw a link is not an attempt to edit, and the
                 * denial audit would otherwise log one refusal per listed record per render.
                 */
                'url' => $resource !== null
                    && AuthorisationProbe::quietly(fn (): bool => $resource::canEdit($record))
                        ? $resource::getUrl('edit', ['record' => $record])
                        : null,
            ];
        }

        $logo = null;

        if ($references['logo']) {
            $logo = [
                'url' => AuthorisationProbe::quietly(fn (): bool => Settings::canAccess())
                    ? Settings::getUrl()
                    : null,
            ];
        }

        return [
            'total' => $references['total'],
            // Counted against the rows the inspector returned, not the rows listed: a reference whose
            // owner has been destroyed is in the total but has nothing to show.
            'hidden' => max(0, $references['total'] - self::LIMIT),
            'logo' => $logo,
            'items' => $items,
        ];
    }

    private static function titleOf(Model $record, string $locale, string $source): string
    {
        if (! method_exists($record, 'getTranslation')) {
            return (string) ($record->getAttribute('title') ?: '—');
        }

        $title = (string) $record->getTranslation('title', $locale, false);

        if ($title === '') {
            $title = (string) $record->getTranslation('title', $source, false);
        }

        return $title !== '' ? $title : '—';
    }
}
