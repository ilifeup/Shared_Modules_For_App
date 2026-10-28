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

    /** GET /api/v1/course-terms: upcoming terms with configured sessions. */
    public function index(): JsonResponse
    {
        $terms = DB::table('course_terms as t')
            ->join('course_sessions as s', 's.course_term_id', '=', 't.id')
            ->join('calendar_events as e', 'e.id', '=', 's.calendar_event_id')
            ->where('e.status', 'published')
            ->where('e.starts_at', '>=', now())
            ->where('e.booking_enabled', true)
            ->where('e.booking_capacity', '>', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('course_sessions as other_session')
                    ->join('calendar_events as other_event', 'other_event.id', '=', 'other_session.calendar_event_id')
                    ->whereColumn('other_session.course_term_id', 't.id')
                    ->where(function ($invalid) {
                        $invalid->where('other_event.status', '!=', 'published')
                            ->orWhere('other_event.booking_enabled', false)
                            ->orWhere('other_event.booking_capacity', '<', 1);
                    });
            })
            ->groupBy('t.id', 't.title', 't.location', 't.enrollment_capacity')
            ->orderBy('t.id')
            ->limit(50)
            ->get(['t.id', 't.title', 't.location', 't.enrollment_capacity']);

        return new JsonResponse(['data' => $terms]);
    }

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
        $result = $this->enrollments->paidCheck($id, $request->user());

        return new JsonResponse(['data' => $result]);
    }

    /** GET /api/v1/me/course-enrollments: own and original-booker family history. */
    public function mine(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Keep the original booker's recent family enrollment history after
        // delegation or qualification changes. Own enrollments have no cutoff.
        // Current delegation is required for mutations, not historical reads.
        $entries = DB::table('course_enrollments as e')
            ->join('course_terms as t', 't.id', '=', 'e.course_term_id')
            ->where(function ($query) use ($actor) {
                $query->where('e.user_id', $actor->id)
                    ->orWhere(function ($history) use ($actor) {
                        $history->where('e.booker_user_id', $actor->id)
                            ->where('e.created_at', '>=', now()->subMonths(2));
                    });
            })
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
