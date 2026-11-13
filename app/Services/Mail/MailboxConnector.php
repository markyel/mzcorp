<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

use function app;

/**
 * Открывает IMAP-соединения для Mailbox-моделей.
 *
 * Foundation §1: webklex/laravel-imap, поллинг каждые 1-2 минуты, READ-ONLY
 * для разбора (не ставим \Seen). Только при постановке custom-меток
 * MyLift/* (Phase 1.7) переоткрываем папку в read-write.
 */
class MailboxConnector
{
    /**
     * Соединиться с IMAP-сервером.
     *
     * Поддерживает оба пути аутентификации:
     *   - app-password (auth_type=password) — стандартный LOGIN
     *   - OAuth 2.0 / XOAUTH2 (auth_type=oauth) — через access_token Yandex
     *
     * Перед открытием соединения для OAuth-ящиков рефрешим токен,
     * если он близок к истечению (см. YandexOAuthService::ensureFreshToken).
     *
     * Соединение возвращается уже открытым (connect() вызван).
     *
     * @throws ConnectionFailedException
     */
    public function imapClient(Mailbox $mailbox): Client
    {
        if ($mailbox->isOAuth()) {
            // Гарантируем актуальность токена. ensureFreshToken сохранит
            // обновлённые токены в БД, если был выполнен refresh.
            app(YandexOAuthService::class)->ensureFreshToken($mailbox);

            $authPassword = $mailbox->accessToken() ?? '';
            $authentication = 'oauth';
        } else {
            $authPassword = $mailbox->password() ?? '';
            $authentication = null;
        }

        $accountKey = 'mb_' . $mailbox->id;

        $config = Config::make([
            'accounts' => [
                $accountKey => [
                    'host' => $mailbox->imap_host,
                    'port' => $mailbox->imap_port,
                    'protocol' => 'imap',
                    'encryption' => $this->normalizeEncryption($mailbox->imap_encryption),
                    'validate_cert' => true,
                    'username' => $mailbox->imap_username,
                    'password' => $authPassword,
                    'authentication' => $authentication,
                    'timeout' => self::CONNECT_TIMEOUT,
                ],
            ],
            'default' => $accountKey,
        ]);

        $client = new Client($config);
        for ($attempt = 1; ; $attempt++) {
            try {
                $client->connect();
                break;
            } catch (ConnectionFailedException $e) {
                if ($attempt >= self::CONNECT_ATTEMPTS || ! self::isConnectTimeout($e)) {
                    throw $e;
                }
                \Illuminate\Support\Facades\Log::info('MailboxConnector: IMAP connect timed out, retrying', [
                    'mailbox_id' => $mailbox->id,
                    'attempt' => $attempt,
                ]);
                usleep(500_000 * $attempt);
            }
        }
        // Подключение — короткий таймаут, чтение — прежние 30 с: UID SEARCH по папке
        // на 100k писем Яндекс считает секунд десять, и короткий таймаут рвал бы его.
        $stream = $client->getConnection()->stream ?? null;
        if (is_resource($stream)) {
            stream_set_timeout($stream, self::READ_TIMEOUT);
        }

        return $client;
    }

    /**
     * Подключение к IMAP Яндекса: часть новых соединений с нашего адреса сервер молча отбрасывает
     * (13.11.2026: 3–7% подключений к imap.yandex.ru:993 зависают, к smtp.yandex.ru и другим хостам —
     * ни одного). Раньше такое соединение ждало 30 с и роняло задачу (181 «connection failed» за день);
     * теперь — 8 с и повтор, повторное подключение почти всегда проходит.
     */
    private const CONNECT_TIMEOUT = 8;

    private const CONNECT_ATTEMPTS = 3;

    private const READ_TIMEOUT = 30;

