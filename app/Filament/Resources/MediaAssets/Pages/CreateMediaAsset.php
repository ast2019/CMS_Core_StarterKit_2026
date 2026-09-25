<?php

declare(strict_types=1);

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Models\MediaAsset;
use Filament\Resources\Pages\CreateRecord;

class CreateMediaAsset extends CreateRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = MediaAssetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->normaliseTranslatablePayload($data);

        /*
         * The uploader is assigned, never chosen — and it was never assigned at
         * all. `uploaded_by` exists in the fillable list, the relation, the
         * migration and the factory, but nothing in the panel set it, so every
         * asset created here had a NULL owner. That is not cosmetic:
         * MediaAssetPolicy::ownerColumn() is `uploaded_by`, so the
         * `media.update.own` boundary matched nobody and an Author could not edit
         * the alt text of an image they had just uploaded themselves.
         */
        $data['uploaded_by'] ??= auth()->id();

        return $data;
    }

    /**
     * Copy the file's own facts onto the row once the upload has been attached.
     *
     * SpatieMediaLibraryFileUpload saves its media after the record is created, so
     * the file does not exist yet during mutateFormDataBeforeCreate() and this
     * cannot be folded into it.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof MediaAsset) {
            $record->syncFileMetadata();
        }
    }
}
