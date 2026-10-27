<?php

namespace App\Services\Booking;

use App\Jobs\DispatchBookingGoogleSync;
use App\Jobs\DispatchBookingNotification;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventWaitlistEntry;
use App\Models\GoogleCalendarConnection;
use App\Models\MemberVerification;
use App\Models\User;
use App\Services\Family\FamilyDelegation;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S9 M08 the only seat / waitlist engine (S9-L06 A).
 *
 * - Seats = confirmed rows in event_booking_attendees (one per real attendee); the
 *   S6 API also writes a group + attendee, so old and new entry points share one count.
 * - Every write first locks the calendar_events row (SELECT ... FOR UPDATE), then
 *   groups / bookings, so all entry points are serialised per occurrence.
 * - Waitlist is strict FIFO by sequence; a group is promoted only as a whole and the
 *   head group blocks later groups (D17, D22). Only permanent reasons skip a group (D09).
 * - Notifications and Google sync run after the commit and never undo a booking.
 */
final class BookingEngine
{
    /** @var list<array{0: EventBookingGroup, 1: string, 2: string}> */
    private array $pendingNotices = [];

    /** @var array<int, array<int, true>> user id => calendar event ids */
    private array $pendingSync = [];

    public function __construct(private readonly BookingSideEffects $effects) {}

    public function confirmedSeats(CalendarEvent $event): int
    {
        return EventBookingAttendee::query()
            ->where('calendar_event_id', $event->id)
            ->where('status', EventBookingAttendee::STATUS_CONFIRMED)
            ->count();
    }

    public function waitingGroups(CalendarEvent $event): int
    {
        return EventWaitlistEntry::query()->where('calendar_event_id', $event->id)->where('status', EventWaitlistEntry::WAITING)->count();
    }

    /**
     * Why the occurrence does not take a new direct booking now (null = it does).
     * event_not_published | event_cancelled | disabled | not_open | closed | full.
     * "full" also while groups are waiting (nobody may jump the queue).
     */
    public function unavailableReason(CalendarEvent $event, int $seats = 1, bool $ignoreWindow = false): ?string
    {
        $now = now();

        return match (true) {
            $event->status === CalendarEvent::STATUS_CANCELLED => 'event_cancelled',
            $event->status !== CalendarEvent::STATUS_PUBLISHED => 'event_not_published',
            ! $event->booking_enabled => 'disabled',
            ! $ignoreWindow && $event->booking_opens_at !== null && $now->lt($event->booking_opens_at) => 'not_open',
            ! $ignoreWindow && $now->gte($event->bookingClosesAt()) => 'closed',
            $this->confirmedSeats($event) + $seats > (int) $event->booking_capacity || $this->waitingGroups($event) > 0 => 'full',
            default => null,
        };
    }

    /**
     * The member's active (confirmed or waitlisted) place at this occurrence, if any.
     */
    public function activeAttendeeOf(CalendarEvent $event, User $member): ?EventBookingAttendee
    {
        return EventBookingAttendee::query()
            ->where('calendar_event_id', $event->id)
            ->where('member_user_id', $member->id)
            ->where('active', 1)
            ->first();
    }

    /**
     * S6 single-member booking (POST /events/{id}/bookings, no body). Same rules and
     * error codes as S6; the seat is counted in the same ledger.
     */
    public function bookLegacy(CalendarEvent $event, User $member): EventBooking
    {
        try {
            $booking = $this->transaction(function () use ($event, $member): EventBooking {
                $locked = $this->lock($event);

                if ($this->activeAttendeeOf($locked, $member) !== null) {
                    throw new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。');
                }

                $reason = $this->unavailableReason($locked);
                if ($reason !== null) {
                    throw self::unavailable($reason);
                }
                $this->assertEligible($locked, $member);

                $booking = EventBooking::query()->create([
                    'calendar_event_id' => $locked->id,
                    'user_id' => $member->id,
                    'status' => EventBooking::STATUS_CONFIRMED,
                    'active' => 1,
                    'booked_at' => now(),
                ]);

                $group = $this->createGroup($locked, [
                    'legacy_booking_id' => $booking->id,
                    'booker_user_id' => $member->id,
                    'contact_name' => $member->name,
                    'contact_email' => $member->email,
                    'contact_phone' => $member->phone_e164,
                    'status' => EventBookingGroup::STATUS_CONFIRMED,
                    'seat_count' => 1,
                ]);
                $this->createAttendee($group, $member->name, $member->id, EventBookingAttendee::STATUS_CONFIRMED);
                $this->queueSync($member->id, $locked->id);

                AuditEvent::record('booking.confirmed', $member->id, $booking, ['event_id' => $event->id]);

                return $booking;
            });
        } catch (QueryException $e) {
            throw $this->duplicateOr($e);
        }

        $this->flush();

        return $booking;
    }

