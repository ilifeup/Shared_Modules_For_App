<?php

namespace App\Filament\Resources\AccessRoles\Pages;

use App\Filament\Resources\AccessRoles\AccessRoleResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditAccessRole extends EditRecord
{
    protected static string $resource = AccessRoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return []; // only roles change on this page
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->record;
        AccessRoleResource::saveRoles($user, (array) ($this->form->getRawState()['module_roles'] ?? []), auth()->user());
    }
}
