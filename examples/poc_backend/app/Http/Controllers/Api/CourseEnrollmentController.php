<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CourseEnrollmentController extends Controller
{
    public function __construct(private readonly CourseEnrollmentService $enrollments) {}

    /** POST /api/v1/course-terms/{id}/enrollments (verified member or guardian). */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'member_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $booker = $request->user();
        $member = isset($data['member_user_id'])
            ? User::query()->findOrFail($data['member_user_id'])
            : $booker;

        $result = $this->enrollments->enroll($id, $booker, $member);

        return new JsonResponse(['data' => $result], 201);
    }

    /** POST /api/v1/admin/course-enrollments/{id}/confirm-fee. */
    public function confirmFee(Request $request, int $id): JsonResponse
    {
        $result = $this->enrollments->confirmFee($id, $request->user());

        return new JsonResponse(['data' => $result]);
    }

    /** GET /api/v1/me/course-enrollments: own and currently delegated family enrollments. */
    public function mine(Request $request): JsonResponse
    {
        $actor = $request->user();
        $memberIds = DB::table('family_memberships')
            ->where('manager_user_id', $actor->id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->pluck('member_user_id')
            ->push($actor->id)
            ->unique()
            ->values()
            ->all();

        $entries = DB::table('course_enrollments as e')
            ->join('course_terms as t', 't.id', '=', 'e.course_term_id')
            ->whereIn('e.user_id', $memberIds)
            ->orderByDesc('e.id')
            ->select('e.id', 'e.course_term_id', 'e.user_id', 'e.status',
                'e.waitlist_sequence', 'e.fee_confirmed_at', 't.title as course_title')
            ->paginate(20);

        return new JsonResponse(['data' => $entries->items(), 'meta' => [
            'current_page' => $entries->currentPage(),
            'last_page' => $entries->lastPage(),
            'total' => $entries->total(),
        ]]);
    }
}
