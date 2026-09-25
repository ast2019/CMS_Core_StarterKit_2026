<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Models\MediaAsset;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
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
            DeleteAction::make(),
        ];
    }
}
