<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingGroupPresenter;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\CanonicalGroupApiAdapter;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Staged canonical group endpoint. Cutover remains disabled until all dependencies pass. */
class BookingGroupController extends Controller
{
    public function __construct(
        private readonly BookingEngine $engine,
        private readonly CanonicalGroupApiAdapter $canonical,
    ) {}

    private function canonicalEnabled(): bool
    {
        return config('booking.canonical_group_cutover_ready', false) === true;
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $unknown = array_diff(array_keys($request->all()), ['client_request_id', 'member_user_id']);
        if ($unknown !== []) {
            throw ValidationException::withMessages([(string) reset($unknown) => ['此欄位不支援單人報名。']]);
        }
        $data = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'member_user_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $booker = $request->user();
        $memberId = (int) ($data['member_user_id'] ?? $booker->id);
        $member = User::query()->whereKey($memberId)->where('is_active', true)->where('verified_status', 2)->first();
        if ($member === null || ! $this->engine->canManageFamily($booker, $member)) {
            throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '參加者不是有效的已驗證家庭會員，或沒有代管授權。');
        }
        if ($this->canonicalEnabled()) {
            // Canonical writer rechecks authorization and serializes the event transaction.
            $created = false;
            $data = $this->canonical->reserve($event, $booker, $member, $data['client_request_id'], $created);
            return new JsonResponse(['data' => $data], $created ? 201 : 200);
        }
        $created = false;
        $group = $this->engine->book($event, new BookingRequest(
            booker: $booker,
            contactName: $booker->name,
            contactEmail: $booker->email !== null ? mb_strtolower(trim($booker->email)) : null,
            contactPhone: $booker->phone_e164,
            attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
            clientRequestId: $data['client_request_id'],
            actor: $booker,
        ), $created);
        return new JsonResponse(['data' => BookingGroupPresenter::present($group, $booker)], $created ? 201 : 200);
    }

    public function mine(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:active,confirmed,waitlisted,cancelled,event_cancelled'],
        ]);
        $status = $request->input('status');
        if ($this->canonicalEnabled()) {
            return new JsonResponse($this->canonical->mine($request->user(), $status));
        }
        $actorId = $request->user()->id;
        $page = EventBookingGroup::query()->where('booker_user_id', $actorId)
            ->whereHas('attendees', function ($query) use ($actorId) {
                $query->where('member_user_id', $actorId)
                    ->orWhere(function ($history) {
                        $history->whereNotNull('member_user_id')
                            ->where('event_booking_groups.booked_at', '>=', now()->subMonths(2));
                    });
            })
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED]))
            ->when($status === 'cancelled', fn ($q) => $q->whereIn('status', [EventBookingGroup::STATUS_CANCELLED, EventBookingGroup::STATUS_EVENT_CANCELLED]))
            ->when(in_array($status, ['confirmed', 'waitlisted', 'event_cancelled'], true), fn ($q) => $q->where('status', $status))
            ->with('event', 'attendees.attendance', 'waitlistEntry')
            ->orderByDesc('booked_at')->orderByDesc('id')->paginate(20);
        return new JsonResponse([
            'data' => collect($page->items())->map(fn (EventBookingGroup $g) => BookingGroupPresenter::present($g, $request->user()))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($this->canonicalEnabled()) {
            $booking = EventBooking::query()->where(function ($query) use ($request) {
                $query->where('booker_user_id', $request->user()->id)
                    ->orWhere('member_user_id', $request->user()->id);
            })->findOrFail($id);
            return new JsonResponse(['data' => $this->canonical->cancel($booking, $request->user())]);
        }
        $group = EventBookingGroup::query()->where('booker_user_id', $request->user()->id)->findOrFail($id);
        $cancelled = $this->engine->cancelGroup($group, $request->user());
        return new JsonResponse(['data' => BookingGroupPresenter::present($cancelled, $request->user())]);
    }
}
