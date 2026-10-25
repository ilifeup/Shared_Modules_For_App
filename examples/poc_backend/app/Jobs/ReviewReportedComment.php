<?php

namespace App\Jobs;

use App\Models\ArticleComment;
use App\Models\AuditEvent;
use App\Models\CommentModerationCase;
use App\Models\User;
use App\Notifications\CommentReviewNeeded;
use App\Services\Moderation\CommentModerator;
use App\Services\Moderation\ModerationResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * S8 M05-B05: one AI review per moderation round (the round is marked "pending"
 * under a row lock before this job is queued; this job only runs a pending round).
 * keep → stays public; hide / unsure / AI unavailable → hidden until an admin acts.
 * Admins are emailed either way.
 */
class ReviewReportedComment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Never retried: a round calls the AI at most once. */
    public int $tries = 1;

    public function __construct(public readonly int $caseId) {}

    public function handle(CommentModerator $moderator): void
    {
        $case = CommentModerationCase::query()->with(['comment', 'reports'])->find($this->caseId);

        if ($case === null || ! $case->isOpen() || $case->ai_status !== 'pending') {
            return;
        }

        $reasons = $case->reports->pluck('reason')->unique()->values()->all();

        try {
            $result = $moderator->review((string) $case->comment->body, $reasons);
            $aiStatus = 'done';
        } catch (Throwable $e) {
            report($e);
            $result = new ModerationResult(ModerationResult::UNSURE, 'AI 審核無法使用，已先暫時隱藏。');
            $aiStatus = 'failed';
        }

        DB::transaction(function () use ($case, $result, $aiStatus): void {
            $case->forceFill([
                'ai_status' => $aiStatus,
                'ai_decision' => $aiStatus === 'done' ? $result->decision : null,
                'ai_reason' => $result->reason,
                'ai_reviewed_at' => now(),
            ])->save();

            $comment = ArticleComment::query()->whereKey($case->article_comment_id)->lockForUpdate()->first();
            if ($comment !== null && $comment->status === ArticleComment::STATUS_VISIBLE && ($aiStatus !== 'done' || $result->decision !== ModerationResult::KEEP)) {
                $comment->forceFill([
                    'status' => ArticleComment::STATUS_HIDDEN,
                    'hidden_reason' => match (true) {
                        $aiStatus !== 'done' => 'ai_unavailable',
                        $result->decision === ModerationResult::HIDE => 'ai_hide',
                        default => 'ai_unsure',
                    },
                ])->save();
            }
        });

        AuditEvent::record('comment.ai_reviewed', null, $case, [
            'comment_id' => $case->article_comment_id,
            'ai_status' => $aiStatus,
            'decision' => $aiStatus === 'done' ? $result->decision : null,
        ], $aiStatus === 'done' ? 'success' : 'failure');

        $admins = User::role(User::ADMIN_ROLE)->where('is_active', true)->whereNotNull('email')->get()
            ->filter(fn (User $u): bool => $u->can('comments.moderate'));
        Notification::send($admins, new CommentReviewNeeded($case->refresh()));
    }
}
