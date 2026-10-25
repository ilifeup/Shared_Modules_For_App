<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * S9 M03-D10 email when a verification is revoked (types set to "email").
 */
class VerificationRevokedMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $typeName, public readonly string $reason) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] 認證已撤銷')
            ->line('您的「'.$this->typeName.'」認證已由管理員撤銷。')
            ->line('原因：'.$this->reason);
    }
}
