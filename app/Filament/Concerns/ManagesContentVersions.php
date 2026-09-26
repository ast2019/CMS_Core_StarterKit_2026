<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Contracts\Versionable;
use App\Models\ContentVersion;
use App\Support\Dates\LocalizedDate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Version history and restore for an Edit page.
 *
 * Requirement 3.7.
 *
 * Restoring is itself an update, so HasContentVersions snapshots the state it
 * replaced — rolling back is undoable rather than destructive. That property is
 * what makes offering this to editors safe.
 */
trait ManagesContentVersions
{
    protected function versionHistoryAction(): Action
    {
        return Action::make('versionHistory')
            ->label(__('cms.version.history'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(__('cms.version.history'))
            ->modalSubmitAction(false)
            ->visible(fn (): bool => $this->getRecord() instanceof Versionable)
            ->modalContent(function () {
                $record = $this->getRecord();

                // getRecord() is typed to Model; ->visible() above guarantees the
                // narrowing holds by the time this runs.
                if (! $record instanceof Versionable) {
                    return null;
                }

                return view('filament.pages.version-history', [
                    'record' => $record,
                    'versions' => $record->latestVersions(),
                ]);
            });
    }

    protected function restoreVersionAction(): Action
    {
        return Action::make('restoreVersion')
            ->label(__('cms.action.restore_version'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('cms.version.restore_warning'))
            // Restoring rewrites live content, so it sits behind the same
            // `content.restore` ability as un-archiving rather than plain update.
            ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false)
            ->visible(fn (): bool => $this->getRecord() instanceof Versionable)
            ->schema([
                Select::make('version_id')
                    ->label(__('cms.version.select'))
                    ->required()
                    ->options(fn (): array => $this->versionOptions()
                        ->mapWithKeys(fn (ContentVersion $version): array => [
                            $version->getKey() => sprintf(
                                '#%s — %s — %s',
                                // The version number is a count an editor reads
                                // alongside a Persian date, so it gets the same
                                // digits; mixing «#12» with «۱۴۰۵/۰۷/۰۵» in one
                                // label looks like two different systems.
                                LocalizedDate::number($version->version_number),
                                LocalizedDate::format($version->created_at) ?? '',
                                $version->author->name ?? __('cms.audit.system'),
                            ),
                        ])
                        ->all()),
            ])
            ->action(function (array $data): void {
                $record = $this->getRecord();

                if (! $record instanceof Versionable) {
                    return;
                }

                $version = $record->findVersion($data['version_id']);

                if ($version === null) {
                    Notification::make()
                        ->title(__('cms.version.not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                $record->restoreVersion($version);

                // Refill the form from the restored record, or the editor keeps
                // looking at the pre-restore values and may save them back.
                $this->fillForm();

                Notification::make()
                    ->title(__('cms.version.restored', ['number' => $version->version_number]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Most recent snapshots for the restore picker.
     *
     * @return Collection<int, ContentVersion>
     */
    protected function versionOptions(): Collection
    {
        $record = $this->getRecord();

        if (! $record instanceof Versionable) {
            return collect();
        }

        return $record->latestVersions();
    }
}
