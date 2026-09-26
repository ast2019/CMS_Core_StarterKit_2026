<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesContentVersions;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Concerns\OffersRedirectsForChangedSlugs;
use App\Filament\Resources\Contents\ContentResource;
use App\Models\Content;
use App\Services\Content\PreviewLinkService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditContent extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesContentVersions;
    use ManagesFeaturedImage;

    /**
     * Requirement 7.5. Lifted out of this class into a concern so a Page, Gallery
     * and Category get the same prompt — all four are routable and sitemapped, and
     * until then only an article's slug change was ever offered a 301.
     */
    use OffersRedirectsForChangedSlugs;

    protected static string $resource = ContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('cms.action.preview'))
                ->icon('heroicon-o-eye')
                ->url(fn (Content $record): string => app(PreviewLinkService::class)
                    ->urlFor($record, app()->getLocale()))
                ->openUrlInNewTab()
                ->authorize(fn (Content $record): bool => auth()->user()?->can('preview', $record) ?? false),

            $this->versionHistoryAction(),
            $this->restoreVersionAction(),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Capture before the write: RedirectSuggestionService::pendingFor() diffs
        // against the slugs as they were, and the save is about to move that
        // baseline.
        $this->captureSlugsBeforeSave();

        return $this->normaliseTranslatablePayload($data);
    }

    protected function afterSave(): void
    {
        $this->syncFeaturedImageFromForm();

        $record = $this->getRecord();

        // getRecord() is typed to Model, so narrow before using Content's API.
        if (! $record instanceof Content) {
            return;
        }

        $record->syncPrimaryCategory();

        $this->offerRedirectsForChangedSlugs($record);
    }
}