    /** Соединение не установилось (таймаут/обрыв TCP или TLS) — в отличие от отказа в авторизации. */
    public static function isConnectTimeout(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \Webklex\PHPIMAP\Exceptions\AuthFailedException) {
                return false;
            }
        }
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (preg_match('/timed out|connection failed|connection setup failed|unable to connect|handshake/i', $x->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Найти папку INBOX. На IMAP-серверах это стандартное имя (RFC 3501).
     *
     * @throws \RuntimeException
     */
    public function findInbox(Client $client): Folder
    {
        $folder = $client->getFolderByPath('INBOX');

        if (! $folder) {
            throw new \RuntimeException('INBOX folder not found on this account.');
        }

        return $folder;
    }

    /**
     * Найти папку «Отправленные».
     *
     * ПРИОРИТЕТ — IMAP special-use атрибут \Sent (RFC 6154). Имя ненадёжно:
     * у Yandex рядом с СИСТЕМНОЙ "Sent" (флаг \Sent) часто живёт
     * ПОЛЬЗОВАТЕЛЬСКАЯ папка "Отправленные" (русское имя, БЕЗ \Sent) — её
     * создаёт почтовый клиент/правило. Ручные отправки менеджера из веб-клиента
     * Yandex падают в системную \Sent, а матч по имени "Отправленные" цеплял
     * пользовательскую — и весь исходящий поток менеджера не синхронизировался
     * (кейс Dmitry.Rumiantsev: системная Sent=59568 не читалась, читалась
     * кастомная "Отправленные"=15641 с одними автоотбивками MyLift).
     *
     * Фолбэк по именам — только для серверов без SPECIAL-USE в LIST.
     */
    public function findSent(Client $client): Folder
    {
        $special = $this->findFolderBySpecialUse($client, 'Sent');
        if ($special !== null) {
            return $special;
        }

        foreach (['Sent', 'Отправленные', '[Gmail]/Sent Mail', 'Sent Items'] as $candidate) {
            $folder = $client->getFolderByPath($candidate, soft_fail: true);
            if ($folder) {
                return $folder;
            }
        }

        throw new \RuntimeException('Sent folder not found (no \\Sent special-use; tried: Sent, Отправленные, [Gmail]/Sent Mail, Sent Items).');
    }

    /**
     * Найти папку по IMAP special-use флагу (RFC 6154): '\Sent', '\Drafts',
     * '\Trash', '\Junk', '\Archive'. Читает сырой LIST (path => flags[]).
     * Возвращает Folder или null, если флаг нигде не выставлен / LIST не дал
     * атрибутов (старые серверы).
     *
     * @param  string  $useFlag  имя флага без backslash, напр. 'Sent'
     */
    public function findFolderBySpecialUse(Client $client, string $useFlag): ?Folder
    {
        try {
            $list = (array) $client->getConnection()->folders('', '*')->validatedData();
        } catch (\Throwable) {
            return null;
        }

        $needle = strtolower(ltrim($useFlag, '\\'));
        foreach ($list as $path => $info) {
            $flags = is_array($info) ? ($info['flags'] ?? $info['attributes'] ?? []) : [];
            foreach ((array) $flags as $flag) {
                if (strtolower(ltrim((string) $flag, '\\')) === $needle) {
                    return $client->getFolderByPath((string) $path, soft_fail: true);
                }
            }
        }

        return null;
    }

    /**
     * Запустить connect, прочитать список папок и вернуть диагностику —
     * для health-check (Phase 1.11) и ручной проверки настроек.
     *
     * @return array{ok: bool, message?: string, folders?: array<int, string>, inbox?: array, sent?: array}
     */
    public function testConnection(Mailbox $mailbox): array
    {
        try {
            $client = $this->imapClient($mailbox);
            $folders = $client->getFolders(hierarchical: false);

            $names = $folders->map(fn (Folder $f) => $f->path)->values()->all();

            $inboxStatus = null;
            $sentStatus = null;

            try {
                $inbox = $this->findInbox($client);
                $inboxStatus = $inbox->examine();
            } catch (\Throwable $e) {
                $inboxStatus = ['error' => $e->getMessage()];
            }

            try {
                $sent = $this->findSent($client);
                $sentStatus = $sent->examine();
            } catch (\Throwable $e) {
                $sentStatus = ['error' => $e->getMessage()];
            }

            $client->disconnect();

            return [
                'ok' => true,
                'folders' => $names,
                'inbox' => $inboxStatus,
                'sent' => $sentStatus,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function normalizeEncryption(?string $value): string|false
    {
        return match (strtolower((string) $value)) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            'starttls' => 'starttls',
            'none', '' => false,
            default => 'ssl',
        };
    }
}
