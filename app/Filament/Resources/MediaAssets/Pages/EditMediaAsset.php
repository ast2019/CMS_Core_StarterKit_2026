<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
