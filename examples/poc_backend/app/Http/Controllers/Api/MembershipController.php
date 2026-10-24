<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipLevel;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * GET /api/v1/me/membership — current level, benefits and validity period.
     */
    public function show(Request $request): JsonResponse
    {
        ['level' => $level, 'assignment' => $assignment] = $this->memberships->current($request->user());

        return new JsonResponse(['data' => [
            'level' => $this->level($level),
            'starts_at' => $assignment?->starts_at->toIso8601ZuluString(),
            'ends_at' => $assignment?->ends_at?->toIso8601ZuluString(),
        ]]);
    }

    /**
     * GET /api/v1/membership-levels — public list of levels and their benefits.
     */
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => MembershipLevel::query()->orderBy('rank')->get()->map(fn (MembershipLevel $level) => $this->level($level))->all(),
        ]);
    }

    /**
     * @return array{code: string, name: string, benefits: string|null, is_default: bool}
     */
    private function level(MembershipLevel $level): array
    {
        return [
            'code' => $level->code,
            'name' => $level->name,
            'benefits' => $level->benefits,
            'is_default' => $level->is_default,
        ];
    }
}
