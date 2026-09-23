<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slides\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Concerns\ManagesFeaturedImage;
use App\Filament\Resources\Slides\SlideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSlide extends CreateRecord
{
    use InteractsWithTranslatableRecord;
    use ManagesFeaturedImage;

    protected static string $resource = SlideResource::class;
}
