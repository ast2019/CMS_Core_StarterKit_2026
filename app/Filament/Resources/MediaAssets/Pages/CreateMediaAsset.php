<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMediaAsset extends CreateRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = MediaAssetResource::class;
}
