<?php

namespace App\Services\Course;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseEnrollmentService
{
    /**
     * One term seat per verified member. Lock the parent term before counting
     * enrollments so concurrent requests cannot exceed either capacity.
     * Family delegation must be authorized by the calling API layer.
     */
    public function enroll(int $termId, User $member): array
    {
        if (! $member->is_active || ! $member->isVerifiedMember()) {
            throw ValidationException::withMessages(['member' => '僅限有效且已驗證會員報名。']);
        }

        return DB::transaction(function () use ($termId, $member): array {
            $term = DB::table('course_terms')->where('id', $termId)->lockForUpdate()->first();
            if ($term === null) {
                throw ValidationException::withMessages(['term' => '課程期別不存在。']);
            }

            $existing = DB::table('course_enrollments')
                ->where('course_term_id', $termId)->where('user_id', $member->id)->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['member' => '此會員已報名或曾報名本期，請由管理員處理。']);
            }

            $occupied = DB::table('course_enrollments')->where('course_term_id', $termId)
                ->whereIn('status', ['pending_payment', 'confirmed'])->count();
            $waiting = DB::table('course_enrollments')->where('course_term_id', $termId)
                ->where('status', 'waitlisted')->count();

            if ($occupied < $term->enrollment_capacity && $waiting === 0) {
                $status = 'pending_payment';
                $sequence = null;
            } elseif ($waiting < $term->waitlist_capacity) {
                $status = 'waitlisted';
                $sequence = (int) DB::table('course_enrollments')
                    ->where('course_term_id', $termId)->max('waitlist_sequence') + 1;
            } else {
                throw ValidationException::withMessages(['term' => '本期課程及候補名額皆已滿。']);
            }

            $id = DB::table('course_enrollments')->insertGetId([
                'course_term_id' => $termId,
                'user_id' => $member->id,
                'status' => $status,
                'waitlist_sequence' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'status' => $status, 'waitlist_sequence' => $sequence];
        }, 3);
    }
}
