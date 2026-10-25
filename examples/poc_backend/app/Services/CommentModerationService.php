<?php

namespace App\Services;

use App\Models\ArticleComment;
use App\Models\AuditEvent;
use App\Models\CommentModerationCase;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * S8 M05-B05 admin review. Every action closes the round; reports that arrive later
 * open a new round (old, closed reports never trigger again).
 */
final class CommentModerationService
{
    public const ACTIONS = ['restore', 'keep_hidden', 'delete', 'close'];

    public function resolve(CommentModerationCase $case, string $action, User $admin): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', '不支援的處理方式。');
        }

        DB::transaction(function () use ($case, $action, $admin): void {
            $locked = CommentModerationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ApiException(409, 'CASE_CLOSED', '此案件已結案。');
            }

            $comment = ArticleComment::query()->whereKey($locked->article_comment_id)->lockForUpdate()->firstOrFail();
            match ($action) {
                'restore' => $comment->forceFill(['status' => ArticleComment::STATUS_VISIBLE, 'hidden_reason' => null])->save(),
                'keep_hidden' => $comment->forceFill(['status' => ArticleComment::STATUS_HIDDEN, 'hidden_reason' => 'admin'])->save(),
                'delete' => $comment->forceFill(['status' => ArticleComment::STATUS_DELETED])->save(),
                'close' => null,
            };

            $locked->forceFill([
                'active' => null,
                'resolution' => match ($action) {
                    'restore' => 'restored',
                    'keep_hidden' => 'kept_hidden',
                    'delete' => 'deleted',
                    default => 'closed',
                },
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();
        });

        AuditEvent::record('comment.case_resolved', $admin->id, $case, [
            'comment_id' => $case->article_comment_id,
            'action' => $action,
        ]);
    }

    /**
     * Direct admin action on a comment (outside a report round).
     */
    public function setStatus(ArticleComment $comment, string $status, User $admin): void
    {
        $comment->forceFill([
            'status' => $status,
            'hidden_reason' => $status === ArticleComment::STATUS_HIDDEN ? 'admin' : null,
        ])->save();

        AuditEvent::record('comment.'.$status.'_by_admin', $admin->id, $comment);
    }
}
