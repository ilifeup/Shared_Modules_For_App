<?php

namespace App\Services\Booking;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Services\Payment\CanonicalEventInvoiceService;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

/**
 * Canonical reservation write boundary.
 *
 * This component deliberately does not create legacy groups, invoices,
 * notifications or course enrollments. BookingEngine must orchestrate those
 * integrations before switching API routes to this writer.
 */
final class CanonicalBookingWriter
{
    public function reserve(
        CalendarEvent $event,
        User $booker,
        User $member,
        ?string $requestId = null,
        bool $allowWaitlist = true,
        bool $byAdmin = false,
        ?bool &$created = null
    ): EventBooking {
        try {
            return DB::transaction(function () use ($event, $booker, $member, $requestId, $allowWaitlist, $byAdmin, &$created): EventBooking {
            // Lock the event first: the same ordering must be used by
            // cancellation, capacity updates and FIFO promotion.
            $locked = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $this->verified($booker);
            $this->verified($member);
            // Recheck delegation even for idempotent replays; a revoked
            // family manager must not retrieve another member's reservation.
            // Never authorize an administrative override from a potentially
            // stale User object supplied by the HTTP layer. Read current
            // authority under the booking transaction before granting a seat.
            $currentBooker = User::query()->whereKey($booker->id)->sharedLock()->firstOrFail();
            $isAdmin = $byAdmin && $currentBooker->is_active
                && (int) $currentBooker->verified_status === 2
                && ! $currentBooker->FamilyUser && $currentBooker->isAdmin();
            if (! $isAdmin && ! app(BookingEngine::class)->canManageFamily($currentBooker, $member, true)) {
                throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '沒有此會員的有效代訂權限。');
            }

            // Check cutover and billing ownership BEFORE idempotent replay.
            // Otherwise a previously created booking can be returned after a
            // legacy group appears, or when paid billing is still unsupported.
            // The legacy engine still owns invoices and queue side effects.
            // Do not silently admit a canonical-only booking to an occurrence
            // whose group ledger is still authoritative.
            if (Schema::hasTable('event_booking_groups')
                && DB::table('event_booking_groups')->where('calendar_event_id', $locked->id)->exists()) {
                throw new ApiException(409, 'LEGACY_BOOKING_CUTOVER_PENDING',
                    '此活動仍使用舊版預約資料，須完成整批轉換後才能啟用正式單人預約。');
            }
            // A legacy waiting entry can exist without a surviving group row
            // after partial migration. Do not admit a canonical booking ahead
            // of that queue, even when the legacy group ledger looks empty.
            if (app(CanonicalLegacyWaitlistBoundary::class)->hasUnmigratedWaitingEntries((int) $locked->id)) {
                throw new ApiException(409, 'LEGACY_WAITLIST_PENDING',
                    '此活動仍有舊版候補資料，須完成 FIFO 轉換後才能啟用正式單人預約。');
            }
            // Paid cutover is explicitly disabled until refunds, reconciliation,
            // exports and notifications all understand invoice booking_type=03.
            // This flag must not be enabled by deploying this draft alone.
            if ((float) $locked->price_amount > 0 && ! config('booking.canonical_paid_cutover_ready', false)) {
                throw new ApiException(409, 'CANONICAL_PAYMENT_INTEGRATION_PENDING',
                    '付費活動尚未完成正式預約與帳單整合。');
            }

            // Idempotency keys are global; replay must verify all immutable
            // identities and never return another member's booking.
            if ($requestId !== null) {
                $existing = EventBooking::query()->where('client_request_id', $requestId)->first();
                if ($existing !== null) {
                    if ((int) $existing->calendar_event_id !== (int) $locked->id
                        || (int) $existing->booker_user_id !== (int) $booker->id
                        || (int) $existing->member_user_id !== (int) $member->id) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', '此請求識別碼已用於其他預約。');
                    }

                    // A cancelled or event-cancelled booking is historical data,
                    // not a successful reservation. Reusing its request key
                    // must never resurrect or misreport a released seat.
                    if (! $existing->isActive()) {
                        throw new ApiException(409, 'IDEMPOTENCY_BOOKING_INACTIVE',
                            '此請求對應的預約已失效，重新預約須使用新的請求識別碼。');
                    }

                    // An event cancellation invalidates all reservations even
                    // if a legacy path has not yet marked their rows inactive.
                    // Never report a successful replay for a cancelled event.
                    if ($locked->status === CalendarEvent::STATUS_CANCELLED) {
                        throw new ApiException(409, 'EVENT_CANCELLED', '活動已取消，無法重送預約。');
                    }

                    if ((float) $locked->price_amount > 0 && $existing->status === EventBooking::STATUS_CONFIRMED) {
                        app(CanonicalEventInvoiceService::class)->createForConfirmedBooking($locked, $existing);
                    }
                    $created = false;
                    return $existing;
                }
            }

            if (EventBooking::query()->where('calendar_event_id', $locked->id)
                ->where('member_user_id', $member->id)->where('active', 1)->exists()) {
                throw new ApiException(409, 'ALREADY_BOOKED', '此會員已預約此活動。');
            }

            if ($locked->status !== CalendarEvent::STATUS_PUBLISHED || ! $locked->booking_enabled) {
                throw new ApiException(409, 'BOOKING_UNAVAILABLE', '此活動未開放預約。');
            }
            if (($locked->booking_opens_at !== null && now()->lt($locked->booking_opens_at))
                || now()->gte($locked->bookingClosesAt())) {
                throw new ApiException(409, 'BOOKING_UNAVAILABLE', '不在預約開放時間內。');
            }
            // This writer does not independently book a course-owned session.
            if (app(CanonicalSeriesOwnership::class)->ownsEvent((int) $locked->id)) {
                throw new ApiException(409, 'SERIES_BOOKING_REQUIRED', '系列課程須使用整期報名流程。');
            }

            // Eligibility is checked against the actual seat owner, never
            // against the guardian or administrative actor.
            app(BookingEngine::class)->assertEligible($locked, $member);

            $confirmed = EventBooking::query()->where('calendar_event_id', $locked->id)
                ->where('status', EventBooking::STATUS_CONFIRMED)->where('active', 1)->count();
            $waiting = EventBooking::query()->where('calendar_event_id', $locked->id)
                ->where('status', EventBooking::STATUS_WAITLISTED)->where('active', 1)->count();

            // A NULL capacity is unlimited; do not cast it to zero.
            $full = ($locked->booking_capacity !== null
                && $confirmed >= (int) $locked->booking_capacity) || $waiting > 0;
            if ($full && (! $allowWaitlist || ! $locked->waitlist_enabled
                || ($locked->waitlist_capacity !== null
                    && $waiting >= (int) $locked->waitlist_capacity))) {
                throw new ApiException(409, 'BOOKING_FULL', '活動或候補名額已滿。');
            }

            // A paid waitlist cannot be promoted until the billing lifecycle
            // and payment policy for promoted members are fully integrated.
            if ($full && (float) $locked->price_amount > 0) {
                throw new ApiException(409, 'CANONICAL_PAID_WAITLIST_PENDING',
                    '付費活動候補帳務流程尚未完成。');
            }
            // A newly allocated FIFO position must be strictly greater than
            // every historical position, including cancelled/promoted rows.
            // Do not silently overflow the unsigned INT column or continue
            // from a corrupted negative sequence.
            $nextSequence = null;
            if ($full) {
                // Existing duplicate positions make FIFO order ambiguous. Do not
                // admit more members into a corrupted queue until repaired.
                $duplicateSequence = EventBooking::query()
                    ->where('calendar_event_id', $locked->id)
                    ->where('status', EventBooking::STATUS_WAITLISTED)
                    ->where('active', 1)
                    ->whereNotNull('waitlist_sequence')
                    ->select('waitlist_sequence')
                    ->groupBy('waitlist_sequence')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();
                $invalidSequence = EventBooking::query()
                    ->where('calendar_event_id', $locked->id)
                    ->where('status', EventBooking::STATUS_WAITLISTED)
                    ->where('active', 1)
                    ->where(function ($query) {
                        $query->whereNull('waitlist_sequence')
                            ->orWhere('waitlist_sequence', '<=', 0);
                    })->exists();
                if ($duplicateSequence || $invalidSequence) {
                    throw new ApiException(409, 'WAITLIST_SEQUENCE_CORRUPT',
                        '候補排序資料不完整或重複，請先修復後再接受候補。');
                }
                $maxSequence = EventBooking::query()
                    ->where('calendar_event_id', $locked->id)
                    ->max('waitlist_sequence');
                if ($maxSequence !== null && (! ctype_digit((string) $maxSequence)
                    || (float) $maxSequence >= 4294967295)) {
                    throw new ApiException(409, 'WAITLIST_SEQUENCE_EXHAUSTED',
                        '候補序號異常或已達資料庫上限，請先進行資料修復。');
                }
                $nextSequence = $maxSequence === null ? 1 : (int) $maxSequence + 1;
            }
            $time = now();
            $booking = EventBooking::query()->forceCreate([
                'calendar_event_id' => $locked->id,
                'user_id' => $member->id, // Transitional S6 compatibility.
                'member_user_id' => $member->id,
                'booker_user_id' => $booker->id,
                'client_request_id' => $requestId,
                'status' => $full ? EventBooking::STATUS_WAITLISTED : EventBooking::STATUS_CONFIRMED,
                'active' => 1,
                'waitlist_sequence' => $nextSequence,
                'booked_at' => $time,
                'confirmed_at' => $full ? null : $time,
            ]);
            if ($full) {
                // The canonical FIFO ledger and its compatibility projection
                // must be created atomically. Otherwise cancellation/promotion
                // cannot reconcile the queue after a successful reservation.
                if (! Schema::hasTable('event_waitlist_entries')
                    || ! Schema::hasColumn('event_waitlist_entries', 'event_booking_id')
                    || ! Schema::hasColumn('event_waitlist_entries', 'sequence')) {
                    throw new ApiException(409, 'WAITLIST_PROJECTION_SCHEMA_PENDING',
                        '候補對照資料表尚未完成正式預約結構更新。');
                }
                DB::table('event_waitlist_entries')->insert([
                    'calendar_event_id' => $locked->id,
                    'event_booking_id' => $booking->id,
                    'sequence' => $nextSequence,
                    'status' => 'waiting',
                    'created_at' => $time,
                    'updated_at' => $time,
                ]);
            }
            if (! $full && (float) $locked->price_amount > 0) {
                // Same transaction as the seat: invoice failure rolls back both.
                app(CanonicalEventInvoiceService::class)->createForConfirmedBooking($locked, $booking);
            }
            // Record the transition before commit, atomically with the seat.
            // Disabled until the outbox migration and consumer are deployed.
            if (config('booking.canonical_outbox_ready', false)) {
                app(CanonicalBookingOutbox::class)->record($booking, $booking->status);
            }
            $created = true;
            return $booking;
            }, 3);
        } catch (QueryException $exception) {
            // Concurrent unique-key conflicts must not be reported as raw SQL
            // errors. Never treat a different member/request as a valid replay.
            // MySQL SQLSTATE 23000 covers FK, NOT NULL and CHECK failures as
            // well as duplicate keys. Only duplicate-key error 1062 is a
            // reservation race; PostgreSQL uses the specific SQLSTATE 23505.
            // Never mask a schema/foreign-key defect as ALREADY_BOOKED.
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if (! ($sqlState === '23505'
                || ($sqlState === '23000' && $driverCode === 1062))) {
                throw $exception;
            }
            if ($requestId !== null) {
                $existing = EventBooking::query()->where('client_request_id', $requestId)->first();
                if ($existing !== null) {
                    if ((int) $existing->calendar_event_id !== (int) $event->id
                        || (int) $existing->booker_user_id !== (int) $booker->id
                        || (int) $existing->member_user_id !== (int) $member->id) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', '此請求識別碼已用於其他預約。');
                    }
                    // Re-run the regular replay path to revalidate delegation.
                    return $this->reserve($event, $booker, $member, $requestId, $allowWaitlist, $byAdmin);
                }
            }
            if (EventBooking::query()->where('calendar_event_id', $event->id)
                ->where('member_user_id', $member->id)->where('active', 1)->exists()) {
                throw new ApiException(409, 'ALREADY_BOOKED', '此會員已預約此活動。');
            }
            // Other FK / constraint failures must remain visible to the caller.
            throw $exception;
        }
    }

    private function verified(User $user): void
    {
        // A locking read is required inside the reservation transaction:
        // a consistent snapshot could otherwise accept a member whose
        // verification was revoked by a concurrent transaction.
        if (User::query()->whereKey($user->id)
            ->where('is_active', true)->where('verified_status', 2)
            ->sharedLock()->first() === null) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '僅接受有效且已驗證的會員。');
        }
    }
}
