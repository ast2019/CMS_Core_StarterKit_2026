<?php

namespace App\Filament\Resources\Redirects\Pages;

use App\Filament\Concerns\GuardsAgainstConcurrentEdits;
use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRedirect extends EditRecord
{
    /*
     * Item 35 — refuse to silently overwrite someone else's save made while this form was open.
     */
    use GuardsAgainstConcurrentEdits;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
