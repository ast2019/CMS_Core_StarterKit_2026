<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
