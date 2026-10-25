<?php

namespace App\Notifications;

use App\Models\CommentModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Email to moderators after the AI step of a reported comment.
 */
class CommentReviewNeeded extends Notification
{
    use Queueable;

    public function __construct(public readonly CommentModerationCase $case) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $case = $this->case->loadMissing(['comment.article', 'reports']);
        $comment = $case->comment;
        $reasons = config('articles.report_reasons', []);
        $reasonSummary = $case->reports->groupBy('reason')
            ->map(fn ($group, $code) => ($reasons[$code] ?? $code).' × '.$group->count())
            ->implode('、');
        $ai = match (true) {
            $case->ai_status === 'failed' => 'AI 無法使用（已先暫時隱藏）',
            $case->ai_decision === 'keep' => 'AI 判定：保留公開',
            $case->ai_decision === 'hide' => 'AI 判定：暫時隱藏',
            default => 'AI 無法判定（已先暫時隱藏）',
        };

        return (new MailMessage)
            ->subject('['.config('app.name').'] 留言檢舉待處理 #'.$case->id)
            ->line('文章：'.($comment->article?->title ?? '—'))
            ->line('留言：'.Str::limit((string) $comment->body, 300))
            ->line("檢舉（第 {$case->round} 輪）：{$case->report_count} 位會員；{$reasonSummary}")
            ->line($ai.($case->ai_reason ? '｜'.$case->ai_reason : ''))
            ->line('目前狀態：'.($comment->status === 'visible' ? '公開' : '隱藏'))
            ->action('開啟後台案件', url('/admin/comment-moderation-cases/'.$case->id.'/edit'));
    }
}
