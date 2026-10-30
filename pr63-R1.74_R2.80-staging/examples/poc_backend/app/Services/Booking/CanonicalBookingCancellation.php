<?php

namespace App\Services\Booking;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical-only cancellation transition. Callers must integrate invoice,
 * attendance, notification and calendar side effects before enabling it.
 * Does not operate on course-owned events or legacy booking groups.
 */
final class CanonicalBookingCancellation
{
    public function cancel(EventBooking $booking, User $actor, bool $byAdmin = false, ?string $reason = null, ?array &$promotedBookingIds = null): EventBooking
    {
        // Capture IDs locally; expose them only after the whole transaction commits.
        $promoted = [];
        $result = DB::transaction(function () use ($booking, $actor, $byAdmin, $reason, &$promoted): EventBooking {
            $event = CalendarEvent::query()->whereKey($booking->calendar_event_id)->lockForUpdate()->firstOrFail();
            if (app(CanonicalSeriesOwnership::class)->ownsEvent((int) $event->id)) {
                throw new ApiException(409, 'SERIES_BOOKING_MANAGED_BY_PARENT', '系列課程須由整期流程取消。');
            }
            $locked = EventBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->calendar_event_id !== (int) $event->id) {
                throw new ApiException(409, 'CANONICAL_BOOKING_MISMATCH', '預約活動關聯不一致。');
            }
            // Authorization must use current database state, not a stale User
            // instance supplied by the controller before this transaction.
            $currentActor = User::query()->whereKey($actor->id)->sharedLock()->first();
            if ($currentActor === null || ! $currentActor->is_active
                || (int) $currentActor->verified_status !== 2) {
                throw new ApiException(403, 'MEMBER_NOT_VERIFIED',
                    '取消預約者必須是有效且已驗證的會員。');
            }
            $actor = $currentActor;
            $admin = $byAdmin && ! $actor->FamilyUser && $actor->isAdmin();
            if (! $admin && (int) $actor->id !== (int) $locked->member_user_id
                && (int) $actor->id !== (int) $locked->booker_user_id) {
                throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '無權取消此預約。');
            }
            // The original booker is not a perpetual delegate: if family
            // management has ended, only the member or a real admin may cancel.
            if (! $admin && (int) $actor->id === (int) $locked->booker_user_id
                && (int) $actor->id !== (int) $locked->member_user_id) {
                $member = User::query()->whereKey($locked->member_user_id)->first();
                if ($member === null || ! app(BookingEngine::class)->canManageFamily($actor, $member, true)) {
                    throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED',
                        '原代訂人的家庭代管權限已失效，請由會員或管理員處理。');
                }
            }
            if (! $locked->isActive()) {
                throw new ApiException(409, 'BOOKING_NOT_ACTIVE', '此預約已取消。');
            }
            if (! $admin && $locked->status !== EventBooking::STATUS_WAITLISTED
                && now()->gte($event->cancelClosesAt())) {
                throw new ApiException(409, 'CANCEL_DEADLINE_PASSED', '已超過取消期限。');
            }
            // Do not permit a partial canonical cancellation while legacy
            // invoice/attendance/group pointers are still authoritative.
            // Cutover is per occurrence, not per installed legacy schema.
            // A legacy group table may legitimately exist for other events;
            // only rows for THIS event make canonical-only cancellation unsafe.
            // Never require a transitional legacy_booking_id column just to
            // cancel a clean canonical occurrence.
            if (Schema::hasTable('event_booking_groups')
                && DB::table('event_booking_groups')
                    ->where('calendar_event_id', $event->id)->exists()) {
                throw new ApiException(409, 'LEGACY_DEPENDENCIES_PENDING',
                    '此活動仍有舊版預約群組，須先完成整批轉換。');
            }
            // A paid booking can have an invoice in the legacy invoices ledger
            // even when no invoice_booking_items mapping exists. Until the
            // canonical invoice/refund contract is implemented, never release
            // its seat through this isolated cancellation service, regardless
            // of whether there are members waiting.
            if ((float) $event->price_amount > 0) {
                throw new ApiException(409, 'CANONICAL_PAYMENT_INTEGRATION_PENDING',
                    '付費活動尚未完成正式預約取消與退款整合。');
            }

