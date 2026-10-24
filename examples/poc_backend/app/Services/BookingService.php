<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * S6 M08 bookings. Capacity is checked and the booking created inside one
 * transaction holding a row lock on the event (SELECT ... FOR UPDATE), so parallel
 * requests for the last seat cannot both succeed. A unique index on
 * (event, member, active) is the final guard against duplicate active bookings.
 */
final class BookingService
{
    /**
     * Why an event does not accept new bookings now, or null when it does.
     * One of: event_not_published, event_cancelled, disabled, not_open, closed, full.
     */
    public function unavailableReason(CalendarEvent $event, ?int $confirmed = null): ?string
    {
        $now = now();

        return match (true) {
            $event->status === CalendarEvent::STATUS_CANCELLED => 'event_cancelled',
            $event->status !== CalendarEvent::STATUS_PUBLISHED => 'event_not_published',
            ! $event->booking_enabled => 'disabled',
            $event->booking_opens_at !== null && $now->lt($event->booking_opens_at) => 'not_open',
            $now->gte($event->bookingClosesAt()) => 'closed',
            ($confirmed ?? $this->confirmedCount($event)) >= (int) $event->booking_capacity => 'full',
            default => null,
        };
    }

    public function confirmedCount(CalendarEvent $event): int
    {
        return $event->bookings()->where('status', EventBooking::STATUS_CONFIRMED)->count();
    }

    public function activeBookingOf(CalendarEvent $event, User $member): ?EventBooking
    {
        return $event->bookings()->where('user_id', $member->id)->where('status', EventBooking::STATUS_CONFIRMED)->first();
    }

    public function book(CalendarEvent $event, User $member): EventBooking
    {
        try {
            $booking = DB::transaction(function () use ($event, $member): EventBooking {
                /** @var CalendarEvent $locked */
                $locked = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if ($this->activeBookingOf($locked, $member) !== null) {
                    throw new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。');
                }

                $reason = $this->unavailableReason($locked);
                if ($reason !== null) {
                    throw self::unavailable($reason);
                }

                return EventBooking::query()->create([
                    'calendar_event_id' => $locked->id,
                    'user_id' => $member->id,
                    'status' => EventBooking::STATUS_CONFIRMED,
                    'active' => 1,
                    'booked_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Unique (event, member, active) violation from a parallel duplicate request.
            if ($this->activeBookingOf($event, $member) !== null) {
                throw new ApiException(409, 'ALREADY_BOOKED', '您已預約此活動。');
            }

            throw $e;
        }

        AuditEvent::record('booking.confirmed', $member->id, $booking, ['event_id' => $event->id]);

        return $booking;
    }

    /**
     * Cancels a confirmed booking (kept as history). $actor is the member or an admin.
     */
    public function cancel(EventBooking $booking, User $actor): EventBooking
    {
        $cancelled = DB::transaction(function () use ($booking, $actor): EventBooking {
            /** @var EventBooking $locked */
            $locked = EventBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new ApiException(409, 'BOOKING_NOT_ACTIVE', '此預約已取消。');
            }

            $locked->forceFill([
                'status' => EventBooking::STATUS_CANCELLED,
                'active' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            return $locked;
        });

        AuditEvent::record('booking.cancelled', $actor->id, $cancelled, [
            'event_id' => $cancelled->calendar_event_id,
            'by' => $actor->id === $cancelled->user_id ? 'member' : 'admin',
        ]);

        return $cancelled;
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
}
