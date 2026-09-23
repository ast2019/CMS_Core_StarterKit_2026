<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\Tags\TagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = TagResource::class;
}
