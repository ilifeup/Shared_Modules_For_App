<?php

namespace App\Services;

use App\Jobs\ReviewReportedComment;
use App\Models\Article;
use App\Models\ArticleComment;
use App\Models\ArticleLike;
use App\Models\AuditEvent;
use App\Models\CommentModerationCase;
use App\Models\CommentReport;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * S8 M05-B: views, likes, comments and reports. Callers check read permission first.
 */
final class ArticleInteractionService
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Counts a view unless the same viewer (member id, or hashed IP + user agent)
     * was counted for this article within the de-duplication window.
     */
    public function recordView(Article $article, ?User $viewer, string $ip, string $userAgent): bool
    {
        $who = $viewer !== null ? 'u'.$viewer->id : 'a'.hash('sha256', $ip.'|'.$userAgent);
        $key = 'article-view:'.$article->id.':'.$who;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }

        RateLimiter::hit($key, $this->settings->int('article_view_dedupe_minutes') * 60);
        Article::query()->whereKey($article->id)->increment('view_count');

        return true;
    }

    public function like(Article $article, User $member): void
    {
        DB::transaction(function () use ($article, $member): void {
            // unique(article_id, user_id): a second like is ignored, not counted.
            $inserted = ArticleLike::query()->insertOrIgnore([
                'article_id' => $article->id,
                'user_id' => $member->id,
                'created_at' => now(),
            ]);
            if ($inserted > 0) {
                Article::query()->whereKey($article->id)->increment('like_count');
            }
        });
    }

    public function unlike(Article $article, User $member): void
    {
        DB::transaction(function () use ($article, $member): void {
            $deleted = ArticleLike::query()->where('article_id', $article->id)->where('user_id', $member->id)->delete();
            if ($deleted > 0) {
                Article::query()->whereKey($article->id)->where('like_count', '>', 0)->decrement('like_count');
            }
        });
    }

    public function comment(Article $article, User $member, string $body): ArticleComment
    {
        $body = trim($body);

        if (blank($member->nickname)) {
            throw new ApiException(422, 'NICKNAME_REQUIRED', '請先在個人資料設定公開暱稱，才能留言。');
        }

        if ($body === '' || mb_strlen($body) > 1000) {
            throw new ApiException(422, 'VALIDATION_FAILED', '留言需為 1–1000 字。', ['body' => ['留言需為 1–1000 字。']]);
        }

        foreach ((array) config('articles.comment_blacklist', []) as $pattern) {
            if (@preg_match((string) $pattern, $body) === 1) {
                AuditEvent::record('comment.blocked', $member->id, $article, [], 'failure');

                throw new ApiException(422, 'COMMENT_BLOCKED', '留言含有不允許的內容，請修改後再送出。', [
                    'body' => ['留言含有不允許的內容（例如連結或廣告），請修改後再送出。'],
                ]);
            }
        }

        $comment = ArticleComment::query()->create([
            'article_id' => $article->id,
            'user_id' => $member->id,
            'body' => $body,
            'status' => ArticleComment::STATUS_VISIBLE,
        ]);

        AuditEvent::record('comment.created', $member->id, $comment);

        return $comment;
    }

    /**
     * One effective report per member and round. When the round reaches the
     * threshold of distinct members, it is marked pending under a row lock and
     * exactly one AI review is queued (after commit).
     */
    public function report(ArticleComment $comment, User $member, string $reason, ?string $note): CommentModerationCase
    {
        if (! array_key_exists($reason, (array) config('articles.report_reasons', []))) {
            throw new ApiException(422, 'VALIDATION_FAILED', '請選擇檢舉理由。', ['reason' => ['請選擇檢舉理由。']]);
        }

        if ($comment->user_id === $member->id) {
            throw new ApiException(422, 'VALIDATION_FAILED', '不能檢舉自己的留言。');
        }

        $threshold = $this->settings->int('comment_report_threshold');

        $case = DB::transaction(function () use ($comment, $member, $reason, $note, $threshold): CommentModerationCase {
            ArticleComment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            $case = CommentModerationCase::query()
                ->where('article_comment_id', $comment->id)
                ->where('active', 1)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                $round = (int) CommentModerationCase::query()->where('article_comment_id', $comment->id)->max('round') + 1;
                $case = CommentModerationCase::query()->create([
                    'article_comment_id' => $comment->id,
                    'round' => $round,
                    'active' => 1,
                    'report_count' => 0,
                    'ai_status' => 'none',
                ]);
            }

            if (CommentReport::query()->where('comment_moderation_case_id', $case->id)->where('user_id', $member->id)->exists()) {
                throw new ApiException(409, 'ALREADY_REPORTED', '您已檢舉過這則留言。');
            }

            CommentReport::query()->create([
                'comment_moderation_case_id' => $case->id,
                'article_comment_id' => $comment->id,
                'user_id' => $member->id,
                'reason' => $reason,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ]);

            $case->report_count = CommentReport::query()->where('comment_moderation_case_id', $case->id)->count();

            if ($case->report_count >= $threshold && $case->ai_triggered_at === null) {
                $case->ai_triggered_at = now();
                $case->ai_status = 'pending';
                DB::afterCommit(fn () => ReviewReportedComment::dispatch($case->id));
            }

            $case->save();

            return $case;
        });

        AuditEvent::record('comment.reported', $member->id, $comment, ['case_id' => $case->id, 'reason' => $reason]);

        return $case;
    }
}
