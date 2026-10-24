<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\MemberResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * GET /api/v1/me and GET /api/v1/me/profile (shared contract path)
     */
    public function show(Request $request): MemberResource
    {
        return new MemberResource($request->user());
    }

    /**
     * PATCH /api/v1/me/profile — members can only change their own display_name.
     */
    public function update(UpdateProfileRequest $request): MemberResource
    {
        $user = $request->user();
        $user->update(['display_name' => $request->string('display_name')->toString()]);

        return new MemberResource($user->refresh());
    }
}