            // A confirmed cancellation must promote the FIFO head in the same
            // event-locked transaction; otherwise a released seat can strand
            // waiting members. Paid events remain blocked until invoice cutover.
            $hasWaiting = $locked->status === EventBooking::STATUS_CONFIRMED
                && EventBooking::query()->where('calendar_event_id', $event->id)
                    ->where('status', EventBooking::STATUS_WAITLISTED)
                    ->where('active', 1)->exists();

            // The new canonical link columns are transitional. Never leave
            // invoice, attendance, makeup or waitlist dependencies behind.
            foreach (['course_makeups', 'invoice_booking_items', 'event_attendances'] as $table) {
                // Optional modules may not have been installed yet. A table
                // that exists without its canonical FK is unsafe, however.
                if (! Schema::hasTable($table)) {
                    continue;
                }
                if (! Schema::hasColumn($table, 'event_booking_id')) {
                    throw new ApiException(409, 'CANONICAL_SCHEMA_INCOMPLETE',
                        '正式預約關聯欄位尚未完成 Migration。');
                }
                if (DB::table($table)->where('event_booking_id', $locked->id)->exists()) {
                    throw new ApiException(409, 'CANONICAL_DEPENDENCIES_PENDING',
                        '此預約仍有帳務、簽到或課程關聯，須由完整取消流程處理。');
                }
            }
            // Waitlist projections are not external dependencies: validate and
            // transition them below instead of rejecting every linked waiting row.
            // Cancel the canonical waitlist projection atomically with its booking.
            // A waiting projection must never survive a cancelled queue position.
            if ($locked->status === EventBooking::STATUS_WAITLISTED) {
                if (! Schema::hasTable('event_waitlist_entries')
                    || ! Schema::hasColumn('event_waitlist_entries', 'event_booking_id')) {
                    throw new ApiException(409, 'CANONICAL_SCHEMA_INCOMPLETE',
                        '候補對照資料表或關聯欄位尚未完成 Migration。');
                }
                $entries = DB::table('event_waitlist_entries')
                    ->where('event_booking_id', $locked->id)->lockForUpdate()->get();
                if ($entries->count() !== 1 || $entries->contains(fn ($entry) =>
                    (int) $entry->calendar_event_id !== (int) $event->id
                    || $entry->status !== 'waiting'
                    || (int) $entry->sequence !== (int) $locked->waitlist_sequence)) {
                    throw new ApiException(409, 'CANONICAL_WAITLIST_PROJECTION_MISMATCH',
                        '候補對照資料與正式預約不一致，請先修復。');
                }
                DB::table('event_waitlist_entries')
                    ->where('id', $entries->first()->id)
                    ->update(['status' => 'cancelled', 'resolved_at' => now(), 'updated_at' => now()]);
            }
            $locked->forceFill([
                'status' => EventBooking::STATUS_CANCELLED,
                'active' => null,
                // Preserve historical FIFO sequence even after cancellation.
                // The writer allocates max(sequence)+1 across all booking states.
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            ])->save();
            // The promoter uses the same event lock and outer transaction.
            // Any invalid FIFO head rolls back both cancellation and promotion.
            if ($hasWaiting) {
                $promoted = array_map(
                    static fn (EventBooking $row): int => (int) $row->id,
                    app(CanonicalWaitlistPromotion::class)->promoteLocked($event)
                );
            }
            // Persist cancellation and all resulting FIFO promotions in the same
            // transaction. The consumer remains disabled until deployed and tested.
            if (config('booking.canonical_outbox_ready', false)) {
                $outbox = app(CanonicalBookingOutbox::class);
                $outbox->record($locked, EventBooking::STATUS_CANCELLED);
                foreach ($promoted as $promotedId) {
                    $promotedBooking = EventBooking::query()->findOrFail($promotedId);
                    $outbox->record($promotedBooking, EventBooking::STATUS_CONFIRMED);
                }
            }
            // API routing still requires audit, notifications and calendar sync.
            return $locked->refresh();
        }, 3);
        $promotedBookingIds = $promoted;
        return $result;
    }
}
