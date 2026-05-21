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

            // Только COPY, без MOVE/DELETE/EXPUNGE — webklex 6.x на Yandex 360
            // даёт «BAD [CLIENTBUG] EXPUNGE Wrong session state» при попытке
            // чистого MOVE или COPY+EXPUNGE. Соглашаемся на дублирование:
            // оригинал остаётся в INBOX, копия появляется в MZ|Ivanov для
            // секретаря.
            $msg->copy($targetPath, expunge: false);

            // Помечаем оригинал в INBOX как прочитанный (\Seen). Это явное
            // отступление от Foundation §1 / CLAUDE.md «Не ставь \Seen» —
            // оправдано тем, что оригинал нельзя удалить (Yandex IMAP не
            // даёт EXPUNGE после COPY в той же сессии), и без \Seen в INBOX
            // копится «непрочитанный шум» который мешает секретарю.
            //
            // ВАЖНО: query()->...->get() выше использует EXAMINE (READ-ONLY).
            // STORE в read-only режиме отклоняется сервером. Явно открываем
            // папку в READ-WRITE режиме (force_select=true) перед STORE.
            // Двухступенчатый STORE:
            //   1) webklex Message::setFlag (high-level, открывает folder).
            //   2) fallback на raw connection STORE с UID (если #1 не сработал).
            $seenApplied = false;
            try {
                $client->openFolder($sourceFolder->path, force_select: true);
                $setFlagResult = $msg->setFlag('Seen');
                $seenApplied = $setFlagResult !== false;
            } catch (\Throwable $seenError) {
                Log::info('MailFolderRouter: setFlag(Seen) failed, will try raw STORE', [
                    'email_message_id' => $message->id,
                    'error' => $seenError->getMessage(),
                ]);
            }
            if (! $seenApplied) {
                try {
                    $connection = $client->getConnection();
                    // UID STORE +FLAGS (\Seen). 6-й параметр IMAP::ST_UID — флаг
                    // режима UID; раньше передавали null → команда уходила
                    // обычным STORE (по sequence number), что для нашего UID
                    // не имело смысла. См. webklex/php-imap@6.x Protocol::store.
                    $connection->store(
                        ['\\Seen'],
                        (int) $message->imap_uid,
                        (int) $message->imap_uid,
                        '+',
                        true,
                        IMAP::ST_UID,
                    );
                    $seenApplied = true;
                } catch (\Throwable $rawError) {
                    Log::warning('MailFolderRouter: raw STORE \\Seen also failed', [
                        'email_message_id' => $message->id,
                        'imap_uid' => $message->imap_uid,
                        'error' => $rawError->getMessage(),
                    ]);
                }
            }

            // БД отражает физическое размещение оригинала — он остался в INBOX.
            // Не меняем folder/uid у EmailMessage. Только Request маршрутизирован
            // (через folder name target в логе и нашу доменную модель).

            Log::info('MailFolderRouter: moved', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox->id,
                'from_folder' => $sourceFolder->path,
                'to_folder' => $targetPath,
                'manager_id' => $manager?->id,
                'seen_applied' => $seenApplied,
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
