<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\GuardsAgainstConcurrentEdits;
use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MediaAssets\Actions\ReplaceFileAction;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Tables\GuardedDeleteActions;
use App\Models\MediaAsset;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
    /*
     * Item 35 — refuse to silently overwrite someone else's save made while this form was open.
     */
    use GuardsAgainstConcurrentEdits;
    use InteractsWithTranslatableRecord;

    protected static string $resource = MediaAssetResource::class;

    /**
     * Replacing the file changes its size and dimensions, so the row is
     * re-synced — otherwise an asset would keep reporting the measurements of the
     * image it used to hold.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof MediaAsset) {
            $record->syncFileMetadata();
        }
    }

    /**
     * Show the new file in the (locked) upload field after ReplaceFileAction, and the measurements
     * read from it, without discarding anything else the editor has typed but not saved.
     */
    public function refreshReplacedFile(): void
    {
        $record = $this->getRecord();
        $record->refresh()->load('media');

        $this->form->getComponent('file')?->loadStateFromRelationships(shouldHydrate: true);
        $this->refreshFormData(['duration_seconds']);
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Item 11 — the guarded delete, which refuses a delete that would silently degrade
             * published output and names the consequence of one that would not. Item 10 adds the
             * two actions that make the trash a trash rather than a one-way door.
             */
            // Item 12 — the only way to swap the file, and it says where the file is used first.
            ReplaceFileAction::make(),
            GuardedDeleteActions::record(),
            GuardedDeleteActions::forceDeleteRecord(),
            RestoreAction::make(),
        ];
    }
}
