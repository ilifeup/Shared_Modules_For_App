<?php

namespace App\Services\Booking;

use App\Models\AuditEvent;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S9 M08-D18/D23/D24 a member takes over guest bookings made with the same contact.
 * Ownership of that contact is proven by the member's own verified email or phone
 * (existing S1/S3 verification); names or booking numbers are never enough.
 */
class GuestClaimService
{
    /**
     * @return Collection<int, EventBookingGroup>
     */
    public function claimable(User $member): Collection
    {
        [$email, $phone] = $this->verifiedContacts($member);
        if ($email === null && $phone === null) {
            return collect();
        }

        return EventBookingGroup::query()->with(['event', 'attendees'])
            ->where('is_guest', true)->whereNull('booker_user_id')
            ->where(fn (Builder $q) => $q
                ->when($email !== null, fn (Builder $e) => $e->orWhere('contact_email', $email))
                ->when($phone !== null, fn (Builder $p) => $p->orWhere('contact_phone', $phone)))
            ->orderByDesc('booked_at')->limit(100)->get();
    }

    public function claim(EventBookingGroup $group, User $member): EventBookingGroup
    {
        $claimed = DB::transaction(function () use ($group, $member): EventBookingGroup {
            /** @var EventBookingGroup $locked */
            $locked = EventBookingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            [$email, $phone] = $this->verifiedContacts($member);
            $matches = $locked->is_guest && $locked->booker_user_id === null
                && (($email !== null && $locked->contact_email === $email) || ($phone !== null && $locked->contact_phone === $phone));
            if (! $matches) {
                throw new ApiException(404, 'NOT_FOUND', '找不到可認領的報名（需與您已驗證的 Email 或手機相同）。');
            }

            $hasOwn = EventBookingAttendee::query()->where('calendar_event_id', $locked->calendar_event_id)->where('member_user_id', $member->id)->where('active', 1)->exists();
            if ($hasOwn && $locked->isActive()) {
                throw new ApiException(409, 'CLAIM_CONFLICT', '您已報名同一場次，請洽主辦單位協助處理。');
            }

            $locked->forceFill(['booker_user_id' => $member->id, 'claimed_at' => now()])->save();

            // Notifications kept for the guest booking move to the member (D23).
            DB::table('notifications')->where('notifiable_type', $locked->getMorphClass())->where('notifiable_id', $locked->id)
                ->update(['notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id, 'updated_at' => now()]);

            return $locked;
        });

        AuditEvent::record('booking.guest_claimed', $member->id, $claimed, ['event_id' => $claimed->calendar_event_id]);

        return $claimed;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function verifiedContacts(User $member): array
    {
        return [
            $member->email !== null && $member->hasVerifiedEmail() ? mb_strtolower($member->email) : null,
            $member->phone_e164 !== null && $member->hasVerifiedPhone() ? $member->phone_e164 : null,
        ];
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : null;

        return $email === '' ? null : $email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        return Phone::normalize($phone);
    }
}
