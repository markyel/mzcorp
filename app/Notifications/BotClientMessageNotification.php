<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Менеджеру: клиент написал по заявке в боте Telegram/MAX (колокольчик). */
class BotClientMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Request $request,
        public readonly string $channelLabel,
        public readonly string $text,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'bot_message',
            'request_id' => $this->request->id,
            'internal_code' => $this->request->internal_code,
            'client_name' => $this->request->client_name,
            'channel' => $this->channelLabel,
            'preview' => Str::limit($this->text, 120),
        ];
    }
}
