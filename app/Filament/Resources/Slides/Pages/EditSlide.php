<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slides\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Resources\Slides\SlideResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlide extends EditRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;

    protected static string $resource = SlideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
