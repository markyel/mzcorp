<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\MailFolderRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async IMAP-move письма в подпапку нового менеджера.
 *
 * Вынесено из синхронного `ReassignService::reassign()`: Yandex 360
 * IMAP COPY на крупных папках держит соединение 5–10 секунд, в это
 * время Livewire-запрос блокировал UI («кнопка зависла»). Теперь
 * reassign в БД мгновенный, IMAP уезжает в воркер.
 *
 * Идемпотентность: `MailFolderRouter::routeToManager` сам по UID
 * ищет письмо и пропускает если оригинал уже в папке менеджера.
 * При retry — best-effort, не валит UI-операцию.
 *
 * tries=3 + backoff=30 покрывают transient Yandex IMAP ошибки
 * (rate-limit, EXPUNGE wrong state).
 */
class RouteMailToManagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $emailMessageId,
        public readonly ?int $managerId,
    ) {
    }

    public function handle(MailFolderRouter $router): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            Log::info('RouteMailToManagerJob: message not found, skip', [
                'email_message_id' => $this->emailMessageId,
            ]);

            return;
        }

        $manager = $this->managerId !== null ? User::find($this->managerId) : null;

        $router->routeToManager($message, $manager);
    }
}
