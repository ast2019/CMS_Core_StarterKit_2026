<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesContentVersions;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Concerns\OffersRedirectsForChangedSlugs;
use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesContentVersions;
    use ManagesFeaturedImage;
    use OffersRedirectsForChangedSlugs;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->versionHistoryAction(),
            $this->restoreVersionAction(),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Before the write, or the diff has nothing to compare against.
        $this->captureSlugsBeforeSave();

        return $this->normaliseTranslatablePayload($data);
    }

    /**
     * Overrides ManagesFeaturedImage::afterSave(), so it calls it explicitly — the
     * same arrangement EditGallery already documents. A trait method is replaced, not
     * merged, by a method on the class.
     */
    protected function afterSave(): void
    {
        $this->syncFeaturedImageFromForm();

        /*
         * A static page is the type where a renamed slug hurts most: its URL has no
         * type segment (/fa/about, not /fa/page/about), so it is the one most likely
         * to be linked from print, email and other sites. The homepage is exempt and
         * the concern knows why — it answers at /{locale}, so its slug is not part of
         * any public URL.
         */
        $this->offerRedirectsForChangedSlugs();
    }
}
