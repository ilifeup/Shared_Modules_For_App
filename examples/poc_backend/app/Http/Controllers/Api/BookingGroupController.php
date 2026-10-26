<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingGroupPresenter;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Family\FamilyDelegation;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S9 M08 members-only group bookings and waitlist.
 * The S6 single-booking API stays unchanged (BookingController).
 */
class BookingGroupController extends Controller
{
    public function __construct(
        private readonly BookingEngine $engine,
    ) {}

    /**
     * POST /api/v1/events/{id}/booking-groups
     * Body: {include_self, attendees:[{member_user_id}], client_request_id}
     * 201 confirmed or waitlisted (whole group), 200 for an idempotent retry.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'include_self' => ['sometimes', 'boolean'],
            'attendees' => ['sometimes', 'array', 'max:50'],
            'attendees.*.member_user_id' => ['required', 'integer', 'exists:users,id'],
            'client_request_id' => ['required', 'uuid'],
        ]);

        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $member = $request->user();
        $attendees = [];
        if ($request->boolean('include_self', true)) {
            $attendees[] = ['name' => $member->name, 'member_user_id' => $member->id];
        }
        foreach ($data['attendees'] ?? [] as $a) {
            $attendeeId = (int) $a['member_user_id'];
            $attendee = User::query()->whereKey($attendeeId)->where('is_active', true)->firstOrFail();
            if (! app(FamilyDelegation::class)->canManage($member, $attendee)) {
                throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '尚未取得此參加者的代訂授權。');
            }
            $attendees[] = ['name' => $attendee->name, 'member_user_id' => $attendee->id];
        }
        $ids = array_column($attendees, 'member_user_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new ApiException(422, 'DUPLICATE_ATTENDEE', '同一位會員不可重複加入預約。');
        }

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
        // G01: revoking delegation preserves existing bookings but removes the
        // former manager's authority to cancel a group containing that member.
        $actor = $request->user();
        $delegation = app(FamilyDelegation::class);
        foreach ($group->attendees as $attendee) {
            $member = $attendee->member_user_id !== null
                ? User::query()->find($attendee->member_user_id)
                : null;
            if ($member === null || ! $delegation->canManage($actor, $member)) {
                throw new ApiException(403, 'ATTENDEE_NOT_AUTHORIZED', '此預約包含目前無權代管的家庭會員，請洽管理員處理。');
            }
        }
        $cancelled = $this->engine->cancelGroup($group, $actor);

        return new JsonResponse(['data' => BookingGroupPresenter::present($cancelled, $request->user())]);
    }


}
