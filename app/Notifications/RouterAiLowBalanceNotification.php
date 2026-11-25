<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Админам: на счёте RouterAI осталось меньше порога (services.routerai.balance_alert_rub).
 * На нуле RouterAI отвечает 402 и все задачи ИИ уходят в OpenAI напрямую (18.11 и 23.11.2026).
 *
 * Channels: database (колокольчик) + mail, если задан SUPPORT_DEVELOPER_EMAIL.
 */
class RouterAiLowBalanceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly float $credits,
        public readonly float $threshold,
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
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'routerai_low_balance',
            'credits' => round($this->credits, 2),
            'threshold' => round($this->threshold, 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(sprintf('MyLift · на счёте RouterAI %s ₽ — пополните', number_format($this->credits, 0, ',', ' ')))
            ->greeting('Пора пополнить RouterAI.')
            ->line(sprintf(
                'Остаток на счёте: **%s ₽** (порог предупреждения — %s ₽).',
                number_format($this->credits, 2, ',', ' '),
                number_format($this->threshold, 0, ',', ' '),
            ))
            ->line('Когда деньги кончатся, разбор писем и заявок уйдёт напрямую в OpenAI — работа не встанет, но станет дороже.')
            ->action('Пополнить RouterAI', 'https://routerai.ru')
            ->salutation('— MyLift CRM');
    }
}
