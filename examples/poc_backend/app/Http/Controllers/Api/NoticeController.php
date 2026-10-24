<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NoticeResource;
use App\Models\Notice;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoticeController extends Controller
{
    /**
     * GET /api/v1/notices — published notices only, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        return NoticeResource::collection(
            Notice::query()->visibleToMembers()->orderByDesc('published_at')->orderByDesc('id')->paginate(20)
        );
    }

    /**
     * GET /api/v1/notices/{id} — drafts and future notices are reported as not found.
     */
    public function show(int $id): NoticeResource
    {
        return new NoticeResource(Notice::query()->visibleToMembers()->findOrFail($id));
    }
}
