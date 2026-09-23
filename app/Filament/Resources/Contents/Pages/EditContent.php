<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Resources\Contents\ContentResource;
use App\Models\Content;
use App\Services\Content\PreviewLinkService;
use App\Services\Content\RedirectSuggestionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditContent extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;

    protected static string $resource = ContentResource::class;

    /**
     * Slug values before this save, so a change can be offered as a 301.
     *
     * @var array<string, string|null>
     */
    protected array $slugsBeforeSave = [];

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
        // Capture before the write, because HasSlug::slugChanges() compares
        // against what was loaded and the save is about to move that baseline.
        $this->slugsBeforeSave = $this->getRecord()->getTranslations('slug');

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

    /**
     * Requirement 7.5 — offer a 301 when a PUBLISHED record's slug changes.
     *
     * Offered, not created automatically. A slug corrected three times while
     * drafting would otherwise leave two dead redirect hops behind, and redirect
     * chains are worse for both crawlers and page speed than no redirect at all.
     */
    protected function offerRedirectsForChangedSlugs(Content $record): void
    {
        if (! $record->isLive()) {
            return;
        }

        $changes = app(RedirectSuggestionService::class)->pendingFor($record, $this->slugsBeforeSave);

        if ($changes === []) {
            return;
        }

        Notification::make()
            ->title(__('cms.redirect.slug_changed_title'))
            ->body(__('cms.redirect.slug_changed_body', ['count' => count($changes)]))
            ->warning()
            ->persistent()
            ->actions([
                Action::make('create_redirects')
                    ->label(__('cms.redirect.create_action'))
                    ->button()
                    ->action(function () use ($record, $changes): void {
                        $created = app(RedirectSuggestionService::class)->create($record, $changes);

                        Notification::make()
                            ->title(__('cms.redirect.created', ['count' => $created]))
                            ->success()
                            ->send();
                    }),
            ])
            ->send();
    }
}
