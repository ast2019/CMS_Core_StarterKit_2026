<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Concerns\InteractsWithTranslatableRecord;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Tables\GuardedDeleteActions;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    use InteractsWithTranslatableRecord;

    protected static string $resource = TagResource::class;

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
