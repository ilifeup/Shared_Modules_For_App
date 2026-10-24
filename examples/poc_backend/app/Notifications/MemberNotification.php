<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * S4 M04 in-app notification (database channel only). The optional action tells
 * the App where to go (e.g. type "notice" + id); the App decides the screen.
 */
class MemberNotification extends Notification
{
    use Queueable;

    public const TYPES = ['system', 'account', 'membership', 'notice'];

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $category = 'system',
        public readonly ?string $actionType = null,
        public readonly ?string $actionId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->category,
            'action_type' => $this->actionType,
            'action_id' => $this->actionId,
        ];
    }
}
