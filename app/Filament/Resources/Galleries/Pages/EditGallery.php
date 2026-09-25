<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Concerns\ManagesGalleryItems;
use App\Filament\Resources\Galleries\GalleryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditGallery extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;
    use ManagesGalleryItems;

    protected static string $resource = GalleryResource::class;

    /**
     * Overrides ManagesFeaturedImage::afterSave(), so it calls it explicitly.
     */
    protected function afterSave(): void
    {
        $this->syncFeaturedImageFromForm();
        $this->syncGalleryItemsFromForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
