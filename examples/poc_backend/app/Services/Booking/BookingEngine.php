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
use App\Services\Payment\CashInvoiceService;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * S9 M08 the only seat / waitlist engine (S9-L06 A).
 *
 * - Seats = confirmed active event_bookings rows (one per verified member).
 *   Both current entry points write the canonical booking row transactionally.
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

    /**
     * Current database authorization for self or a delegated family member.
     * The caller's User models may be stale: qualification is re-read from the
     * database, with a locking read when $lock is set so that a revocation
     * committed after this transaction started is still seen (InnoDB consistent
     * reads would return the older snapshot).
     */
    public function canManageFamily(User $manager, User $member, bool $lock = false): bool
    {
        $users = User::query()->whereKey(array_unique([$manager->id, $member->id]))
            ->when($lock, fn ($query) => $query->sharedLock())
            ->get()->keyBy('id');
        $manager = $users->get($manager->id);
        $member = $users->get($member->id);
        if ($manager === null || $member === null
            || ! $manager->is_active || (int) $manager->verified_status !== 2 || $manager->FamilyUser
            || ! $member->is_active || (int) $member->verified_status !== 2) {
            return false;
        }
        if ($manager->id === $member->id) {
            return true;
        }
        if (! $member->FamilyUser) {
            return false;
        }
        $query = DB::table('family_memberships')
            ->where('manager_user_id', $manager->id)
            ->where('member_user_id', $member->id)
            ->where('status', 'active')
            ->whereNull('ended_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() !== null;
    }

    public function confirmedSeats(CalendarEvent $event): int
    {
        // One canonical booking row occupies exactly one confirmed seat.
        // Both current entry points write this row in the same transaction.
        return EventBooking::query()
            ->where('calendar_event_id', $event->id)
            ->where('status', EventBooking::STATUS_CONFIRMED)
            ->where('active', 1)
            ->count();
    }

    public function waitingGroups(CalendarEvent $event): int
    {
        // Each active waitlisted member occupies one waitlist slot.
        // Queue entries retain their sequence only until the queue cutover.
        return EventBooking::query()
            ->where('calendar_event_id', $event->id)
            ->where('status', EventBooking::STATUS_WAITLISTED)
            ->where('active', 1)
            ->count();
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
    public function activeAttendeeOf(CalendarEvent $event, User $member): ?EventBooking
    {
        // R1: one active booking per event/member, whether confirmed or waiting.
        // The return type is the canonical booking, not an attendee ledger row.
        return EventBooking::query()
            ->where('calendar_event_id', $event->id)
            ->where('member_user_id', $member->id)
            ->whereIn('status', [EventBooking::STATUS_CONFIRMED, EventBooking::STATUS_WAITLISTED])
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
                $this->assertActiveVerifiedMember($member);
                // Series-owned occurrences must use the authoritative whole-term path.
                if (DB::table('course_group_events')->where('calendar_event_id', $locked->id)->exists()
                    || DB::table('course_sessions')->where('calendar_event_id', $locked->id)->exists()) {
                    throw new ApiException(409, 'SERIES_BOOKING_REQUIRED',
                        '此場次屬於系列活動／課程，請由整系列報名入口預約。');
                }

                if ($this->activeAttendeeOf($locked, $member) !== null) {
                    throw new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。');
                }

                $reason = $this->unavailableReason($locked);
                if ($reason !== null) {
                    throw self::unavailable($reason);
                }
                $this->assertEligible($locked, $member);

                $bookedAt = now();
                $booking = EventBooking::query()->create([
                    'calendar_event_id' => $locked->id,
                    'user_id' => $member->id, // S6 compatibility until the coordinated cutover.
                    'member_user_id' => $member->id,
                    'booker_user_id' => $member->id,
                    'status' => EventBooking::STATUS_CONFIRMED,
                    'active' => 1,
                    'booked_at' => $bookedAt,
                    'confirmed_at' => $bookedAt,
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
                app(CashInvoiceService::class)->forEvent($locked, $group, $member->id);
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
        // Never trust a caller-supplied admin flag: authorization belongs in the engine.
        $byAdmin = $byAdmin && $actor->is_active && ! $actor->FamilyUser && $actor->isAdmin();
        $cancelled = $this->transaction(function () use ($booking, $actor, $byAdmin): EventBooking {
            $event = $this->lock($booking->event);
            // Series-owned occurrences must use the authoritative whole-term path.
            if (DB::table('course_group_events')->where('calendar_event_id', $event->id)->exists()
                || DB::table('course_sessions')->where('calendar_event_id', $event->id)->exists()) {
                throw new ApiException(409, 'SERIES_BOOKING_REQUIRED',
                    '此場次屬於系列活動／課程，請由整系列報名入口預約。');
            }
            /** @var EventBooking $locked */
            $locked = EventBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            // The engine, not only the API controller, enforces ownership.
            // An administrator may cancel on behalf of a member; ordinary
            // members can only cancel their own legacy single-member booking.
            // Canonical booking identity determines cancellation ownership.
            // The original booker can cancel a delegated family booking; a
            // different family manager cannot silently take over that booking.
            if (! $byAdmin && (int) $locked->member_user_id !== (int) $actor->id
                && (int) $locked->booker_user_id !== (int) $actor->id) {
                throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '只有預約會員或原代訂人可以取消此預約。');
            }
            if (! $locked->isActive()) {
                throw new ApiException(409, 'BOOKING_NOT_ACTIVE', '此預約已取消。');
            }
            if (! $byAdmin && now()->gte($event->cancelClosesAt())) {
                throw new ApiException(409, 'CANCEL_DEADLINE_PASSED', '已超過取消期限，請洽主辦單位。');
            }

            $group = EventBookingGroup::query()->where('legacy_booking_id', $locked->id)->lockForUpdate()->first();
            if ($group !== null && $group->isActive()) {
                // Reject corrupt cross-ledger pointers before touching invoices,
                // attendees or the group. The surrounding transaction rolls back.
                $memberId = EventBookingAttendee::query()
                    ->where('event_booking_group_id', $group->id)->value('member_user_id');
                if ((int) $group->calendar_event_id !== (int) $locked->calendar_event_id
                    || (int) $group->booker_user_id !== (int) $locked->booker_user_id
                    || $memberId === null || (int) $memberId !== (int) $locked->member_user_id) {
                    throw new ApiException(409, 'CANONICAL_BOOKING_MISMATCH', '預約資料關聯不一致，請洽管理員。');
                }
                $this->cancelGroupLocked($group, $actor, $byAdmin, null);
            } else {
                $this->cancelLegacyRow($locked, $actor);
            }

            $this->promote($event);

            AuditEvent::record('booking.cancelled', $actor->id, $locked, [
                'event_id' => $locked->calendar_event_id,
                'by' => $byAdmin ? 'admin' : ((int) $actor->id === (int) $locked->booker_user_id ? 'booker' : 'member'),
            ]);

            return $locked->refresh();
        });

        $this->flush();

        return $cancelled;
    }

    /**
     * S9 group booking (registered member or admin on the member's behalf).
     */
    public function book(CalendarEvent $event, BookingRequest $request, ?bool &$created = null, bool $coveredByCourseTerm = false): EventBookingGroup
    {
        $created = false;
        // Guard before idempotency replay: no legacy guest request can be replayed.
        if ($request->booker === null) {
            throw new ApiException(403, 'GUEST_NOT_ALLOWED', '活動僅接受正式會員預約。');
        }
        // Verify the current database qualification before any replay lookup.
        // A revoked or inactive booker must not inspect an existing booking.
        $this->assertActiveVerifiedMember($request->booker);
        if ($request->clientRequestId !== null) {
            $existing = EventBookingGroup::query()->where('client_request_id', $request->clientRequestId)->first();
            if ($existing !== null) {
                return $this->replay($existing, $event, $request);
            }
        }

        $replayed = null;
        try {
            $group = $this->transaction(function () use ($event, $request, $coveredByCourseTerm, &$replayed): ?EventBookingGroup {
                $locked = $this->lock($event);
                // A parallel retry with the same id may have committed while we waited for the lock.
                if ($request->clientRequestId !== null
                    && ($replayed = EventBookingGroup::query()->where('client_request_id', $request->clientRequestId)->first()) !== null) {
                    return null;
                }
                // Series-owned occurrences are never independently bookable.
                // Legacy term-derived bookings use the validated parent path below.
                if (DB::table('course_group_events')
                    ->where('calendar_event_id', $locked->id)->exists()) {
                    throw new ApiException(409, 'SERIES_BOOKING_REQUIRED',
                        '此場次屬於系列活動／課程，請由整系列報名入口預約。');
                }
                if (! $coveredByCourseTerm && DB::table('course_sessions')
                    ->where('calendar_event_id', $locked->id)->exists()) {
                    throw new ApiException(409, 'SERIES_BOOKING_REQUIRED',
                        '此場次屬於系列活動／課程，請由整系列報名入口預約。');
                }
                $this->validateRequest($locked, $request);

                // Each accepted booking represents exactly one verified member.
                $seats = 1;
                if ($coveredByCourseTerm) {
                    // This is a derived seat of an already admitted whole term,
                    // not a new independent event capacity or waitlist decision.
                    // Authenticate the precise enrollment and deterministic key
                    // BEFORE allowing this distinct booking path.
                    $attendee = $request->attendees[0];
                    $linked = DB::table('course_enrollments')
                        ->join('course_sessions', 'course_sessions.course_term_id', '=', 'course_enrollments.course_term_id')
                        ->where('course_sessions.calendar_event_id', $locked->id)
                        ->where('course_enrollments.user_id', $attendee['member_user_id'])
                        ->where('course_enrollments.booker_user_id', $request->booker->id)
                        ->where('course_enrollments.status', 'pending_payment')
                        ->select('course_enrollments.id')->get();
                    $valid = $linked->contains(function ($entry) use ($locked, $request): bool {
                        return $request->clientRequestId === (string) Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'course-enrollment:'.$entry->id.':event:'.$locked->id,
                        );
                    });
                    if (! $valid) {
                        throw new ApiException(422, 'INVALID_COURSE_BOOKING', '整期課程預約關聯無效。');
                    }
                    // Term capacity is the authority, but a derived seat still
                    // needs a live occurrence (never a cancelled or draft one).
                    $sessionReason = match (true) {
                        $locked->status === CalendarEvent::STATUS_CANCELLED => 'event_cancelled',
                        $locked->status !== CalendarEvent::STATUS_PUBLISHED => 'event_not_published',
                        ! $locked->booking_enabled => 'disabled',
                        default => null,
                    };
                    if ($sessionReason !== null) {
                        throw self::unavailable($sessionReason);
                    }
                    $status = EventBookingGroup::STATUS_CONFIRMED;
                } else {
                    // A forged byAdmin flag must not bypass event booking windows.
                    $ignoreWindow = $request->byAdmin && $request->booker->is_active
                        && ! $request->booker->FamilyUser && $request->booker->isAdmin();
                    $reason = $this->unavailableReason($locked, $seats, $ignoreWindow);
                    $status = match (true) {
                        $reason === null => EventBookingGroup::STATUS_CONFIRMED,
                        $reason === 'full' && $locked->waitlist_enabled => EventBookingGroup::STATUS_WAITLISTED,
                        default => throw self::unavailable($reason),
                    };
                    if ($status === EventBookingGroup::STATUS_WAITLISTED
                        && $this->waitingGroups($locked) >= (int) $locked->waitlist_capacity) {
                        throw new ApiException(409, 'WAITLIST_FULL', '候補名額已滿。');
                    }
                }

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
                // validateRequest() guarantees exactly one verified users.id.
                $attendee = $request->attendees[0];
                $this->createAttendee($group, $attendee['name'], (int) $attendee['member_user_id'], $attendeeStatus);
                if ($status === EventBookingGroup::STATUS_CONFIRMED) {
                    if (! $coveredByCourseTerm) {
                        app(CashInvoiceService::class)->forEvent($locked, $group, (int) $attendee['member_user_id']);
                    }
                }

                if ($status === EventBookingGroup::STATUS_WAITLISTED) {
                    $next = (int) EventBooking::query()->where('calendar_event_id', $locked->id)->max('waitlist_sequence') + 1;
                    EventBooking::query()->whereKey($group->legacy_booking_id)
                        ->update(['waitlist_sequence' => $next, 'updated_at' => now()]);
                    EventWaitlistEntry::query()->create([
                        'calendar_event_id' => $locked->id,
                        'event_booking_group_id' => $group->id,
                        'sequence' => $next,
                        'status' => EventWaitlistEntry::WAITING,
                    ]);
                } elseif ($request->booker !== null) {
                    $this->queueSync($request->booker->id, $locked->id);
                }

                // Audit the verified privilege actually used, never the untrusted
                // request flag. A missing optional actor must not erase the booker.
                AuditEvent::record($group->status === EventBookingGroup::STATUS_WAITLISTED ? 'booking.waitlisted' : 'booking.confirmed', $request->actor?->id ?? $request->booker->id, $group, [
                    'event_id' => $event->id,
                    'seats' => $group->seat_count,
                    'by' => ($request->byAdmin && $request->booker->is_active && ! $request->booker->FamilyUser && $request->booker->isAdmin()) ? 'admin' : 'member',
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
     * Legacy cash-expiry entry point. An overdue invoice is an accounting
     * marker only: never cancel the booking or promote the waitlist.
     */
    public function expireCashHold(int $groupId, int $invoiceId): bool
    {
        return DB::transaction(function () use ($groupId, $invoiceId): bool {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null
                || $invoice->booking_type !== '01'
                || (int) $invoice->booking_id !== $groupId
                || (int) $invoice->invoice_status !== 0
                || $invoice->overdue_at !== null
                || $invoice->payment_due_at === null
                || now()->lt($invoice->payment_due_at)
                || ! EventBookingGroup::query()->whereKey($groupId)->exists()) {
                return false;
            }

            return DB::table('invoices')->where('id', $invoiceId)
                ->where('invoice_status', 0)->whereNull('overdue_at')
                ->update(['overdue_at' => now(), 'updated_at' => now()]) === 1;
        });
    }

    /**
     * Cancels a confirmed or waitlisted group (member before the cancel deadline, or an
     * admin at any time) and promotes waiting groups at once (D15, D16).
     */
    public function cancelGroup(EventBookingGroup $group, User $actor, bool $byAdmin = false, ?string $reason = null): EventBookingGroup
    {
        // Only actual administrators can bypass normal cancellation ownership.
        // Course release reasons are handled separately by the verified parent
        // enrollment/invoice checks inside the transaction below.
        $verifiedAdmin = $actor->is_active && ! $actor->FamilyUser && $actor->isAdmin();
        $courseReleaseRequested = $byAdmin && in_array($reason, [
            'course_payment_expired', 'course_enrollment_cancelled', 'course_makeup_released',
        ], true);
        $byAdmin = $byAdmin && $verifiedAdmin;
        $wasConfirmed = false;
        $result = $this->transaction(function () use ($group, $actor, $byAdmin, $courseReleaseRequested, $reason, &$wasConfirmed): EventBookingGroup {
            $event = $this->lock($group->event);
            /** @var EventBookingGroup $locked */
            $locked = EventBookingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            // A derived series occurrence must not be cancelled separately.
            // The payment-expiry workflow owns the parent term lock and is
            // the only authorized per-occurrence release in this MVP.
            $courseSession = DB::table('course_sessions')
                ->where('calendar_event_id', $locked->calendar_event_id)->exists();
            $courseGroupSession = DB::table('course_group_events')
                ->where('calendar_event_id', $locked->calendar_event_id)->exists();
            // Only the course-owned path may request a parent-validated release.
            // A forged course reason cannot grant admin rights on a normal event.
            if ($courseReleaseRequested && $courseSession && ! $byAdmin) {
                // The release is authorized only by the exact cancelled parent
                // enrollment and invoice below, never by a supplied reason alone.
                $byAdmin = true;
            }
            if ($courseSession || $courseGroupSession) {
                $releaseAllowed = false;
                if ($courseSession && $byAdmin && in_array($reason, ['course_payment_expired', 'course_enrollment_cancelled'], true)) {
                    // A caller-supplied reason is not authorization. The parent
                    // enrollment must already be cancelled and its cash invoice
                    // expired within the owning transaction.
                    $enrollments = DB::table('course_enrollments')
                        ->join('course_sessions', 'course_sessions.course_term_id', '=', 'course_enrollments.course_term_id')
                        ->join('invoices', function ($join) {
                            $join->on('invoices.booking_id', '=', 'course_enrollments.id')
                                ->where('invoices.booking_type', '=', '02');
                        })
                        ->where('course_sessions.calendar_event_id', $locked->calendar_event_id)
                        ->where('course_enrollments.status', 'cancelled')
                        ->whereIn('invoices.invoice_status', $reason === 'course_payment_expired' ? [3] : [1, 2])
                        ->select('course_enrollments.id', 'course_enrollments.booker_user_id')->get();
                    foreach ($enrollments as $enrollment) {
                        $expected = (string) Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'course-enrollment:'.$enrollment->id.':event:'.$locked->calendar_event_id,
                        );
                        if ($locked->client_request_id === $expected
                            && (int) $enrollment->booker_user_id === (int) $actor->id) {
                            $releaseAllowed = true;
                            break;
                        }
                    }
                }
                // A makeup seat belongs to its original course enrollment, not to
                // a standalone event reservation. Only the cancelled parent may
                // release it, and the exact canonical group must match.
                if (! $releaseAllowed && $courseSession && $byAdmin
                    && $reason === 'course_makeup_released') {
                    $releaseAllowed = DB::table('course_makeups as m')
                        ->join('course_session_leaves as l', 'l.id', '=', 'm.course_session_leave_id')
                        ->join('course_enrollments as ce', 'ce.id', '=', 'l.course_enrollment_id')
                        ->join('invoices as i', function ($join) {
                            $join->on('i.booking_id', '=', 'ce.id')
                                ->where('i.booking_type', '=', '02');
                        })
                        ->where('m.event_booking_group_id', $locked->id)
                        ->where('ce.status', 'cancelled')
                        ->where('ce.booker_user_id', $actor->id)
                        ->whereIn('i.invoice_status', [1, 2, 3])
                        ->exists();
                }
                if (! $releaseAllowed) {
                    throw new ApiException(409, 'SERIES_BOOKING_MANAGED_BY_PARENT',
                        '系列活動／課程的從屬場次不可單獨取消，請由整系列管理流程處理。');
                }
            }

            if (! $byAdmin) {
                if ((int) $locked->booker_user_id !== (int) $actor->id) {
                    throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '只有原預約者可以取消此預約。');
                }
                foreach ($locked->attendees()->get() as $attendee) {
                    $member = $attendee->member_user_id !== null
                        ? User::query()->find($attendee->member_user_id)
                        : null;
                    if ($member === null || ! $this->canManageFamily($actor, $member, true)) {
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
        $this->assertBookingAdministrator($actor);
        $count = $this->transaction(function () use ($event, $reason, $actor): int {
            $locked = $this->lock($event);
            $this->assertNotCourseOwned($locked);
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
     * A course-owned occurrence cannot be changed through independent event
     * actions: the course-term/group transaction owns its enrolled members.
     */
    private function assertNotCourseOwned(CalendarEvent $event): void
    {
        $termOwned = DB::table('course_sessions')
            ->where('calendar_event_id', $event->id)->exists();
        $groupOwned = DB::table('course_group_events')
            ->where('calendar_event_id', $event->id)->exists();
        if ($termOwned || $groupOwned) {
            throw new ApiException(409, 'COURSE_EVENT_MANAGED_BY_TERM',
                '此課堂屬於系列課程，請由系列課程管理介面處理名額、取消及報名異動。');
        }
    }

    /**
     * Admin capacity change (D20): the admin chooses which confirmed groups to cancel
     * when the new capacity is below the confirmed seats; never leaves overbooking.
     *
     * @param  list<int>  $cancelGroupIds
     */
    public function changeCapacity(CalendarEvent $event, int $capacity, array $cancelGroupIds, User $actor, ?string $reason = null): void
    {
        $this->assertBookingAdministrator($actor);
        if ($capacity < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', '名額至少 1 人。', ['capacity' => ['名額至少 1 人。']]);
        }

        $before = 0;
        $this->transaction(function () use ($event, $capacity, $cancelGroupIds, $actor, $reason, &$before): void {
            $locked = $this->lock($event);
            $this->assertNotCourseOwned($locked);
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
    /** Protect destructive booking operations even when invoked outside HTTP controllers. */
    private function assertBookingAdministrator(User $actor): void
    {
        if (! $actor->is_active || $actor->FamilyUser || ! $actor->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', '此操作僅限管理員執行。');
        }
    }

    public function moveInWaitlist(EventBookingGroup $group, int $position, User $actor, string $reason): void
    {
        $this->assertBookingAdministrator($actor);
        $order = [];
        $this->transaction(function () use ($group, $position, $actor, $reason, &$order): void {
            $event = $this->lock($group->event);
            // Administrator reordering reads the same canonical FIFO order as promotion.
            // Legacy queue rows remain only for existing downstream consumers.
            $entries = EventWaitlistEntry::query()
                ->join('event_booking_groups', 'event_booking_groups.id', '=', 'event_waitlist_entries.event_booking_group_id')
                ->join('event_bookings', 'event_bookings.id', '=', 'event_booking_groups.legacy_booking_id')
                ->where('event_waitlist_entries.calendar_event_id', $event->id)
                ->where('event_waitlist_entries.status', EventWaitlistEntry::WAITING)
                ->where('event_bookings.calendar_event_id', $event->id)
                ->where('event_bookings.status', EventBooking::STATUS_WAITLISTED)
                ->where('event_bookings.active', 1)
                ->orderBy('event_bookings.waitlist_sequence')
                ->orderBy('event_bookings.id')
                ->select('event_waitlist_entries.*')
                ->lockForUpdate()->get();

            $ids = $entries->pluck('event_booking_group_id')->all();
            if (! in_array($group->id, $ids, true)) {
                throw new ApiException(409, 'BOOKING_NOT_WAITLISTED', '此報名不在候補名單中。');
            }

            $order['before'] = $ids;
            $ids = array_values(array_diff($ids, [$group->id]));
            array_splice($ids, max(0, min($position - 1, count($ids))), 0, [$group->id]);
            $order['after'] = $ids;

            $base = (int) EventWaitlistEntry::query()->where('calendar_event_id', $event->id)->max('sequence') + 1;
            // Preserve the canonical FIFO slots, not the obsolete queue's order.
            $sequences = EventBooking::query()
                ->where('calendar_event_id', $event->id)
                ->where('status', EventBooking::STATUS_WAITLISTED)
                ->where('active', 1)
                ->whereNotNull('waitlist_sequence')
                ->orderBy('waitlist_sequence')
                ->pluck('waitlist_sequence')->all();
            if (count($sequences) !== count($ids)
                || count(array_unique($sequences)) !== count($sequences)) {
                throw new ApiException(409, 'WAITLIST_STATE_INCONSISTENT', '候補排序資料不一致，請先檢查報名狀態。');
            }
            // Queue sequence has a UNIQUE(event, sequence) constraint across all
            // statuses, including cancelled/promoted rows. Preserve the existing
            // active queue slots independently of canonical FIFO slots.
            $queueSequences = $entries->pluck('sequence')->sort()->values()->all();
            // Reserve temporary positions in both tables before assigning the
            // final order. This avoids collisions with unique queue positions.
            $canonicalBase = (int) EventBooking::query()
                ->where('calendar_event_id', $event->id)->max('waitlist_sequence') + 1;
            $byGroup = $entries->keyBy('event_booking_group_id');
            $bookingIds = EventBookingGroup::query()->whereIn('id', $ids)
                ->pluck('legacy_booking_id', 'id');
            foreach ($ids as $i => $groupId) {
                $byGroup[$groupId]->forceFill(['sequence' => $base + $i])->save();
                EventBooking::query()->whereKey($bookingIds[$groupId])
                    ->update(['waitlist_sequence' => $canonicalBase + $i, 'updated_at' => now()]);
            }
            foreach ($ids as $i => $groupId) {
                $byGroup[$groupId]->forceFill(['sequence' => $queueSequences[$i]])->save();
                EventBooking::query()->whereKey($bookingIds[$groupId])
                    ->update(['waitlist_sequence' => $sequences[$i], 'updated_at' => now()]);
            }

            $this->promote($event);
            AuditEvent::record('booking.waitlist_reordered', $actor->id, $group, $order + ['reason' => $reason]);
        });

        $this->flush();
    }

    /**
     * Promotes one verified member booking at a time in strict FIFO order
     * (call with the occurrence locked). Stops when no capacity remains.
     *
     * @return list<EventBookingGroup>
     */
    public function promote(CalendarEvent $event): array
    {
        // Once an event has started, paid waitlist promotion cannot create a valid
        // invoice. Stop promotion without rolling back the cancellation that
        // released the seat; free events retain the existing end-time rule.
        if ($event->status !== CalendarEvent::STATUS_PUBLISHED || ! $event->booking_enabled
            || now()->gte($event->ends_at)
            || ((float) $event->price_amount > 0 && now()->gte($event->starts_at))) {
            return [];
        }

        $promoted = [];
        while (true) {
            /** @var EventWaitlistEntry|null $head */
            // Select FIFO order from the canonical booking; the queue row remains
            // temporarily for downstream audit and payment integrations.
            $head = EventWaitlistEntry::query()
                ->join('event_booking_groups', 'event_booking_groups.id', '=', 'event_waitlist_entries.event_booking_group_id')
                ->join('event_bookings', 'event_bookings.id', '=', 'event_booking_groups.legacy_booking_id')
                ->where('event_waitlist_entries.calendar_event_id', $event->id)
                ->where('event_waitlist_entries.status', EventWaitlistEntry::WAITING)
                ->where('event_bookings.calendar_event_id', $event->id)
                ->where('event_bookings.status', EventBooking::STATUS_WAITLISTED)
                ->where('event_bookings.active', 1)
                ->orderBy('event_bookings.waitlist_sequence')
                ->orderBy('event_bookings.id')
                ->select('event_waitlist_entries.*')
                ->lockForUpdate()->first();
            if ($head === null) {
                break;
            }

            /** @var EventBookingGroup $group */
            $group = EventBookingGroup::query()->whereKey($head->event_booking_group_id)->lockForUpdate()->firstOrFail();

            $permanent = $this->permanentFailure($group);
            // New MVP bookings have exactly one verified users.id. Historical
            // guest/group records must never be promoted into a valid seat.
            $attendees = $group->attendees()->get();
            if ($permanent === null && ($group->status !== EventBookingGroup::STATUS_WAITLISTED
                || (int) $group->calendar_event_id !== (int) $event->id
                || (int) $group->seat_count !== 1 || $attendees->count() !== 1
                || (int) $attendees->first()?->calendar_event_id !== (int) $event->id
                || $attendees->first()?->member_user_id === null
                || $attendees->first()?->status !== EventBookingAttendee::STATUS_WAITLISTED
                || $attendees->first()?->active != 1)) {
                $permanent = 'invalid_waitlist_entry';
            }
            if ($permanent === null) {
                // During the migration, never grant a seat to an attendee whose
                // identity differs from the canonical booking ledger.
                $canonical = EventBooking::query()->whereKey($group->legacy_booking_id)
                    ->where('calendar_event_id', $event->id)
                    ->where('status', EventBooking::STATUS_WAITLISTED)
                    ->where('active', 1)->lockForUpdate()->first();
                if ($canonical === null
                    || (int) $canonical->member_user_id !== (int) $attendees->first()->member_user_id
                    || (int) $canonical->booker_user_id !== (int) $group->booker_user_id) {
                    $permanent = 'canonical_booking_mismatch';
                }
            }
            if ($permanent === null) {
                $memberId = $attendees->first()?->member_user_id;
                $member = $memberId === null ? null : User::query()->find($memberId);
                if ($member === null || ! $member->is_active || (int) $member->verified_status !== 2) {
                    $permanent = 'attendee_unverified';
                } elseif ($member->id !== $group->booker_user_id) {
                    // A family proxy may lose delegation while waiting.
                    // Recheck current authorization before granting a seat.
                    $booker = User::query()->find($group->booker_user_id);
                    if ($booker === null || ! $this->canManageFamily($booker, $member, true)) {
                        $permanent = 'delegation_revoked';
                    }
                }
            }
            if ($permanent !== null) {
                $head->forceFill(['status' => EventWaitlistEntry::SKIPPED, 'skip_reason' => $permanent, 'resolved_at' => now()])->save();
                // A corrupt queue row may point to a booking owned by another event.
                // Skip this event's queue entry without cancelling a different event's
                // booking, invoice, attendance or calendar sync.
                if ((int) $group->calendar_event_id === (int) $event->id) {
                    $this->endGroup($group, EventBookingGroup::STATUS_CANCELLED, null, $permanent, quarantineInvalidWaitlist: true);
                }
                AuditEvent::record('booking.waitlist_skipped', null, $group, ['event_id' => $event->id, 'reason' => $permanent]);

                continue;
            }

            if ($this->confirmedSeats($event) >= (int) $event->booking_capacity) {
                break; // Strict FIFO: the next single-member booking must wait for one free seat.
            }

            // Near-start paid bookings use the earlier of the configured
            // hold and event start as their deadline (CashInvoiceService).
            // Do not block FIFO promotion merely because the full hold
            // period cannot fit before the event begins.
            $confirmedAt = now();
            $group->forceFill(['status' => EventBookingGroup::STATUS_CONFIRMED, 'confirmed_at' => $confirmedAt])->save();
            if ($group->legacy_booking_id !== null) {
                EventBooking::query()->whereKey($group->legacy_booking_id)
                    ->where('status', EventBooking::STATUS_WAITLISTED)
                    ->update(['status' => EventBooking::STATUS_CONFIRMED, 'confirmed_at' => $confirmedAt,
                        'waitlist_sequence' => null, 'updated_at' => $confirmedAt]);
            }
            app(CashInvoiceService::class)->forEvent($event, $group, (int) $attendees->first()->member_user_id);
            EventBookingAttendee::query()->where('event_booking_group_id', $group->id)->where('status', EventBookingAttendee::STATUS_WAITLISTED)
                ->update(['status' => EventBookingAttendee::STATUS_CONFIRMED, 'updated_at' => now()]);
            $head->forceFill(['status' => EventWaitlistEntry::PROMOTED, 'resolved_at' => now()])->save();

            AuditEvent::record('booking.promoted', null, $group, ['event_id' => $event->id, 'seats' => $group->seat_count]);
            $this->notice($group, '候補成功：'.$event->title, '已為您保留 1 個名額，請準時出席。');
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
        // Event changes can be called outside a booking transaction. Enqueue
        // notifications and Google sync in an explicit DB transaction so the
        // production database queue cannot silently fall back to afterCommit.
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

    private function assertActiveVerifiedMember(User $member): void
    {
        if (! User::query()->whereKey($member->id)->where('is_active', true)->where('verified_status', 2)->exists()) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '僅開放已驗證且啟用的會員預約。');
        }
    }

    private function validateRequest(CalendarEvent $event, BookingRequest $request): void
    {
        // No guest booking, including admin/internal calls and malformed payloads.
        if ($request->booker === null) {
            throw new ApiException(403, 'GUEST_NOT_ALLOWED', '活動不開放訪客預約，請先建立正式會員身分。');
        }
        $this->assertActiveVerifiedMember($request->booker);

        // Exactly one registered attendee per booking, independent of legacy
        // event booking_max_seats and administrator privileges.
        $seats = count($request->attendees);
        if ($seats !== 1) {
            throw new ApiException(422, 'SINGLE_ATTENDEE_ONLY', 'MVP 每筆預約只能有一位參加者。');
        }

        // Only a real, active and verified users.id can occupy the one seat.
        $attendeeId = $request->attendees[0]['member_user_id'] ?? null;
        // Strict positive integer: is_numeric() also accepts fractions and scientific
        // notation, which would silently coerce to a different users.id.
        $validatedMemberId = filter_var($attendeeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validatedMemberId === false) {
            throw new ApiException(422, 'MEMBER_ID_REQUIRED', '參加者必須有正式會員 ID。');
        }
        $attendee = User::query()->whereKey($validatedMemberId)
            ->where('is_active', true)->where('verified_status', 2)->first();
        if ($attendee === null) {
            throw new ApiException(422, 'INVALID_ATTENDEE', '參加者會員 ID 無效。');
        }
        // Internal callers cannot manufacture administrator authority by
        // setting byAdmin=true; the verified booker must actually be an admin.
        $isAdmin = $request->byAdmin && $request->booker->is_active
            && ! $request->booker->FamilyUser && $request->booker->isAdmin();
        if (! $isAdmin && ! $this->canManageFamily($request->booker, $attendee, true)) {
            throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '尚未取得此參加者的代訂授權。');
        }
        if ($this->activeAttendeeOf($event, $attendee) !== null) {
            throw new ApiException(409, 'ALREADY_BOOKED', '此會員已預約此活動。');
        }

        // MVP limit is per attendee users.id, enforced by activeAttendeeOf().
        // Do not cap bookings per booker: one guardian may book separately
        // for multiple authorized family members, one member per request.

        // Eligibility is checked when booking only (D10, D19).
        $this->assertEligible($event, $attendee);
    }

    private function permanentFailure(EventBookingGroup $group): ?string
    {
        $booker = $group->booker_user_id !== null ? User::query()->find($group->booker_user_id) : null;

        return match (true) {
            $booker === null => 'booker_deleted',
            ! $booker->is_active => 'booker_disabled',
            (int) $booker->verified_status !== 2 => 'booker_unverified',
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

    private function endGroup(EventBookingGroup $group, string $status, ?User $actor, ?string $reason, bool $quarantineInvalidWaitlist = false): void
    {
        // Lock canonical identity before any legacy invoice or attendance mutation.
        $legacy = null;
        if ($group->legacy_booking_id !== null) {
            // Never cancel an unrelated canonical booking through a stale group
            // pointer. Only the same event, booker and single attendee may be
            // transitioned while the legacy tables are being retired.
            $attendeeMemberId = EventBookingAttendee::query()
                ->where('event_booking_group_id', $group->id)->value('member_user_id');
            $legacy = EventBooking::query()->whereKey($group->legacy_booking_id)
                ->where('calendar_event_id', $group->calendar_event_id)
                ->where('booker_user_id', $group->booker_user_id)
                ->where('member_user_id', $attendeeMemberId)
                ->whereIn('status', [EventBooking::STATUS_CONFIRMED, EventBooking::STATUS_WAITLISTED])
                ->lockForUpdate()->first();
            if ($legacy === null && ! $quarantineInvalidWaitlist) {
                throw new ApiException(409, 'CANONICAL_BOOKING_MISMATCH', '預約資料關聯不一致，請洽管理員。');
            }
        }

        DB::table('invoices')->where('booking_type', '01')->where('booking_id', $group->id)
            ->where('invoice_status', 0)->update(['invoice_status' => 2, 'updated_at' => now()]);
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

        if ($legacy !== null) {
            $legacy->forceFill([
                'status' => $status === EventBookingGroup::STATUS_EVENT_CANCELLED
                    ? EventBooking::STATUS_EVENT_CANCELLED : EventBooking::STATUS_CANCELLED,
                'active' => null,
                'waitlist_sequence' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ])->save();
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
            'waitlist_sequence' => null,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->id,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createGroup(CalendarEvent $event, array $attributes): EventBookingGroup
    {
        // R1 invariant at the shared write boundary: neither legacy nor S9
        // callers may create a multi-seat booking, including admin paths.
        if (($attributes['seat_count'] ?? null) !== 1 || empty($attributes['booker_user_id'])) {
            throw new ApiException(422, 'SINGLE_MEMBER_BOOKING_REQUIRED', '每筆活動報名必須由一位正式會員操作，且僅佔一個名額。');
        }

        $now = now();

        return EventBookingGroup::query()->create($attributes + [
            'calendar_event_id' => $event->id,
            'booked_at' => $now,
            'confirmed_at' => ($attributes['status'] ?? null) === EventBookingGroup::STATUS_CONFIRMED ? $now : null,
        ]);
    }

    private function createAttendee(EventBookingGroup $group, string $name, ?int $memberId, string $status): EventBookingAttendee
    {
        // Do not let any internal caller persist a guest attendee.
        if ($memberId === null || $memberId <= 0) {
            throw new ApiException(422, 'MEMBER_ID_REQUIRED', '參加者必須有正式會員 ID。');
        }
        // A one-seat booking can never acquire a second attendee through an
        // internal/admin code path. The event lock serialises normal booking
        // writes; this is an additional guard against future callers.
        if ((int) $group->seat_count !== 1
            || EventBookingAttendee::query()->where('event_booking_group_id', $group->id)->exists()) {
            throw new ApiException(422, 'SINGLE_ATTENDEE_ONLY', '每筆活動報名只能建立一位參加者。');
        }

        $attendee = EventBookingAttendee::query()->create([
            'event_booking_group_id' => $group->id,
            'calendar_event_id' => $group->calendar_event_id,
            'member_user_id' => $memberId,
            'name' => mb_substr(trim($name), 0, 80),
            'status' => $status,
            'active' => 1,
        ]);

        // R1 transition: both S9 and S6 share the canonical booking identity.
        // The outer booking transaction owns this write and rolls back both
        // ledgers together. S6 already created the linked canonical row.
        if ($group->legacy_booking_id === null) {
            $booking = EventBooking::query()->create([
                'calendar_event_id' => $group->calendar_event_id,
                'user_id' => $memberId, // Legacy S6 compatibility column.
                'member_user_id' => $memberId,
                'booker_user_id' => $group->booker_user_id,
                'client_request_id' => $group->client_request_id,
                'status' => $status,
                'active' => 1,
                'booked_at' => $group->booked_at,
                'confirmed_at' => $status === EventBooking::STATUS_CONFIRMED ? now() : null,
            ]);
            $group->forceFill(['legacy_booking_id' => $booking->id])->save();
        }

        return $attendee;
    }

    private function replay(EventBookingGroup $existing, CalendarEvent $event, BookingRequest $request): EventBookingGroup
    {
        $same = $existing->calendar_event_id === $event->id
            && $existing->booker_user_id === $request->booker?->id
            && ($request->booker !== null || ($existing->contact_name === $request->contactName
                && $existing->contact_email === $request->contactEmail
                && $existing->contact_phone === $request->contactPhone));
        // The attendee's immutable users.id, not their mutable display name,
        // identifies an idempotent retry. Legacy multi-attendee records are rejected.
        $storedMemberIds = $existing->attendees()->orderBy('id')->pluck('member_user_id')
            ->map(static fn ($id): ?int => $id === null ? null : (int) $id)->all();
        $incomingMemberId = count($request->attendees) === 1
            ? ($request->attendees[0]['member_user_id'] ?? null)
            : null;
        // Apply the same strict ID parsing to idempotent retries as to new bookings.
        // A malformed ID must not be coerced to a different registered member.
        $parsedMemberId = filter_var($incomingMemberId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $same || count($request->attendees) !== 1 || $existing->seat_count !== 1
            || count($storedMemberIds) !== 1 || $storedMemberIds[0] === null
            || $parsedMemberId === false || $storedMemberIds[0] !== $parsedMemberId) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', '此 client_request_id 已用於不同的報名內容。');
        }

        // Replays cannot revive or disclose historical guest/group bookings.
        // The current MVP contract applies even when the idempotency key exists.
        if ($request->booker === null) {
            throw new ApiException(403, 'GUEST_NOT_ALLOWED', '活動不開放訪客預約。');
        }
        $this->assertActiveVerifiedMember($request->booker);
        // An idempotent replay is still a read of protected booking details.
        // Admin callers must not bypass attendee validity; family delegation is
        // rechecked for members to prevent access after authorization revocation.
        $attendee = User::query()->whereKey($storedMemberIds[0])
            ->where('is_active', true)->where('verified_status', 2)->first();
        if ($attendee === null) {
            throw new ApiException(403, 'INVALID_ATTENDEE', '參加者會員資格已失效。');
        }
        // A caller-supplied byAdmin flag is not proof of administrator rights.
        // The booker must still own this request, and any delegated booking
        // must pass current authorization unless the booker is a real admin.
        $isAdmin = $request->byAdmin && $request->booker->is_active
            && ! $request->booker->FamilyUser && $request->booker->isAdmin();
        if (! $isAdmin && ! $this->canManageFamily($request->booker, $attendee, true)) {
            throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '已無權查看此家庭會員的預約。');
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
    private function assertDurableQueueConfiguration(): void
    {
        if (app()->environment('production') && (
            config('queue.default') !== 'database'
            || (config('queue.connections.database.connection') !== null
                && config('queue.connections.database.connection') !== DB::getDefaultConnection())
            || config('queue.connections.database.after_commit') !== false
        )) {
            throw new \LogicException('Booking durable handoff requires the database queue on the booking DB with after_commit=false.');
        }
    }

    private function transaction(callable $callback): mixed
    {
        $this->assertDurableQueueConfiguration();

        try {
            return DB::transaction($callback, 3);
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

        // A course may book many sessions inside one outer transaction.
        // Never perform external side effects until that transaction commits.
        $deliver = function () use ($notices, $sync): void {
            foreach ($notices as [$group, $title, $body]) {
                $this->effects->notify($group, $title, $body);
            }
            foreach ($sync as $userId => $events) {
                foreach (array_keys($events) as $eventId) {
                    $this->effects->syncBooking($userId, $eventId);
                }
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);
        } else {
            $deliver();
        }
    }
}
