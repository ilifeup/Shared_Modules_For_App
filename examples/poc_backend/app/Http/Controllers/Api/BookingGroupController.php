<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingGroupPresenter;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Family\FamilyDelegation;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MVP single-member booking and waitlist backed by the existing seat ledger.
 * Exactly one attendee: self or an authorized verified family member.
 */
class BookingGroupController extends Controller
{
    public function __construct(
        private readonly BookingEngine $engine,
    ) {}

    /**
     * POST /api/v1/events/{id}/booking-groups
     * Body: {client_request_id, member_user_id?}. One attendee, self by default.
     * 201 confirmed or waitlisted, 200 for an idempotent retry.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'include_self' => ['prohibited'],
            'attendees' => ['prohibited'],
            'member_user_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $member = $request->user();
        $attendeeId = (int) ($data['member_user_id'] ?? $member->id);
        $attendee = User::query()->whereKey($attendeeId)->where('is_active', true)->where('verified_status', 2)->first();
        if ($attendee === null || ! app(FamilyDelegation::class)->canManage($member, $attendee)) {
            throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '參加者不是有效的已驗證家庭會員，或沒有代管授權。');
        }
        $attendees = [['name' => $attendee->name, 'member_user_id' => $attendee->id]];

        // The engine determines whether this was created or replayed under its event lock.
        $created = false;
        $group = $this->engine->book($event, new BookingRequest(
            booker: $member,
            contactName: $member->name,
            contactEmail: $member->email !== null ? mb_strtolower(trim($member->email)) : null,
            contactPhone: $member->phone_e164,
            attendees: $attendees,
            clientRequestId: $data['client_request_id'],
            actor: $member,
        ), $created);

        return new JsonResponse(['data' => BookingGroupPresenter::present($group, $member)], $created ? 201 : 200);
    }

    /**
     * GET /api/v1/me/booking-groups — groups I booked (confirmed, waitlisted, cancelled), 20 per page.
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:active,confirmed,waitlisted,cancelled'],
        ]);
        $status = $request->input('status');
        $page = EventBookingGroup::query()->where('booker_user_id', $request->user()->id)
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED]))
            ->when($status === 'cancelled', fn ($q) => $q->whereIn('status', [EventBookingGroup::STATUS_CANCELLED, EventBookingGroup::STATUS_EVENT_CANCELLED]))
            ->when(in_array($status, ['confirmed', 'waitlisted'], true), fn ($q) => $q->where('status', $status))
            ->whereHas('attendees', fn ($q) => $q->where('member_user_id', $request->user()->id)
                ->orWhereIn('member_user_id', function ($subquery) use ($request) {
                    $subquery->select('member_user_id')->from('family_memberships')
                        ->where('manager_user_id', $request->user()->id)
                        ->where('status', 'active')->whereNull('ended_at');
                }))
            ->with('event', 'attendees.attendance', 'waitlistEntry')
            ->orderByDesc('booked_at')->orderByDesc('id')->paginate(20);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (EventBookingGroup $g) => BookingGroupPresenter::present($g, $request->user()))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * POST /api/v1/me/booking-groups/{id}/cancel — own groups only (others: 404).
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $group = EventBookingGroup::query()->where('booker_user_id', $request->user()->id)->findOrFail($id);
        // BookingEngine rechecks delegation under the cancellation transaction lock.
        $actor = $request->user();
        $cancelled = $this->engine->cancelGroup($group, $actor);

        return new JsonResponse(['data' => BookingGroupPresenter::present($cancelled, $request->user())]);
    }
}
