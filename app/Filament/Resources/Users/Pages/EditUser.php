<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // UserPolicy::delete() forbids deleting your own account; Filament
            // hides the action automatically when the policy denies it.
            DeleteAction::make(),
        ];
    }
}
