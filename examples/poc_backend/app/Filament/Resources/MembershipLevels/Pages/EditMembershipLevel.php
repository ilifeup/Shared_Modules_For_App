<?php

namespace App\Filament\Resources\MembershipLevels\Pages;

use App\Filament\Resources\MembershipLevels\MembershipLevelResource;
use App\Models\AuditEvent;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMembershipLevel extends EditRecord
{
    protected static string $resource = MembershipLevelResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);
        $changed = array_keys($record->getDirty());
        $record->save();

        if ($changed !== []) {
            AuditEvent::record('membership_level.updated', auth()->id(), $record, ['fields' => $changed]);
        }

        return $record;
    }
}
