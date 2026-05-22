<?php

namespace App\Services\Mail;

use App\Exceptions\Mail\TransientImapException;
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

        // Yandex IMAP / webklex имеют проблемы с не-ASCII именами папок
        // (createFolder и copy/move работают по-разному с MUTF-7). Решение:
        // имена папок только в ASCII через транслитерацию.
        $shortName = $manager ? $this->shortName($manager->name) : 'Unassigned';
        $shortName = $this->cyrillicToLatin($shortName);
        $shortName = mb_substr($shortName, 0, 40);

        // Fallback safety: после транслитерации должна остаться хотя бы
        // одна латинская буква. Иначе папка получится «MZ|3» (числовой
        // suffix из имени типа «РОП 3») — это не identifies user в Yandex
        // web UI. Берём local-part email'а.
        if ($manager && ! preg_match('/[A-Za-z]/', $shortName)) {
            $local = (string) strstr((string) $manager->email, '@', true);
            if ($local === '') {
                $local = (string) $manager->email;
            }
            $shortName = preg_replace('/[^A-Za-z0-9._-]+/', '', $local) ?? $local;
            $shortName = mb_substr($shortName, 0, 40);
            if ($shortName === '') {
                $shortName = 'user' . $manager->id;
            }
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $delimiter = $this->detectDelimiter($client, $message->folder);

            // ASCII-only path — нет нужды в MUTF-7 encoding.
            $targetPath = 'MZ' . $delimiter . $shortName;

            $this->ensureFolder($client, $targetPath, $delimiter);

            $sourceFolder = $client->getFolderByPath($message->folder, soft_fail: true);
            if (! $sourceFolder) {
                throw new \RuntimeException("Source folder '{$message->folder}' not found");
            }

            // Атомарный UID MOVE (RFC 6851). Yandex 360 поддерживает MOVE
            // в CAPABILITY: проверено `mail:try-move` 2026-05-21 — ответ
            // сервера «OK [COPYUID <uidv> <src> <dst>] / N EXPUNGE / OK
            // UID MOVE Completed.». Оригинал физически удаляется из INBOX
            // одной командой, копия появляется в target — без дубликата.
            //
            // Требует READ-WRITE сессии (SELECT, не EXAMINE).
            $client->openFolder($sourceFolder->path, force_select: true);
            $connection = $client->getConnection();

            $newUid = null;
            $rawResponse = null;
            try {
                $resp = $connection->moveMessage(
                    $targetPath,
                    (int) $message->imap_uid,
                    null,
                    IMAP::ST_UID,
                );
                $rawResponse = $resp->validatedData();
                // Реальный признак успеха — наличие COPYUID в ответе.
                // Yandex возвращает «OK [CLIENTBUG] UID MOVE Completed (no
                // messages).» с boolean=true даже когда UID не найден и
                // ничего не переместилось. По COPYUID отличаем настоящее
                // перемещение от no-op.
                $newUid = $this->parseCopyUid($rawResponse);
            } catch (\Throwable $moveError) {
                Log::warning('MailFolderRouter: UID MOVE threw exception', [
                    'email_message_id' => $message->id,
                    'mailbox_id' => $mailbox->id,
                    'from_folder' => $sourceFolder->path,
                    'to_folder' => $targetPath,
                    'imap_uid' => $message->imap_uid,
                    'error' => $moveError->getMessage(),
                ]);
                throw new TransientImapException(
                    sprintf('UID MOVE failed for email_message=%d: %s', $message->id, $moveError->getMessage()),
                    0,
                    $moveError,
                );
            }

            if ($newUid === null) {
                // OK без COPYUID = no-op (письмо уже не в source-папке,
                // CLIENTBUG, или Yandex отказался по другой причине).
                // Бросаем TransientImapException — caller (Job) повторит с
                // backoff; типичный Yandex-flake проходит со 2-й попытки.
                Log::warning('MailFolderRouter: UID MOVE no-op (нет COPYUID в ответе)', [
                    'email_message_id' => $message->id,
                    'mailbox_id' => $mailbox->id,
                    'from_folder' => $sourceFolder->path,
                    'to_folder' => $targetPath,
                    'imap_uid' => $message->imap_uid,
                    'response' => is_array($rawResponse)
                        ? mb_substr(implode(' | ', array_map('strval', $rawResponse)), 0, 300)
                        : null,
                ]);
                throw new TransientImapException(
                    sprintf('UID MOVE no-op (no COPYUID) for email_message=%d', $message->id),
                );
            }

            // Yandex 360 quirk (подтверждено 2026-05-22 через verify-physical
            // UID FETCH — 20/20 in_both): UID MOVE возвращает COPYUID, копия
            // в target создаётся, НО оригинал в source НЕ EXPUNGED — Yandex
            // не выполняет EXPUNGE по неизвестной причине, в Yandex Web UI
            // секретарь видит дубль (оригинал в INBOX + копия в MZ/...).
            //
            // Lекарство (по CLAUDE.md «грабли Yandex»): пометить оригинал
            // `\Seen`. Дубль остаётся физически, но прочитанный — Yandex
            // UI не показывает его в счётчике непрочитанных, секретарь не
            // отвлекается. Это safer чем \Deleted+EXPUNGE (которые Yandex
            // CLIENTBUG-валит).
            //
            // Source folder уже открыт через openFolder выше — STORE
            // выполняется в той же READ-WRITE сессии. Fail-soft.
            try {
                $connection->store(
                    ['\Seen'],
                    (int) $message->imap_uid,
                    (int) $message->imap_uid,
                    '+',
                    true,
                    IMAP::ST_UID,
                );
            } catch (\Throwable $seenErr) {
                Log::info('MailFolderRouter: STORE \Seen на оригинал не удался', [
                    'email_message_id' => $message->id,
                    'old_uid' => (int) $message->imap_uid,
                    'error' => $seenErr->getMessage(),
                ]);
            }

            // Синхронизируем БД с физическим перемещением. Если COPYUID удалось
            // распарсить — пишем новый UID; иначе обнуляем (sync целевой папки
            // дозальёт его). Сохраняем target folder в любом случае.
            try {
                $message->forceFill([
                    'folder' => $targetPath,
                    'imap_uid' => $newUid,
                ])->save();
            } catch (\Throwable $dbErr) {
                Log::warning('MailFolderRouter: failed to update DB after MOVE', [
                    'email_message_id' => $message->id,
                    'error' => $dbErr->getMessage(),
                ]);
            }

            Log::info('MailFolderRouter: moved (UID MOVE)', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox->id,
                'from_folder' => $sourceFolder->path,
                'to_folder' => $targetPath,
                'manager_id' => $manager?->id,
                'old_uid' => (int) $message->imap_uid,
                'new_uid' => $newUid,
            ]);

            return $targetPath;
        } catch (TransientImapException $e) {
            // Уже залогировано в inner catch; пробрасываем для retry в Job.
            throw $e;
        } catch (\Throwable $e) {
            // Прочие IMAP-ошибки (BAD CLIENTBUG на ensureFolder, network drop
            // в getFolderByPath и т.п.) тоже считаем transient — Yandex
            // достаточно flake-склонен, чтобы любой outer-catch стоило ретраить.
            Log::error('MailFolderRouter: failed', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox->id,
                'manager_id' => $manager?->id,
                'error' => $e->getMessage(),
            ]);

            throw new TransientImapException(
                sprintf('routeToManager outer failure for email_message=%d: %s', $message->id, $e->getMessage()),
                0,
                $e,
            );
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
                $created = $client->createFolder($current);

                // Yandex 360 webmail рисует дерево из LSUB (только подписанные).
                // createFolder() не делает SUBSCRIBE автоматически — без явного
                // вызова MZ|Lastname создаётся физически, MOVE едет туда, но
                // секретарь и менеджер не видят папку в web UI. Fail-soft: если
                // SUBSCRIBE упал — папка всё равно есть, можно подписать вручную.
                try {
                    $fresh = $created instanceof \Webklex\PHPIMAP\Folder
                        ? $created
                        : $client->getFolderByPath($current, soft_fail: true);
                    $fresh?->subscribe();
                } catch (\Throwable $e) {
                    Log::info('MailFolderRouter: SUBSCRIBE after createFolder failed', [
                        'folder' => $current,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Определить разделитель иерархии для текущего сервера/папки.
     * Yandex 360 использует '|' для своих ящиков, не RFC-стандартный '/'.
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
     */
    private function shortName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) > 1 && in_array(mb_strtolower($parts[0]), ['менеджер', 'роп'], true)) {
            return $parts[1];
        }

        return $parts[0] ?? 'unknown';
    }

    /**
     * Из ответа UID MOVE / UID COPY извлечь новый UID в целевой папке.
     *
     * RFC 4315 формат: `* OK [COPYUID <uidvalidity> <source_uid_set> <dest_uid_set>]`
     * Yandex отдаёт: `OK [COPYUID 1778847504 2117 206]\r\n`
     *
     * Для одиночного MOVE source и dest — единичные UID (без диапазонов).
     *
     * @param mixed $validatedData Ответ webklex Response::validatedData().
     */
    private function parseCopyUid(mixed $validatedData): ?int
    {
        if (! is_array($validatedData)) {
            return null;
        }
        foreach ($validatedData as $line) {
            if (! is_string($line)) {
                continue;
            }
            if (preg_match('/COPYUID\s+\d+\s+\S+\s+(\d+)/i', $line, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Транслитерация кириллицы в латиницу для имён IMAP-папок.
     * Yandex IMAP / webklex плохо работают с MUTF-7 в copy/move
     * (createFolder работает, copy «Empty response»). ASCII-only обходит.
     *
     * Иванов → Ivanov, Петров → Petrov.
     */
    private function cyrillicToLatin(string $str): string
    {
        // Primary: ICU transliterator (extension intl).
        if (function_exists('transliterator_transliterate')) {
            $result = @transliterator_transliterate('Russian-Latin/BGN; Latin-ASCII', $str);
            if (is_string($result) && $result !== '') {
                return preg_replace('/[^A-Za-z0-9._-]+/', '', $result) ?? $result;
            }
        }

        // Fallback: ручной мап.
        $map = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
            'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R',
            'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        return strtr($str, $map);
    }
}
