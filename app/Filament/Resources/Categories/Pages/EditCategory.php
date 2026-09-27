<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\OffersRedirectsForChangedSlugs;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Tables\GuardedDeleteActions;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use OffersRedirectsForChangedSlugs;

    protected static string $resource = CategoryResource::class;

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
     * A Category has no publish workflow, so it is ALWAYS public — which makes it the
     * type where an unprompted slug rename is most dangerous, not least: there is no
     * draft state in which a typo can be fixed for free. HasSeoMeta::isPubliclyVisible()
     * is what lets the shared concern express that without a status column.
     */
    protected function afterSave(): void
    {
        $this->offerRedirectsForChangedSlugs();
    }
}
