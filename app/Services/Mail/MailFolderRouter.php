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

            // Human-readable путь (для БД). IMAP-команды требуют MUTF-7 для
            // не-ASCII (RFC 3501 §5.1.3). Yandex 360 хранит «Иванов» как
            // «&BBgEMgQwBD0EPgQy-», поэтому createFolder/copy/move нужно
            // звать на encoded path.
            $targetPathHuman = 'MZ' . $delimiter . $shortName;
            $targetPathImap = $this->mutf7Encode($targetPathHuman);

            $this->ensureFolder($client, $targetPathImap, $delimiter);

            $sourceFolderImap = $this->mutf7Encode($message->folder);
            $sourceFolder = $client->getFolderByPath($sourceFolderImap, soft_fail: true);
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

            // Yandex 360 IMAP не отвечает корректно на MOVE-команду
            // («Command failed to process: Empty response»), поэтому
            // используем явный COPY + STORE \Deleted + EXPUNGE.
            // Возможно Yandex не объявляет MOVE capability — webklex
            // не делает fallback автоматически.
            try {
                $msg->move($targetPathImap);
            } catch (\Throwable $moveError) {
                Log::info('MailFolderRouter: MOVE failed, falling back to COPY+DELETE', [
                    'email_message_id' => $message->id,
                    'target' => $targetPathHuman,
                    'error' => $moveError->getMessage(),
                ]);

                // COPY оригинала в подпапку.
                $msg->copy($targetPathImap, expunge: false);

                // Удаление: STORE +FLAGS (\Deleted) без EXPUNGE.
                // Yandex 360: «BAD [CLIENTBUG] EXPUNGE Wrong session state»
                // если вызвать $msg->delete(true) сразу — сессия после COPY
                // выходит из read-write состояния для source.
                $msg->delete(expunge: false);

                // Отдельный EXPUNGE через явный re-SELECT source-папки в RW.
                try {
                    $sourceFolder->expunge();
                } catch (\Throwable $expungeError) {
                    // Не критично: \Deleted уже выставлен. Yandex web UI
                    // обычно скрывает помеченные на удаление, EXPUNGE случится
                    // при следующем SELECT той же сессии (Yandex auto-EXPUNGE
                    // при switch folder).
                    Log::info('MailFolderRouter: EXPUNGE skipped (will happen on next SELECT)', [
                        'email_message_id' => $message->id,
                        'error' => $expungeError->getMessage(),
                    ]);
                }
            }

            // После MOVE старый UID невалиден; новый UID Yandex назначит сам,
            // но webklex move() не возвращает его надёжно. Чистим, чтобы
            // никакой код не пытался дёргать сообщение по старому UID.
            // В БД храним human-readable, не encoded.
            $message->forceFill([
                'folder' => $targetPathHuman,
                'imap_uid' => null,
            ])->save();
            $targetPath = $targetPathHuman;

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
     *
     * $imapPath — уже MUTF-7 encoded (мы кодируем целиком в caller'е).
     */
    private function ensureFolder(Client $client, string $imapPath, string $delimiter): void
    {
        $parts = explode($delimiter, $imapPath);
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
     * Yandex 360 использует '|' для своих ящиков, не RFC-стандартный '/'.
     */
    private function detectDelimiter(Client $client, string $sourceFolderPath): string
    {
        $folder = $client->getFolderByPath($this->mutf7Encode($sourceFolderPath), soft_fail: true);
        if ($folder && ! empty($folder->delimiter)) {
            return (string) $folder->delimiter;
        }

        return '/';
    }

    /**
     * Кодирование UTF-8 → MUTF-7 (RFC 3501 §5.1.3) для IMAP mailbox names.
     * Yandex 360 требует MUTF-7 для не-ASCII в имени папки. PHP mbstring
     * поддерживает это через encoding name 'UTF7-IMAP'.
     */
    private function mutf7Encode(string $utf8): string
    {
        $encoded = @mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8');

        return $encoded === false ? $utf8 : $encoded;
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
