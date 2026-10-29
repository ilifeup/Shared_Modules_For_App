<?php

namespace App\Services\Course;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CourseMakeup;
use App\Models\CourseSession;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class CourseMakeupAssignmentService
{
    /**
     * Administrator-only assignment. The target term and session are locked
     * before the seat count, so competing administrators cannot overbook.
     */
    public function assign(int $leaveId, int $targetSessionId, User $admin): CourseMakeup
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可指派補課。']);
        }

        return DB::transaction(function () use ($leaveId, $targetSessionId, $admin): CourseMakeup {
            $target = CourseSession::query()->findOrFail($targetSessionId);
            DB::table('course_terms')->where('id', $target->course_term_id)->lockForUpdate()->firstOrFail();
            $target = CourseSession::query()->whereKey($targetSessionId)->lockForUpdate()->firstOrFail();
            $leave = DB::table('course_session_leaves')->where('id', $leaveId)->lockForUpdate()->firstOrFail();
            $enrollment = DB::table('course_enrollments')->where('id', $leave->course_enrollment_id)->lockForUpdate()->firstOrFail();

            $existing = CourseMakeup::query()->where('course_session_leave_id', $leaveId)->first();
            if ($existing !== null) {
                if ((int) $existing->target_course_session_id === $targetSessionId
                    && $existing->booking?->status === EventBookingGroup::STATUS_CONFIRMED) {
                    return $existing;
                }
                throw ValidationException::withMessages(['course_session_leave_id' => '請假已指派補課，不可重複指派。']);
            }

            $original = CourseSession::query()->findOrFail($leave->course_session_id);
            if ((int) $original->course_term_id !== (int) $enrollment->course_term_id
                || (int) $original->id === $targetSessionId
                || $enrollment->status !== 'confirmed') {
                throw ValidationException::withMessages(['course_session_leave_id' => '請假與已繳費的有效整期課程不符，或不可補回原場次。']);
            }

            $member = User::query()->whereKey($enrollment->user_id)->firstOrFail();
            if (! $member->is_active || (int) $member->verified_status !== 2) {
                throw ValidationException::withMessages(['member' => '補課會員須維持有效認證。']);
            }

            $event = CalendarEvent::query()->whereKey($target->calendar_event_id)->lockForUpdate()->firstOrFail();
            if ($event->status !== CalendarEvent::STATUS_PUBLISHED || ! $event->booking_enabled
                || $event->starts_at->lte(now())) {
                throw ValidationException::withMessages(['target_course_session_id' => '補課場次必須已發布、開放報名且尚未開始。']);
            }

            $assigned = DB::table('course_makeups as m')
                ->join('event_booking_groups as g', 'g.id', '=', 'm.event_booking_group_id')
                ->where('m.target_course_session_id', $target->id)
                ->where('g.status', EventBookingGroup::STATUS_CONFIRMED)->count();
            if ($assigned >= (int) $target->makeup_capacity) {
                throw ValidationException::withMessages(['target_course_session_id' => '補課名額已滿。']);
            }

            if (EventBookingAttendee::query()->where('calendar_event_id', $event->id)
                ->where('member_user_id', $member->id)->where('status', EventBookingAttendee::STATUS_CONFIRMED)
                ->where('active', true)->exists()) {
                throw ValidationException::withMessages(['member' => '會員在目標場次已有有效預約。']);
            }

            // Makeup capacity is a separate administrative quota, but the
            // occurrence's physical booking capacity is still a hard ceiling.
            $occupied = EventBookingAttendee::query()
                ->where('calendar_event_id', $event->id)
                ->where('status', EventBookingAttendee::STATUS_CONFIRMED)
                ->where('active', true)->count();
            if ($occupied >= (int) $event->booking_capacity) {
                throw ValidationException::withMessages(['target_course_session_id' => '目標活動總座位已滿，無法安排補課。']);
            }

            $requestId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'course-makeup:leave:'.$leaveId);
            $group = EventBookingGroup::query()->create([
                'calendar_event_id' => $event->id,
                'booker_user_id' => $enrollment->booker_user_id,
                'contact_name' => $member->name,
                'contact_email' => $member->email,
                'status' => EventBookingGroup::STATUS_CONFIRMED,
                'seat_count' => 1,
                'client_request_id' => $requestId,
                'booked_at' => now(),
                'confirmed_at' => now(),
            ]);
            EventBookingAttendee::query()->create([
                'event_booking_group_id' => $group->id,
                'calendar_event_id' => $event->id,
                'member_user_id' => $member->id,
                'name' => $member->name,
                'status' => EventBookingAttendee::STATUS_CONFIRMED,
                'active' => true,
            ]);
            $makeup = CourseMakeup::query()->create([
                'course_session_leave_id' => $leaveId,
                'target_course_session_id' => $target->id,
                'event_booking_group_id' => $group->id,
            ]);
            AuditEvent::record('course.makeup_assigned', $admin->id, $makeup, [
                'leave_id' => $leaveId,
                'target_session_id' => $target->id,
                'booking_group_id' => $group->id,
            ]);

            return $makeup;
        }, 3);
    }
}
