<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\Forms\FormResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForm extends CreateRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = FormResource::class;
}
