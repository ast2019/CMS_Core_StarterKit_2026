<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Concerns\ManagesGalleryItems;
use App\Filament\Concerns\OffersRedirectsForChangedSlugs;
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
    use OffersRedirectsForChangedSlugs;

    protected static string $resource = GalleryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->captureSlugsBeforeSave();

        return $this->normaliseTranslatablePayload($data);
    }

    /**
     * Overrides ManagesFeaturedImage::afterSave(), so it calls it explicitly.
     */
    protected function afterSave(): void
    {
        $this->syncFeaturedImageFromForm();
        $this->syncGalleryItemsFromForm();

        $this->offerRedirectsForChangedSlugs();
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
