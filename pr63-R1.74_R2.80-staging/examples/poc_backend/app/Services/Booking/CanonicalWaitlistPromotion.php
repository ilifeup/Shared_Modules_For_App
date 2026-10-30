<?php

namespace App\Services\Booking;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical FIFO promotion, without side effects. This is NOT a public entry
 * point: the caller must integrate invoice, audit, notifications and sync in
 * the same owning workflow before routing production traffic through it.
 */
final class CanonicalWaitlistPromotion
{
    /**
     * The caller must already hold the calendar_events FOR UPDATE lock and
     * own the outer transaction. No independent transaction is started here.
     *
     * @return list<EventBooking>
     */
    public function promoteLocked(CalendarEvent $event): array
    {
        if (DB::transactionLevel() < 1) {
            throw new ApiException(409, 'BOOKING_TRANSACTION_REQUIRED', '候補遞補必須在活動鎖定交易內執行。');
        }

        $locked = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== CalendarEvent::STATUS_PUBLISHED || ! $locked->booking_enabled) {
            return [];
        }
        // A course-owned occurrence must be managed by the term enrollment
        // workflow. Standalone FIFO promotion would bypass course seats,
        // payment state and whole-term cancellation consistency.
        if (app(CanonicalSeriesOwnership::class)->ownsEvent((int) $locked->id)) {
            throw new ApiException(409, 'SERIES_BOOKING_REQUIRED',
                '系列課程的候補遞補必須由整期報名流程處理。');
        }
        // Prevent canonical promotion while any legacy queue still owns the
        // occurrence. Legacy and canonical FIFO cannot run concurrently.
        if (app(CanonicalLegacyWaitlistBoundary::class)->hasUnmigratedWaitingEntries((int) $locked->id)) {
            throw new ApiException(409, 'LEGACY_WAITLIST_PENDING', '候補資料尚未完成正式預約轉換。');
        }

        // Do not mint an unbilled confirmed seat or mix the old group
        // ledger with the canonical queue during the migration window.
        if (Schema::hasTable('event_booking_groups')
            && DB::table('event_booking_groups')->where('calendar_event_id', $locked->id)->exists()) {
            throw new ApiException(409, 'LEGACY_BOOKING_CUTOVER_PENDING',
                '此活動仍有舊版預約資料，不能啟用正式候補遞補。');
        }
        if ((float) $locked->price_amount > 0) {
            throw new ApiException(409, 'CANONICAL_PAYMENT_INTEGRATION_PENDING',
                '付費活動的正式預約帳務尚未完成整合，禁止無帳單遞補。');
        }

        // Unlimited-capacity events do not have a finite seat limit.
        // Bound promotion by the current queue length rather than looping
        // against a NULL capacity cast to zero.
        $capacity = $locked->booking_capacity;
        $promoted = [];
        while (EventBooking::query()->where('calendar_event_id', $locked->id)
            ->where('status', EventBooking::STATUS_CONFIRMED)->where('active', 1)->count()
            < ($capacity === null ? PHP_INT_MAX : (int) $capacity)) {
            $head = EventBooking::query()->where('calendar_event_id', $locked->id)
                ->where('status', EventBooking::STATUS_WAITLISTED)->where('active', 1)
                ->orderBy('waitlist_sequence')->orderBy('id')->lockForUpdate()->first();
            if ($head === null) {
                break;
            }
            // A missing sequence makes FIFO ordering unknowable. Do not let
            // NULL sort ahead of valid historical queue positions.
            if ($head->waitlist_sequence === null || (int) $head->waitlist_sequence < 1) {
                throw new ApiException(409, 'WAITLIST_SEQUENCE_INVALID', '候補順序資料不完整，請先修復。');
            }
            // An ambiguous sequence must not be resolved by ID tie-breaking:
            // historical data may have been imported with duplicate positions.
            // Require manual reconciliation rather than silently reordering FIFO.
            if (EventBooking::query()->where('calendar_event_id', $locked->id)
                ->where('status', EventBooking::STATUS_WAITLISTED)->where('active', 1)
                ->where('waitlist_sequence', $head->waitlist_sequence)->whereKeyNot($head->id)->exists()) {
                throw new ApiException(409, 'WAITLIST_SEQUENCE_DUPLICATE',
                    '候補序號重複，無法確認先後順序，請先修復。');
            }
            // The transitional S6 user_id must identify the same seat owner.
            // A corrupted head cannot be promoted even when the referenced
            // member and booker accounts are otherwise valid.
            if ($head->member_user_id === null || $head->booker_user_id === null
                || (int) $head->user_id !== (int) $head->member_user_id) {
                throw new ApiException(409, 'CANONICAL_BOOKING_MISMATCH',
                    '候補首位的正式預約會員關聯不一致，請先修復。');
            }
            // Never jump the FIFO head: if qualification is no longer valid,
            // stop for an administrator to resolve the blocked queue entry.
            $member = User::query()->whereKey($head->member_user_id)
                ->where('is_active', true)->where('verified_status', 2)->first();
            $booker = User::query()->whereKey($head->booker_user_id)
                ->where('is_active', true)->where('verified_status', 2)->first();
            // Administrative reservations are not family delegations. A
            // verified active administrator can remain the original booker,
            // but the privilege must be checked again at promotion time.
            // Ordinary bookers still require current family authorization.
            $administrativeBooker = $booker !== null
                && ! $booker->FamilyUser && $booker->isAdmin();
            if ($member === null || $booker === null
                || (! $administrativeBooker
                    && ! app(BookingEngine::class)->canManageFamily($booker, $member, true))) {
                throw new ApiException(409, 'WAITLIST_HEAD_REQUIRES_REVIEW', '候補首位會員資格或代訂權限需要人工處理。');
            }
            // Eligibility may change while a member waits. Revalidate the
            // actual attendee before occupying the newly released seat.
            app(BookingEngine::class)->assertEligible($locked, $member);
            // Resolve the canonical queue projection in the same transaction.
            // Never leave a promoted booking with a legacy `waiting` entry.
            if (! Schema::hasTable('event_waitlist_entries')
                || ! Schema::hasColumn('event_waitlist_entries', 'event_booking_id')
                || ! Schema::hasColumn('event_waitlist_entries', 'sequence')) {
                throw new ApiException(409, 'WAITLIST_PROJECTION_SCHEMA_PENDING',
                    '候補對照資料結構未完成，禁止正式候補遞補。');
            }
            $entries = DB::table('event_waitlist_entries')
                ->where('event_booking_id', $head->id)->lockForUpdate()->get();
            // Every new canonical waitlist booking creates exactly one projection.
            // A missing row is not a compatibility case: it is a partial write
            // or incomplete backfill and must not silently become confirmed.
            if ($entries->count() !== 1 || $entries->contains(fn ($entry) =>
                (int) $entry->calendar_event_id !== (int) $locked->id
                || $entry->status !== 'waiting'
                || (int) $entry->sequence !== (int) $head->waitlist_sequence)) {
                throw new ApiException(409, 'CANONICAL_WAITLIST_PROJECTION_MISMATCH',
                    '候補對照資料缺失、重複或與正式預約不一致，請先修復。');
            }
            DB::table('event_waitlist_entries')
                ->where('id', $entries->first()->id)
                ->update(['status' => 'promoted', 'resolved_at' => now(), 'updated_at' => now()]);
            $head->forceFill([
                'status' => EventBooking::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                // Preserve the original FIFO sequence for audit and monotonic allocation.
            ])->save();
            $promoted[] = $head;
        }

        return $promoted;
    }
}
