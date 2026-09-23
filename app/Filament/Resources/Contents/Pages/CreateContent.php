<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contents\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Resources\Contents\ContentResource;
use App\Models\Content;
use Filament\Resources\Pages\CreateRecord;

class CreateContent extends CreateRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;

    protected static string $resource = ContentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->normaliseTranslatablePayload($data);

        // Authorship is assigned, never chosen. Letting an Author pick someone
        // else would defeat the `content.update.own` boundary in the policy: they
        // could create a record owned by an editor and then be unable to edit it,
        // or worse, assign work to a colleague who never wrote it.
        $data['author_id'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncFeaturedImageFromForm();

        $record = $this->getRecord();

        // getRecord() is typed to Model; narrow before calling Content's API.
        if ($record instanceof Content) {
            // Keeps the pivot's is_primary flag and primary_category_id in step,
            // and guarantees the primary category is also a member of the set.
            $record->syncPrimaryCategory();
        }
    }
}
