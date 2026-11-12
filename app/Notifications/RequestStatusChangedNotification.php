<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;


    public function __construct(
        public int $requestId,
        public string $requestTitle,
        public string $action,
        public ?string $fromStatus,
        public ?string $toStatus,
        public string $actorName,
        public string $message
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'request_id' => $this->requestId,
            'request_title' => $this->requestTitle,
            'action' => $this->action,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'actor_name' => $this->actorName,
            'message' => $this->message,
        ];
    }
}