    /**
     * S6 cancel of the member's own single booking (keeps S6 error codes).
     */
    public function cancelLegacy(EventBooking $booking, User $actor, bool $byAdmin = false): EventBooking
    {
        $cancelled = $this->transaction(function () use ($booking, $actor, $byAdmin): EventBooking {
            $event = $this->lock($booking->event);
            /** @var EventBooking $locked */
            $locked = EventBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new ApiException(409, 'BOOKING_NOT_ACTIVE', '此預約已取消。');
            }
            if (! $byAdmin && now()->gte($event->cancelClosesAt())) {
                throw new ApiException(409, 'CANCEL_DEADLINE_PASSED', '已超過取消期限，請洽主辦單位。');
            }

            $group = EventBookingGroup::query()->where('legacy_booking_id', $locked->id)->lockForUpdate()->first();
            if ($group !== null && $group->isActive()) {
                $this->cancelGroupLocked($group, $actor, $byAdmin, null);
            } else {
                $this->cancelLegacyRow($locked, $actor);
            }

            $this->promote($event);

            AuditEvent::record('booking.cancelled', $actor->id, $locked, [
                'event_id' => $locked->calendar_event_id,
                'by' => $actor->id === $locked->user_id ? 'member' : 'admin',
            ]);

            return $locked->refresh();
        });

        $this->flush();

