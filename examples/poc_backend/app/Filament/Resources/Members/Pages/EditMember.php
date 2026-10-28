<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Models\AuditEvent;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * A changed email or phone is never considered verified; the member verifies it again.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        // Membership/contact and attachment-setting changes must never persist
        // without their audit record. Send mail only after the transaction commits.
        $changed = DB::transaction(function () use ($record, $data): array {
            $record->fill($data);
            // Attachment settings are admin-only attributes, never member mass assignment.
            foreach ([
                'attachment_upload_enabled',
                'attachment_upload_expires_at',
                'attachment_weekly_upload_count',
                'attachment_weekly_upload_bytes',
                'admin_identity_note',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $record->setAttribute($field, $data[$field]);
                }
            }
            $changed = array_keys($record->getDirty());

            if ($record->isDirty('email')) {
                $record->email_verified_at = null;
            }

            if ($record->isDirty('phone_e164')) {
                $record->phone_verified_at = null;
            }

            $record->save();

            if ($changed !== []) {
                AuditEvent::record('member.updated_by_admin', auth()->id(), $record, ['fields' => $changed]);
            }

            return $changed;
        });

        if (in_array('email', $changed, true) && $record->email !== null) {
            $record->sendEmailVerificationNotification();
        }

        return $record;
    }
}
