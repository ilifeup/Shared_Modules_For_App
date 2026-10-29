<?php

namespace App\Services\Course;

use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Internal admission primitive. NOT a public enrollment endpoint: the caller must
 * create all event bookings and the consolidated invoice in the SAME transaction
 * before exposing a confirmed admission.
 */
final class CourseGroupAdmission
{
    public function reserve(int $groupId, User $booker, User $member, string $requestKey, callable $createEventBookingsAndInvoice): array
    {
        if (! Uuid::isValid($requestKey)) {
            throw new ApiException(422, 'INVALID_REQUEST_ID', '請提供有效的請求識別碼。');
        }

        return DB::transaction(function () use ($groupId, $booker, $member, $requestKey, $createEventBookingsAndInvoice): array {
            // Every admission for this group locks the same parent row FIRST.
            // This serializes capacity, FIFO allocation and duplicate requests.
            $group = DB::table('course_groups')->where('id', $groupId)->lockForUpdate()->first();
            if ($group === null) {
                throw new ApiException(404, 'COURSE_GROUP_NOT_FOUND', '課程集合不存在。');
            }

            // Check current DB authority even for idempotent replays. A revoked
            // guardian must not recover protected enrollment details by key.
            $currentBooker = User::query()->find($booker->id);
            $currentMember = User::query()->find($member->id);
            if ($currentBooker === null || $currentMember === null
                || ! $currentBooker->is_active || (int) $currentBooker->verified_status !== 2
                || ! $currentMember->is_active || (int) $currentMember->verified_status !== 2
                || ! app(BookingEngine::class)->canManageFamily($currentBooker, $currentMember, true)) {
                throw new ApiException(403, 'MEMBER_NOT_ELIGIBLE', '會員資格或家庭代訂授權無效。');
            }

            $existingKey = DB::table('course_group_members')
                ->where('course_group_id', $groupId)->where('request_key', $requestKey)->first();
            if ($existingKey !== null) {
                if ((int) $existingKey->member_user_id !== (int) $member->id
                    || (int) $existingKey->booker_user_id !== (int) $booker->id) {
                    throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', '此請求識別碼已用於其他會員。');
                }

                return ['id' => (int) $existingKey->id, 'status' => $existingKey->status, 'replayed' => true];
            }

            if (DB::table('course_group_members')->where('course_group_id', $groupId)
                ->where('member_user_id', $member->id)->exists()) {
                throw new ApiException(409, 'ALREADY_BOOKED', '此會員已有該集合報名紀錄。');
            }

            // The caller must use the same group lock to freeze event membership
            // while admissions are active. Preflight is deliberately rechecked.
            $readiness = app(CourseGroupReadiness::class)->inspect($groupId);
            if (! $readiness['ready']) {
                throw new ApiException(409, 'COURSE_GROUP_NOT_READY', '課程集合尚未完成排課或不開放報名。');
            }

            $occupied = DB::table('course_group_members')
                ->where('course_group_id', $groupId)
                ->whereIn('status', ['pending_payment', 'confirmed'])->count();
            $waiting = DB::table('course_group_members')
                ->where('course_group_id', $groupId)->where('status', 'waitlisted')->count();

            // Never let a newcomer jump an existing waitlist.
            $confirmed = $occupied < (int) $group->capacity && $waiting === 0;
            if (! $confirmed && $waiting >= (int) $group->waitlist_capacity) {
                throw new ApiException(409, 'WAITLIST_FULL', '集合候補名額已滿。');
            }

            $sequence = $confirmed ? null : (int) $group->next_waitlist_sequence;
            $status = $confirmed ? 'pending_payment' : 'waitlisted';
            $id = DB::table('course_group_members')->insertGetId([
                'course_group_id' => $groupId,
                'member_user_id' => $member->id,
                'booker_user_id' => $booker->id,
                'status' => $status,
                'waitlist_sequence' => $sequence,
                'request_key' => $requestKey,
                'payment_due_at' => $confirmed ? now()->addHours((int) $group->payment_hold_hours) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($sequence !== null) {
                DB::table('course_groups')->where('id', $groupId)->update([
                    'next_waitlist_sequence' => $sequence + 1, 'updated_at' => now(),
                ]);
            }

            // Any failure in the booking/invoice callback rolls back the seat too.
            if ($confirmed) {
                $createEventBookingsAndInvoice((int) $id, $groupId, $member, $booker);
            }

            return ['id' => (int) $id, 'status' => $status, 'replayed' => false];
        }, 3);
    }
}