        return $cancelled;
    }

    /**
     * S9 group booking (registered member or admin on the member's behalf).
     */
    public function book(CalendarEvent $event, BookingRequest $request, ?bool &$created = null): EventBookingGroup
    {
        $created = false;
        // Guard before idempotency replay: no legacy guest request can be replayed.
        if ($request->booker === null) {
            throw new ApiException(403, 'GUEST_NOT_ALLOWED', '活動僅接受正式會員預約。');
        }
        // Enforce the database membership contract before idempotent replay.
        if (! User::query()->whereKey($request->booker->id)->where('is_active', true)->where('verified_status', 2)->exists()) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '僅開放已驗證且啟用的會員預約。');
        }
        if ($request->clientRequestId !== null) {
            $existing = EventBookingGroup::query()->where('client_request_id', $request->clientRequestId)->first();
            if ($existing !== null) {
                return $this->replay($existing, $event, $request);
            }
        }

        $replayed = null;
        try {
            $group = $this->transaction(function () use ($event, $request, &$replayed): ?EventBookingGroup {
                $locked = $this->lock($event);
                // A parallel retry with the same id may have committed while we waited for the lock.
                if ($request->clientRequestId !== null
                    && ($replayed = EventBookingGroup::query()->where('client_request_id', $request->clientRequestId)->first()) !== null) {
                    return null;
                }
                $this->validateRequest($locked, $request);

                $seats = count($request->attendees);
                $reason = $this->unavailableReason($locked, $seats, $request->byAdmin);

                $status = match (true) {
                    $reason === null => EventBookingGroup::STATUS_CONFIRMED,
                    $reason === 'full' && $locked->waitlist_enabled => EventBookingGroup::STATUS_WAITLISTED,
                    default => throw self::unavailable($reason),
                };

                $group = $this->createGroup($locked, [
                    'booker_user_id' => $request->booker?->id,
                    'contact_name' => $request->contactName,
                    'contact_email' => $request->contactEmail,
                    'contact_phone' => $request->contactPhone,
                    'status' => $status,
                    'seat_count' => $seats,
                    'client_request_id' => $request->clientRequestId,
                ]);

                $attendeeStatus = $status === EventBookingGroup::STATUS_CONFIRMED ? EventBookingAttendee::STATUS_CONFIRMED : EventBookingAttendee::STATUS_WAITLISTED;
                foreach ($request->attendees as $attendee) {
                    $this->createAttendee($group, $attendee['name'], $attendee['member_user_id'] ?? null, $attendeeStatus);
                }

                if ($status === EventBookingGroup::STATUS_WAITLISTED) {
                    $next = (int) EventWaitlistEntry::query()->where('calendar_event_id', $locked->id)->max('sequence') + 1;
                    EventWaitlistEntry::query()->create([
                        'calendar_event_id' => $locked->id,
                        'event_booking_group_id' => $group->id,
                        'sequence' => $next,
                        'status' => EventWaitlistEntry::WAITING,
                    ]);
                } elseif ($request->booker !== null) {
                    $this->queueSync($request->booker->id, $locked->id);
                }

                AuditEvent::record($group->status === EventBookingGroup::STATUS_WAITLISTED ? 'booking.waitlisted' : 'booking.confirmed', $request->actor?->id, $group, [
                    'event_id' => $event->id,
                    'seats' => $group->seat_count,
                    'by' => $request->byAdmin ? 'admin' : 'member',
                ]);

                return $group;
            });
        } catch (QueryException $e) {
            if ($request->clientRequestId !== null && ($existing = EventBookingGroup::query()->where('client_request_id', $request->clientRequestId)->first()) !== null) {
                return $this->replay($existing, $event, $request);
            }
            throw $this->duplicateOr($e);
        }
        if ($group === null) {
            return $this->replay($replayed, $event, $request);
        }

        $created = true;
        $this->flush();

        return $group->refresh()->load('attendees', 'waitlistEntry');
    }

    /**
     * Cancels a confirmed or waitlisted group (member before the cancel deadline, or an
     * admin at any time) and promotes waiting groups at once (D15, D16).
     */
    public function cancelGroup(EventBookingGroup $group, User $actor, bool $byAdmin = false, ?string $reason = null): EventBookingGroup
    {
        $wasConfirmed = false;
        $result = $this->transaction(function () use ($group, $actor, $byAdmin, $reason, &$wasConfirmed): EventBookingGroup {
            $event = $this->lock($group->event);
            /** @var EventBookingGroup $locked */
            $locked = EventBookingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (! $byAdmin) {
                if ((int) $locked->booker_user_id !== (int) $actor->id) {
                    throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '只有原預約者可以取消此預約。');
                }
                foreach ($locked->attendees()->get() as $attendee) {
                    $member = $attendee->member_user_id !== null
                        ? User::query()->find($attendee->member_user_id)
                        : null;
                    if ($member === null || ! app(FamilyDelegation::class)->canManage($actor, $member, true)) {
                        throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '此預約包含目前無權代管的家庭會員，請洽管理員處理。');
                    }
                }
            }

            if (! $locked->isActive()) {
                throw new ApiException(409, 'BOOKING_NOT_ACTIVE', '此報名已取消。');
            }
            if (! $byAdmin && $locked->status === EventBookingGroup::STATUS_CONFIRMED && now()->gte($event->cancelClosesAt())) {
                throw new ApiException(409, 'CANCEL_DEADLINE_PASSED', '已超過取消期限，請洽主辦單位。');
            }

            $wasConfirmed = $locked->status === EventBookingGroup::STATUS_CONFIRMED;
            $this->cancelGroupLocked($locked, $actor, $byAdmin, $reason);
            $this->promote($event);

            AuditEvent::record($wasConfirmed ? 'booking.group_cancelled' : 'booking.waitlist_cancelled', $actor->id, $locked, [
                'event_id' => $locked->calendar_event_id,
                'seats' => $locked->seat_count,
                'by' => $byAdmin ? 'admin' : 'member',
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });

        $this->flush();

        return $result;
    }

    /**
     * Cancels the whole occurrence (M08-D12): every booking and waitlist entry ends,
     * nothing is promoted, all contacts are notified.
     */
    public function cancelEvent(CalendarEvent $event, ?string $reason, User $actor): void
    {
        $count = $this->transaction(function () use ($event, $reason, $actor): int {
            $locked = $this->lock($event);
            $locked->cancel($reason);

            $groups = EventBookingGroup::query()->where('calendar_event_id', $locked->id)
                ->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED])
                ->lockForUpdate()->get();

            foreach ($groups as $group) {
                $this->endGroup($group, EventBookingGroup::STATUS_EVENT_CANCELLED, $actor, $reason);
                $this->notice($group, '活動取消：'.$locked->title, '很抱歉，「'.$locked->title.'」已取消，您的報名／候補已一併取消。'.($reason ? '原因：'.$reason : ''));
            }

            // S6 rows not mapped to a group (none after the S9 backfill, kept for safety).
            EventBooking::query()->where('calendar_event_id', $locked->id)->where('status', EventBooking::STATUS_CONFIRMED)
                ->get()->each(fn (EventBooking $b) => $this->cancelLegacyRow($b, $actor));

            AuditEvent::record('event.cancelled_with_bookings', $actor->id, $event, ['groups' => $groups->count()]);

            return $groups->count();
        });

        $this->flush();
    }

    /**
     * Admin capacity change (D20): the admin chooses which confirmed groups to cancel
     * when the new capacity is below the confirmed seats; never leaves overbooking.
     *
     * @param  list<int>  $cancelGroupIds
     */
    public function changeCapacity(CalendarEvent $event, int $capacity, array $cancelGroupIds, User $actor, ?string $reason = null): void
    {
        if ($capacity < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', '名額至少 1 人。', ['capacity' => ['名額至少 1 人。']]);
        }

        $before = 0;
        $this->transaction(function () use ($event, $capacity, $cancelGroupIds, $actor, $reason, &$before): void {
            $locked = $this->lock($event);
            $before = (int) $locked->booking_capacity;

            $groups = EventBookingGroup::query()->where('calendar_event_id', $locked->id)->whereIn('id', $cancelGroupIds)
                ->where('status', EventBookingGroup::STATUS_CONFIRMED)->lockForUpdate()->get();
            foreach ($groups as $group) {
                $this->cancelGroupLocked($group, $actor, true, $reason ?? '名額調整');
            }

            if ($this->confirmedSeats($locked) > $capacity) {
                throw new ApiException(422, 'CAPACITY_BELOW_CONFIRMED', '新名額小於已確認人數，請選擇要取消的報名。', [
                    'capacity' => ['已確認 '.$this->confirmedSeats($locked).' 人，超過新名額 '.$capacity.' 人。'],
                ]);
            }

            $locked->forceFill(['booking_capacity' => $capacity])->save();
            $this->promote($locked);
            AuditEvent::record('event.capacity_changed', $actor->id, $event, [
                'from' => $before, 'to' => $capacity, 'cancelled_groups' => array_values($cancelGroupIds), 'reason' => $reason,
            ]);
        });

        $this->flush();
    }

    /**
     * Admin moves a waiting group to a new 1-based position (D14); audited with the
     * order before and after.
     */
    public function moveInWaitlist(EventBookingGroup $group, int $position, User $actor, string $reason): void
    {
        $order = [];
        $this->transaction(function () use ($group, $position, $actor, $reason, &$order): void {
            $event = $this->lock($group->event);
            $entries = EventWaitlistEntry::query()->where('calendar_event_id', $event->id)->where('status', EventWaitlistEntry::WAITING)
                ->orderBy('sequence')->lockForUpdate()->get();

            $ids = $entries->pluck('event_booking_group_id')->all();
            if (! in_array($group->id, $ids, true)) {
                throw new ApiException(409, 'BOOKING_NOT_WAITLISTED', '此報名不在候補名單中。');
            }

            $order['before'] = $ids;
            $ids = array_values(array_diff($ids, [$group->id]));
            array_splice($ids, max(0, min($position - 1, count($ids))), 0, [$group->id]);
            $order['after'] = $ids;

            $base = (int) EventWaitlistEntry::query()->where('calendar_event_id', $event->id)->max('sequence') + 1;
            $sequences = $entries->pluck('sequence')->sort()->values()->all();
            // Two passes keep UNIQUE(calendar_event_id, sequence) valid.
            foreach ($entries as $i => $entry) {
                $entry->forceFill(['sequence' => $base + $i])->save();
            }
            $byGroup = $entries->keyBy('event_booking_group_id');
            foreach ($ids as $i => $groupId) {
                $byGroup[$groupId]->forceFill(['sequence' => $sequences[$i]])->save();
            }

            $this->promote($event);
            AuditEvent::record('booking.waitlist_reordered', $actor->id, $group, $order + ['reason' => $reason]);
        });

        $this->flush();
    }

    /**
     * Promotes waiting groups in strict FIFO order while whole groups fit (call with
     * the occurrence locked). Stops at the first group that does not fit.
     *
     * @return list<EventBookingGroup>
     */
    public function promote(CalendarEvent $event): array
    {
        if ($event->status !== CalendarEvent::STATUS_PUBLISHED || ! $event->booking_enabled || now()->gte($event->ends_at)) {
            return [];
        }

        $promoted = [];
        while (true) {
            /** @var EventWaitlistEntry|null $head */
            $head = EventWaitlistEntry::query()->where('calendar_event_id', $event->id)->where('status', EventWaitlistEntry::WAITING)
                ->orderBy('sequence')->lockForUpdate()->first();
            if ($head === null) {
                break;
            }

            /** @var EventBookingGroup $group */
            $group = EventBookingGroup::query()->whereKey($head->event_booking_group_id)->lockForUpdate()->firstOrFail();

            $permanent = $this->permanentFailure($group);
            if ($permanent !== null) {
                $head->forceFill(['status' => EventWaitlistEntry::SKIPPED, 'skip_reason' => $permanent, 'resolved_at' => now()])->save();
                $this->endGroup($group, EventBookingGroup::STATUS_CANCELLED, null, $permanent);
                AuditEvent::record('booking.waitlist_skipped', null, $group, ['event_id' => $event->id, 'reason' => $permanent]);

                continue;
            }

            if ($this->confirmedSeats($event) + $group->seat_count > (int) $event->booking_capacity) {
                break; // strict FIFO: later (smaller) groups never jump the head group
            }

            $group->forceFill(['status' => EventBookingGroup::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();
            EventBookingAttendee::query()->where('event_booking_group_id', $group->id)->where('status', EventBookingAttendee::STATUS_WAITLISTED)
                ->update(['status' => EventBookingAttendee::STATUS_CONFIRMED, 'updated_at' => now()]);
            $head->forceFill(['status' => EventWaitlistEntry::PROMOTED, 'resolved_at' => now()])->save();

            AuditEvent::record('booking.promoted', null, $group, ['event_id' => $event->id, 'seats' => $group->seat_count]);
            $this->notice($group, '候補成功：'.$event->title, '已為您保留 '.$group->seat_count.' 個名額，請準時出席。');
            if ($group->booker_user_id !== null) {
                $this->queueSync($group->booker_user_id, $event->id);
            }
            $promoted[] = $group;
        }

        return $promoted;
    }

    /**
     * Notifies confirmed and waitlisted contacts of an important change (D11, D21).
     */
    public function notifyChange(CalendarEvent $event, string $title, string $body): int
    {
        // The public change-notification entry point must also create durable
        // jobs inside a DB transaction, not only when called by another writer.
        $count = $this->transaction(function () use ($event, $title, $body): int {
            $groups = EventBookingGroup::query()->where('calendar_event_id', $event->id)
                ->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED])->get();
            foreach ($groups as $group) {
                $this->notice($group, $title, $body);
                if ($group->booker_user_id !== null && $group->status === EventBookingGroup::STATUS_CONFIRMED) {
                    $this->queueSync($group->booker_user_id, $event->id);
                }
            }

            return $groups->count();
        });
        $this->flush();

        return $count;
    }

    public function assertEligible(CalendarEvent $event, ?User $booker): void
    {
        $typeId = $event->category?->required_verification_type_id;
        if ($typeId === null) {
            return;
        }

        $ok = $booker !== null && MemberVerification::query()->valid()
            ->where('user_id', $booker->id)->where('verification_type_id', $typeId)->exists();
        if (! $ok) {
            throw new ApiException(403, 'ELIGIBILITY_FAILED', '此活動需要「'.($event->category->requiredVerificationType?->name ?? '指定認證').'」認證才能報名。');
        }
    }

    public static function unavailable(string $reason): ApiException
    {
        return match ($reason) {
            'full' => new ApiException(409, 'BOOKING_FULL', '名額已滿。'),
            'event_cancelled' => new ApiException(409, 'BOOKING_UNAVAILABLE', '活動已取消，無法預約。', ['reason' => [$reason]]),
            'not_open' => new ApiException(409, 'BOOKING_UNAVAILABLE', '尚未開放預約。', ['reason' => [$reason]]),
            'closed' => new ApiException(409, 'BOOKING_UNAVAILABLE', '預約已截止。', ['reason' => [$reason]]),
            default => new ApiException(409, 'BOOKING_UNAVAILABLE', '此活動未開放預約。', ['reason' => [$reason]]),
        };
    }

    // ---- internals ------------------------------------------------------------------

    private function validateRequest(CalendarEvent $event, BookingRequest $request): void
    {
        $seats = count($request->attendees);
        if ($seats !== 1) {
            throw new ApiException(422, 'SINGLE_ATTENDEE_ONLY', 'MVP 每筆預約只能有一位參加者。');
        }
        $category = $event->category;

        if ($seats < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', '請至少填寫一位參加者。', ['attendees' => ['請至少填寫一位參加者。']]);
        }
        if ($seats > max(1, (int) $event->booking_max_seats)) {
            throw new ApiException(422, 'BOOKING_LIMIT_EXCEEDED', '每筆報名最多 '.max(1, (int) $event->booking_max_seats).' 人。');
        }

        // Every entry point, including admin/internal calls, requires an active booker.
        if ($request->booker === null) {
            throw new ApiException(403, 'GUEST_NOT_ALLOWED', '活動不開放訪客預約，請先建立正式會員身分。');
        }
        if (! User::query()->whereKey($request->booker->id)->where('is_active', true)->where('verified_status', 2)->exists()) {
            throw new ApiException(422, 'INVALID_BOOKER', '預約者必須是有效的正式會員。');
        }

        // Enforce attendee identities for every caller, including administrators.
        if ($request->booker !== null) {
            $memberIds = array_map(static fn (array $a) => $a['member_user_id'] ?? null, $request->attendees);
            if (in_array(null, $memberIds, true)) {
                throw new ApiException(422, 'MEMBER_ID_REQUIRED', '每位參加者都必須有正式會員 ID。');
            }
            if (count($memberIds) !== count(array_unique($memberIds))) {
                throw new ApiException(422, 'DUPLICATE_ATTENDEE', '同一位會員不可重複加入預約。');
            }
            foreach ($memberIds as $memberId) {
                if (! User::query()->whereKey($memberId)->where('is_active', true)->where('verified_status', 2)->exists()) {
                    throw new ApiException(422, 'INVALID_ATTENDEE', '參加者會員 ID 無效。');
                }
                // Do not permit arbitrary proxy booking before verified family delegation exists.
                if (! $request->byAdmin && ! app(FamilyDelegation::class)->canManage($request->booker, User::query()->findOrFail($memberId), true)) {
                    throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '尚未取得此參加者的代訂授權。');
                }
            }
        }

        $proxy = $seats > 1 || collect($request->attendees)->contains(fn (array $a): bool => ($a['member_user_id'] ?? null) !== $request->booker->id);
        if ($proxy && ! $request->byAdmin && ! ($category?->allow_proxy_booking ?? false)) {
            throw new ApiException(422, 'PROXY_NOT_ALLOWED', '此活動只能為自己報名。');
        }
        if (! $request->byAdmin && $this->activeAttendeeOf($event, $request->booker) !== null
            && collect($request->attendees)->contains(fn (array $a): bool => ($a['member_user_id'] ?? null) === $request->booker->id)) {
            throw new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。');
        }

        // D05 "每人名額": the limit is per booker and occurrence, not
        // per request — several smaller bookings cannot add up past it (admins exempt).
        if (! $request->byAdmin) {
            $limit = max(1, (int) $event->booking_max_seats);
            $already = (int) EventBookingGroup::query()->where('calendar_event_id', $event->id)
                ->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED])
                ->where('booker_user_id', $request->booker->id)
                ->sum('seat_count');
            if ($already + $seats > $limit) {
                throw new ApiException(422, 'BOOKING_LIMIT_EXCEEDED', '每人於此場次最多可報名 '.$limit.' 位（已報名 '.$already.' 位）。');
            }
        }

        // Eligibility is checked when booking only (D10, D19).
        $this->assertEligible($event, $request->booker);
    }

    private function permanentFailure(EventBookingGroup $group): ?string
    {
        $booker = $group->booker_user_id !== null ? User::query()->find($group->booker_user_id) : null;

        return match (true) {
            $booker === null => 'booker_deleted',
            ! $booker->is_active => 'booker_disabled',
            default => null,
        };
    }

    private function cancelGroupLocked(EventBookingGroup $group, User $actor, bool $byAdmin, ?string $reason): void
    {
        $this->endGroup($group, EventBookingGroup::STATUS_CANCELLED, $actor, $reason);

        if ($byAdmin && $group->booker_user_id !== $actor->id) {
            $this->notice($group, '報名已取消', '主辦單位已取消您於「'.$group->event->title.'」的報名。'.($reason ? '原因：'.$reason : ''));
        }
    }

    private function endGroup(EventBookingGroup $group, string $status, ?User $actor, ?string $reason): void
    {
        $group->forceFill([
            'status' => $status,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->id,
            'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ])->save();

        EventBookingAttendee::query()->where('event_booking_group_id', $group->id)->whereNot('status', EventBookingAttendee::STATUS_CANCELLED)
            ->update(['status' => EventBookingAttendee::STATUS_CANCELLED, 'active' => null, 'updated_at' => now()]);
        EventWaitlistEntry::query()->where('event_booking_group_id', $group->id)->where('status', EventWaitlistEntry::WAITING)
            ->update(['status' => EventWaitlistEntry::CANCELLED, 'resolved_at' => now(), 'updated_at' => now()]);

        if ($group->legacy_booking_id !== null) {
            $legacy = EventBooking::query()->whereKey($group->legacy_booking_id)->where('status', EventBooking::STATUS_CONFIRMED)->first();
            if ($legacy !== null) {
                $this->cancelLegacyRow($legacy, $actor);
            }
        }

        if ($group->booker_user_id !== null) {
            $this->queueSync($group->booker_user_id, $group->calendar_event_id);
        }
    }

    private function cancelLegacyRow(EventBooking $booking, ?User $actor): void
    {
        $booking->forceFill([
            'status' => EventBooking::STATUS_CANCELLED,
            'active' => null,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->id,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createGroup(CalendarEvent $event, array $attributes): EventBookingGroup
    {
        $now = now();

        return EventBookingGroup::query()->create($attributes + [
            'calendar_event_id' => $event->id,
            'booked_at' => $now,
            'confirmed_at' => ($attributes['status'] ?? null) === EventBookingGroup::STATUS_CONFIRMED ? $now : null,
        ]);
    }

    private function createAttendee(EventBookingGroup $group, string $name, ?int $memberId, string $status): EventBookingAttendee
    {
        return EventBookingAttendee::query()->create([
            'event_booking_group_id' => $group->id,
            'calendar_event_id' => $group->calendar_event_id,
            'member_user_id' => $memberId,
            'name' => mb_substr(trim($name), 0, 80),
            'status' => $status,
            'active' => 1,
        ]);
    }

    private function replay(EventBookingGroup $existing, CalendarEvent $event, BookingRequest $request): EventBookingGroup
    {
        $same = $existing->calendar_event_id === $event->id
            && $existing->booker_user_id === $request->booker?->id
            && ($request->booker !== null || ($existing->contact_name === $request->contactName
                && $existing->contact_email === $request->contactEmail
                && $existing->contact_phone === $request->contactPhone));
        // Member IDs (and order), not mutable display names, identify the original
        // attendees. A profile rename must not turn a legitimate retry into 409.
        $storedMemberIds = $existing->attendees()->orderBy('id')->pluck('member_user_id')
            ->map(static fn ($id): ?int => $id === null ? null : (int) $id)->all();
        $incomingMemberIds = array_map(
            static fn (array $attendee): ?int => isset($attendee['member_user_id']) ? (int) $attendee['member_user_id'] : null,
            $request->attendees,
        );
        if (! $same || $existing->seat_count !== count($incomingMemberIds) || $storedMemberIds !== $incomingMemberIds) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', '此 client_request_id 已用於不同的報名內容。');
        }

        // An idempotent replay is still a read of protected booking details.
        // Recheck current identity/delegation; revocation must take effect immediately.
        if (! User::query()->whereKey($request->booker->id)->where('is_active', true)->where('verified_status', 2)->exists()) {
            throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '預約者已停用。');
        }
        if (! $request->byAdmin) {
            foreach ($existing->attendees()->get() as $attendee) {
                $member = User::query()->whereKey($attendee->member_user_id)->where('is_active', true)->where('verified_status', 2)->first();
                if ($member === null || ! app(FamilyDelegation::class)->canManage($request->booker, $member)) {
                    throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '已無權查看此家庭會員的預約。');
                }
            }
        }

        return $existing->load('attendees', 'waitlistEntry');
    }

    private function duplicateOr(QueryException $e): ApiException|QueryException
    {
        // UNIQUE(calendar_event_id, member_user_id, active) or the S6 unique index.
        return str_contains(strtolower($e->getMessage()), 'unique') || ($e->errorInfo[1] ?? null) === 1062
            ? new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。')
            : $e;
    }

    private function lock(CalendarEvent $event): CalendarEvent
    {
        /** @var CalendarEvent $locked */
        $locked = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        return $locked->load('category.requiredVerificationType');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        // Production bookings must never silently fall back to in-memory
        // post-commit handoffs, which can be lost on process termination.
        if (app()->environment('production') && (
            config('queue.default') !== 'database'
            || (config('queue.connections.database.connection') !== null
                && config('queue.connections.database.connection') !== DB::getDefaultConnection())
            || config('queue.connections.database.after_commit') !== false
        )) {
            throw new \LogicException('Booking durable handoff requires the database queue on the booking DB with after_commit=false.');
        }

        try {
            return DB::transaction($callback);
        } catch (\Throwable $e) {
            $this->pendingNotices = [];
            $this->pendingSync = [];
            throw $e;
        }
    }

    private function notice(EventBookingGroup $group, string $title, string $body): void
    {
        if (DB::transactionLevel() > 0
            && config('queue.default') === 'database'
            && (config('queue.connections.database.connection') === null
                || config('queue.connections.database.connection') === DB::getDefaultConnection())) {
            DispatchBookingNotification::dispatch($group->id, $title, $body)->onConnection('database');

            return;
        }

        $this->pendingNotices[] = [$group, $title, $body];
    }

    private function queueSync(int $userId, int $eventId): void
    {
        // A database-queued handoff is inserted on the same DB transaction as
        // the booking. No Google API calls or Google mapping writes occur here.
        // Other queue drivers retain the old post-commit behavior until their
        // durable handoff has been explicitly configured and validated.
        if (DB::transactionLevel() > 0
            && config('queue.default') === 'database'
            && (config('queue.connections.database.connection') === null
                || config('queue.connections.database.connection') === DB::getDefaultConnection())) {
            if (GoogleCalendarConnection::query()
                ->where('user_id', $userId)
                ->where('status', GoogleCalendarConnection::ACTIVE)
                ->whereNotNull('google_calendar_id')
                ->exists()) {
                DispatchBookingGoogleSync::dispatch($userId, $eventId)->onConnection('database');
            }

            return;
        }

        $this->pendingSync[$userId][$eventId] = true;
    }

    /** Side effects after the commit; failures are logged and never undo a booking. */
    private function flush(): void
    {
        $notices = $this->pendingNotices;
        $sync = $this->pendingSync;
        $this->pendingNotices = [];
        $this->pendingSync = [];

        foreach ($notices as [$group, $title, $body]) {
            $this->effects->notify($group, $title, $body);
        }
        foreach ($sync as $userId => $events) {
            foreach (array_keys($events) as $eventId) {
                $this->effects->syncBooking($userId, $eventId);
            }
        }
    }
}
