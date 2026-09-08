<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Уведомление админам: очередь встала — самая старая невзятая задача
 * ждёт дольше порога (queue:watchdog). Симптом для пользователей: заявки
 * создаются, но письма не уезжают в папки менеджеров, не доставляются в
 * личные ящики, не парсятся позиции (инцидент 2026-09-08 14:36–16:12).
 *
 * Channels: database (bell) + mail на SUPPORT_DEVELOPER_EMAIL, если задан.
 */
class QueueStalledNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, array{count:int, oldest_minutes:int, threshold:int}>  $stalled  очередь => метрики
     */
    public function __construct(
        public readonly array $stalled,
        public readonly int $reservedCount,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('support.developer_email')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'queue_stalled',
            'stalled' => $this->stalled,
            'reserved' => $this->reservedCount,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('MyLift · очередь задач встала')
            ->greeting('Очередь задач не разбирается.')
            ->line('Самые старые невзятые задачи ждут дольше порога:');
        foreach ($this->stalled as $queue => $m) {
            $mail->line(sprintf('- **%s**: %d задач, самая старая ждёт %d мин (порог %d).', $queue, $m['count'], $m['oldest_minutes'], $m['threshold']));
        }

        return $mail
            ->line(sprintf('Задач в работе у воркеров сейчас: %d.', $this->reservedCount))
            ->line('Что проверить на сервере: `sudo supervisorctl status`, `php artisan queue:monitor`, лог `/var/log/mzcorp/worker.log`. Если воркеры заняты только `mail-sync` — перезапустить `sudo supervisorctl restart mzcorp-worker:*`.')
            ->salutation('— MyLift CRM');
    }
}
