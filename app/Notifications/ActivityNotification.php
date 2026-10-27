<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ActivityNotification extends Notification
{
    use Queueable;

    /**
     * @param array<string, string> $data
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->data['title'])
            ->greeting($notifiable->name.' さん')
            ->line($this->data['message'])
            ->action('通知を確認する', $this->data['action_url'])
            ->salutation('Certify LMS');
    }
}
