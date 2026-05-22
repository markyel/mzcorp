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
 * Yandex 360 IMAP периодически возвращает «BAD [CLIENTBUG] EXPUNGE Wrong
 * session state» или OK без COPYUID (CLIENTBUG no-op). Это transient —
 * MailFolderRouter бросает TransientImapException, queue делает retry
 * с экспоненциальным backoff. 5 попыток с интервалами 30s/2m/5m/10m/30m
 * покрывают как мелкий flake, так и более длительные «штормы» сервера.
 */
class RouteMailToManagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Backoff в секундах между попытками: 30s, 2m, 5m, 10m, 30m.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 600, 1800];
    }

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
