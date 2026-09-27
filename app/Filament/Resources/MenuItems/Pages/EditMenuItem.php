<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Pages;

use App\Filament\Concerns\GuardsAgainstConcurrentEdits;
use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Filament\Tables\GuardedDeleteActions;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMenuItem extends EditRecord
{
    /*
     * Item 35 — refuse to silently overwrite someone else's save made while this form was open.
     */
    use GuardsAgainstConcurrentEdits;
    use InteractsWithTranslatableRecord;

    protected static string $resource = MenuItemResource::class;

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
}
