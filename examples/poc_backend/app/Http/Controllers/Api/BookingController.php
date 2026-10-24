<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S6 M08 (Sanctum + verification policy). Bookings are only possible for events the
 * member can see; a member only ever sees and cancels their own bookings.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * GET /api/v1/events/{id}/booking — capacity, remaining seats and my booking.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $event = CalendarEvent::query()->listed()->findOrFail($id);

        return new JsonResponse(['data' => $this->info($event, $request)]);
    }

    /**
     * POST /api/v1/events/{id}/bookings
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $this->bookings->book($event, $request->user());

        return new JsonResponse(['data' => $this->info($event->refresh(), $request)], 201);
    }

    /**
     * POST /api/v1/me/bookings/{id}/cancel — own bookings only (others: 404).
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = EventBooking::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->bookings->cancel($booking, $request->user());

        return new JsonResponse(['data' => $this->info($booking->event, $request)]);
    }

    /**
     * GET /api/v1/me/bookings — own bookings (confirmed and cancelled), newest first.
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = EventBooking::query()
            ->where('user_id', $request->user()->id)
            ->with('event')
            ->orderByDesc('booked_at')->orderByDesc('id')
            ->paginate(20);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (EventBooking $b) => $this->booking($b) + [
                'event' => [
                    'id' => (string) $b->event->id,
                    'title' => $b->event->title,
                    'starts_at' => $b->event->starts_at->toIso8601ZuluString(),
                    'ends_at' => $b->event->ends_at->toIso8601ZuluString(),
                    'status' => $b->event->status,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function info(CalendarEvent $event, Request $request): array
    {
        $confirmed = $this->bookings->confirmedCount($event);
        $mine = $this->bookings->activeBookingOf($event, $request->user());
        $reason = $this->bookings->unavailableReason($event, $confirmed);
        $capacity = $event->booking_enabled ? (int) $event->booking_capacity : null;

        return [
            'event_id' => (string) $event->id,
            'enabled' => (bool) $event->booking_enabled,
            'capacity' => $capacity,
            'booked_count' => $confirmed,
            'remaining' => $capacity === null ? null : max(0, $capacity - $confirmed),
            'opens_at' => $event->booking_opens_at?->toIso8601ZuluString(),
            'closes_at' => $event->booking_enabled ? $event->bookingClosesAt()->toIso8601ZuluString() : null,
            // Null when a new booking is possible now (and I have none).
            'unavailable_reason' => $mine !== null ? 'already_booked' : $reason,
            'my_booking' => $mine === null ? null : $this->booking($mine),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(EventBooking $b): array
    {
        return [
            'id' => (string) $b->id,
            'event_id' => (string) $b->calendar_event_id,
            'status' => $b->status,
            'booked_at' => $b->booked_at->toIso8601ZuluString(),
            'cancelled_at' => $b->cancelled_at?->toIso8601ZuluString(),
        ];
    }
}
