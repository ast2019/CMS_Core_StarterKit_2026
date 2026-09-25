<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Concerns\ManagesGalleryItems;
use App\Filament\Resources\Galleries\GalleryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGallery extends CreateRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;
    use ManagesGalleryItems;

    protected static string $resource = GalleryResource::class;

    /**
     * Cover and items are both rows in `media_attachments`, so neither can be
     * written until the gallery exists. ManagesFeaturedImage defines its own
     * afterCreate(), which this overrides — hence the explicit call.
     */
    protected function afterCreate(): void
    {
        $this->syncFeaturedImageFromForm();
        $this->syncGalleryItemsFromForm();
    }
}
