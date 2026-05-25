<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Уведомление админам, что circuit-breaker для OpenAI открылся —
 * категоризатор / парсер / прочие AI-потребители временно не работают.
 *
 * Channels:
 *  - database (всегда) — для bell-иконки в шапке.
 *  - mail (если SUPPORT_DEVELOPER_EMAIL прописан) — на тот же адрес,
 *    куда уходят support-тикеты.
 */
class OpenAiCircuitOpenedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reason,
        public readonly int $failCount,
        public readonly int $cooldownMinutes,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('support.developer_email')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    /**
     * Данные для bell-dropdown. Парсятся в resources/views/livewire/
     * notifications/bell.blade.php по полю kind.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'openai_circuit_opened',
            'reason' => mb_substr($this->reason, 0, 200),
            'fail_count' => $this->failCount,
            'cooldown_minutes' => $this->cooldownMinutes,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shortReason = mb_substr($this->reason, 0, 200);

        return (new MailMessage())
            ->subject('MyLift · OpenAI недоступен — AI-категоризатор на паузе')
            ->greeting('Срочно: проверить OpenAI квоту.')
            ->line(sprintf(
                'Подряд провалившихся вызовов категоризатора: **%d**. Circuit-breaker открыт на **%d минут**.',
                $this->failCount,
                $this->cooldownMinutes,
            ))
            ->line('Все входящие письма за этот период остаются без category и не превращаются в заявки.')
            ->line('**Последняя ошибка:**')
            ->line($shortReason)
            ->action('Проверить баланс OpenAI', 'https://platform.openai.com/account/billing')
            ->line('После пополнения первый же успешный запрос автоматически снимет паузу. Backlog подберётся scheduler\'ом `mail:categorize --all` каждые 5 минут.')
            ->salutation('— MyLift CRM');
    }
}
