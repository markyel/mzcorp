<?php

namespace App\Services\Mail;

use App\Exceptions\Mail\TransientImapException;
use App\Jobs\Mail\RouteMailToManagerJob;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Единый владелец операции «положить письмо в папку менеджера» из синхронного
 * кода (персистер, job парсинга). До 2026-09-09 связка «попробовать синхронно →
 * на transient-сбое Yandex диспатчить RouteMailToManagerJob с задержкой» была
 * скопирована в четырёх местах и расходилась в деталях (что логировать, что
 * глотать).
 *
 * Поведение сохранено: сначала синхронный UID MOVE (быстрый happy-path), на
 * TransientImapException — async retry с backoff (RouteMailToManagerJob,
 * tries=5); прочие ошибки — warning, пайплайн не валим (письмо доберёт
 * mail:backfill-manager-deliveries / повторный проход).
 */
class ManagerMailRoutingService
{
    /** Через сколько секунд повторять после transient-сбоя Yandex. */
    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(private readonly MailFolderRouter $router)
    {
    }

    /** Письмо уже лежит в подпапке менеджера (MZ/… или MZ|…, Yandex-разделитель «|»). */
    public function isAlreadyRouted(EmailMessage $message): bool
    {
        $folder = (string) $message->folder;

        return str_contains($folder, 'MZ/') || str_contains($folder, 'MZ|');
    }

    /**
     * Синхронно перенести письмо в папку менеджера; на transient-сбое —
     * поставить RouteMailToManagerJob с задержкой. Никогда не бросает.
     *
     * @param  string  $context  метка вызывающего для лога (напр. 'persister', 'adopt')
     */
    public function routeOrRetry(EmailMessage $message, User $manager, string $context): void
    {
        try {
            $this->router->routeToManager($message->fresh() ?? $message, $manager);
        } catch (TransientImapException $e) {
            Log::info('ManagerMailRouting: transient routing failure, dispatching async retry', [
                'context' => $context,
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
                'error' => $e->getMessage(),
            ]);
            RouteMailToManagerJob::dispatch($message->id, $manager->id)
                ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
        } catch (\Throwable $e) {
            Log::warning('ManagerMailRouting: routing failed (non-fatal)', [
                'context' => $context,
                'email_message_id' => $message->id,
                'manager_id' => $manager->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
