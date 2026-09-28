<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\AppliesPublishingWorkflow;
use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Resources\Pages\PageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use AppliesPublishingWorkflow;
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;

    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->normaliseTranslatablePayload($data);

        return $this->extractStatusForWorkflow($data);
    }

    protected function afterCreate(): void
    {
        $this->syncFeaturedImageFromForm();
        $this->applyPendingStatusTransition();
    }
}
