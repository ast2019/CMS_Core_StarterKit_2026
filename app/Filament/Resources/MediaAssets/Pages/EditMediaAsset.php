<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\GuardsAgainstConcurrentEdits;
use App\Filament\Concerns\InteractsWithTranslatableRecord;
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

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Item 11 — the guarded delete, which refuses a delete that would silently degrade
             * published output and names the consequence of one that would not. Item 10 adds the
             * two actions that make the trash a trash rather than a one-way door.
             */
            GuardedDeleteActions::record(),
            GuardedDeleteActions::forceDeleteRecord(),
            RestoreAction::make(),
        ];
    }
}
