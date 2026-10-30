<?php

namespace App\Services\Booking;

use App\Http\Presenters\CanonicalBookingPresenter;
use App\Jobs\DispatchCanonicalBookingNotification;
use App\Jobs\DispatchBookingGoogleSync;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** API bridge for the legacy booking-groups URL after the canonical cutover. */
final class CanonicalGroupApiAdapter
{
    public function __construct(
        private readonly CanonicalBookingWriter $writer,
        private readonly CanonicalBookingCancellation $cancellation,
    ) {}

    public function reserve(CalendarEvent $event, User $booker, User $member, string $requestId, ?bool &$created = null): array
    {
        // The writer enforces authorization, FIFO and idempotency under event lock.
        $booking = $this->writer->reserve($event, $booker, $member, $requestId, true, false, $created);
        if ($created === true && config('booking.canonical_side_effects_ready', false)
            && ! config('booking.canonical_outbox_delivery_ready', false)) {
            // Never enqueue the legacy group-bound notification job.
            DispatchCanonicalBookingNotification::dispatch((int) $booking->id, $booking->status)->afterCommit();
            if ($booking->status === EventBooking::STATUS_CONFIRMED) {
                DispatchBookingGoogleSync::dispatch((int) $booker->id, (int) $event->id)->afterCommit();
            }
        }
        return CanonicalBookingPresenter::present($booking, $booker);
    }

    public function cancel(EventBooking $booking, User $actor): array
    {
        if ((int) $booking->booker_user_id !== (int) $actor->id
            && (int) $booking->member_user_id !== (int) $actor->id) {
            abort(404);
        }
        $promotedBookingIds = [];
        $cancelled = $this->cancellation->cancel($booking, $actor, false, null, $promotedBookingIds);
        if (config('booking.canonical_side_effects_ready', false)
            && ! config('booking.canonical_outbox_delivery_ready', false)) {
            DispatchCanonicalBookingNotification::dispatch((int) $cancelled->id, 'cancelled')->afterCommit();
            DispatchBookingGoogleSync::dispatch((int) $cancelled->booker_user_id,
                (int) $cancelled->calendar_event_id)->afterCommit();
            foreach ($promotedBookingIds as $promotedId) {
                $promoted = EventBooking::query()->findOrFail($promotedId);
                DispatchCanonicalBookingNotification::dispatch($promotedId, 'confirmed')->afterCommit();
                DispatchBookingGoogleSync::dispatch((int) $promoted->booker_user_id,
                    (int) $promoted->calendar_event_id)->afterCommit();
            }
        }
        return CanonicalBookingPresenter::present($cancelled, $actor);
    }

    public function mine(User $booker, ?string $status = null): array
    {
        // Members can always view their own reservations, even when booked by family.
        // The original booker can view a delegated reservation for two months only.
        $query = EventBooking::query()
            ->where(function ($visibility) use ($booker) {
                $visibility->where('member_user_id', $booker->id)
                    ->orWhere(function ($delegated) use ($booker) {
                        $delegated->where('booker_user_id', $booker->id)
                            ->whereNotNull('member_user_id')
                            ->where('member_user_id', '!=', $booker->id)
                            ->where('booked_at', '>=', now()->subMonths(2));
                    });
            })
            ->when($status === 'active', fn ($q) => $q->where('active', 1)->whereIn('status', ['confirmed', 'waitlisted']))
            ->when($status === 'cancelled', fn ($q) => $q->whereIn('status', ['cancelled', 'event_cancelled']))
            ->when(in_array($status, ['confirmed', 'waitlisted', 'event_cancelled'], true), fn ($q) => $q->where('status', $status))
            ->with('event', 'member', 'canonicalAttendance')
            ->orderByDesc('booked_at')->orderByDesc('id');
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate(20);
        return [
            'data' => collect($page->items())->map(fn (EventBooking $b) => CanonicalBookingPresenter::present($b, $booker))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }
}
