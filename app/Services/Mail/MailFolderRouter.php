<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Маршрутизация писем по подпапкам Yandex IMAP.
 *
 * Контекст: Foundation §1.6 требует, чтобы секретарь видел распределение
 * заявок в Yandex web UI. IMAP custom keywords для меток на Yandex не
 * работают (известный баг сервера: флаги слетают после CLOSE/SELECT;
 * Yandex 360 REST API endpoints для меток нет). Альтернатива: подпапки.
 *
 * Структура: INBOX/MZ/{Lastname}, INBOX/MZ/Нерасп. Inbox остаётся
 * с непереназначенными письмами; подпапки — backlog по менеджеру.
 *
 * Метод routeToManager выполняет IMAP MOVE с авто-созданием подпапки
 * и обновлением email_messages.folder в БД (imap_uid после MOVE
 * становится невалидным — обнуляем).
 */
class MailFolderRouter
{
    public function __construct(private readonly MailboxConnector $connector)
    {
    }

    /**
     * Переместить письмо в подпапку менеджера.
     *
     * @return string|null  Путь новой папки при успехе, null при ошибке.
     */
    public function routeToManager(EmailMessage $message, ?User $manager): ?string
    {
        $mailbox = $message->mailbox;
        if (! $mailbox) {
            return null;
        }

        $shortName = $manager ? $this->shortName($manager->name) : 'Нерасп.';
        $shortName = mb_substr($shortName, 0, 40);

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $delimiter = $this->detectDelimiter($client, $message->folder);

            // Yandex 360 не разрешает подпапки под INBOX
            // (BAD [CLIENTBUG] CREATE cannot apply to INBOX subfolder).
            // Все «Мои папки» живут на root-уровне параллельно с INBOX.
            $targetPath = 'MZ' . $delimiter . $shortName;

            $this->ensureFolder($client, $targetPath, $delimiter);

            $sourceFolder = $client->getFolderByPath($message->folder, soft_fail: true);
            if (! $sourceFolder) {
                throw new \RuntimeException("Source folder '{$message->folder}' not found");
            }

            // Yandex 360 любит per-message read-write SELECT для MOVE/COPY.
            // FT_PEEK гарантирует, что \Seen не выставится при чтении.
            $msgs = $sourceFolder->query()
                ->setFetchOptions(IMAP::FT_PEEK)
                ->setFetchBody(false)
                ->setFetchFlags(true)
                ->whereUid($message->imap_uid)
                ->get();

            $msg = $msgs->first();
            if (! $msg) {
                throw new \RuntimeException(
                    "Message UID {$message->imap_uid} not found in '{$message->folder}'"
                );
            }

            $msg->move($targetPath);

            // После MOVE старый UID невалиден; новый UID Yandex назначит сам,
            // но webklex move() не возвращает его надёжно. Чистим, чтобы
            // никакой код не пытался дёргать сообщение по старому UID.
            $message->forceFill([
                'folder' => $targetPath,
                'imap_uid' => null,
            ])->save();

            Log::info('MailFolderRouter: moved', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox->id,
                'from_folder' => $sourceFolder->path,
                'to_folder' => $targetPath,
                'manager_id' => $manager?->id,
            ]);

            return $targetPath;
        } catch (\Throwable $e) {
            Log::error('MailFolderRouter: failed', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox->id,
                'manager_id' => $manager?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $client?->disconnect();
        }
    }

    /**
     * Рекурсивно гарантировать существование пути «A/B/C»: сначала «A»,
     * потом «A/B», потом «A/B/C». Webklex createFolder на отсутствующего
     * родителя у Yandex отвечает BAD CLIENTBUG.
     */
    private function ensureFolder(Client $client, string $path, string $delimiter): void
    {
        $parts = explode($delimiter, $path);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . $delimiter . $part;
            $existing = $client->getFolderByPath($current, soft_fail: true);
            if (! $existing) {
                $client->createFolder($current);
            }
        }
    }

    /**
     * Определить разделитель иерархии для текущего сервера/папки.
     * Yandex 360 использует '/' (RFC), но запрашиваем у самой папки —
     * безопаснее на случай legacy-серверов с '|'.
     */
    private function detectDelimiter(Client $client, string $sourceFolderPath): string
    {
        $folder = $client->getFolderByPath($sourceFolderPath, soft_fail: true);
        if ($folder && ! empty($folder->delimiter)) {
            return (string) $folder->delimiter;
        }

        return '/';
    }

    /**
     * «Менеджер Иванов Иван» → «Иванов».
     * Дублирует IncomingMailProcessor::shortName() / RequestItemPersister::shortName().
     */
    private function shortName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) > 1 && in_array(mb_strtolower($parts[0]), ['менеджер', 'роп'], true)) {
            return $parts[1];
        }

        return $parts[0] ?? 'unknown';
    }
}
