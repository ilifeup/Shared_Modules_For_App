<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
