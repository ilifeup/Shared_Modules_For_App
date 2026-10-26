<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\MemberNotification;
use App\Support\Api\ApiException;
use Illuminate\Support\Collection;

/**
 * Sends in-app notifications to members (never to the administrator account).
 */
final class MemberNotifier
{
    /**
     * @param  iterable<User>|User  $members
     * @return int number of members notified
     */
    public function send(iterable|User $members, string $title, string $body, string $type = 'system', ?string $actionType = null, ?string $actionId = null, ?User $actor = null, ?string $notificationId = null): int
    {
        $title = trim($title);
        $body = trim($body);

        $errors = array_filter([
            'title' => $title === '' || mb_strlen($title) > 120 ? ['標題需為 1–120 字。'] : null,
            'body' => $body === '' || mb_strlen($body) > 2000 ? ['內容需為 1–2000 字。'] : null,
            'type' => in_array($type, MemberNotification::TYPES, true) ? null : ['不支援的通知類型。'],
            'action_type' => $actionType !== null && ! preg_match('/^[a-z_]{1,30}$/', $actionType) ? ['導向類型格式錯誤。'] : null,
            'action_id' => $actionId !== null && mb_strlen($actionId) > 64 ? ['導向目標過長。'] : null,
        ]);

        if ($errors !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', '輸入資料有誤。', $errors);
        }

        $recipients = Collection::wrap($members instanceof User ? [$members] : $members)
            ->filter(fn (User $user): bool => ! $user->isAdmin());

        $notification = new MemberNotification($title, $body, $type, $actionType, $actionId === '' ? null : $actionId);
        if ($notificationId !== null) {
            $notification->id = $notificationId;
        }
        foreach ($recipients as $user) {
            $user->notify($notification);
        }

        if ($actor !== null) {
            AuditEvent::record('notification.sent', $actor->id, null, [
                'type' => $type,
                'recipients' => $recipients->count(),
            ]);
        }

        return $recipients->count();
    }
}
