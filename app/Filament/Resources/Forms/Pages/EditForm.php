<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Concerns\GuardsAgainstConcurrentEdits;
use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\Forms\Actions\DeleteFormAction;
use App\Filament\Resources\Forms\FormResource;
use Filament\Resources\Pages\EditRecord;

class EditForm extends EditRecord
{
    /*
     * Item 35 — refuse to silently overwrite someone else's save made while this form was open.
     */
    use GuardsAgainstConcurrentEdits;
    use InteractsWithTranslatableRecord;

    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteFormAction::make(),
        ];
    }
}
