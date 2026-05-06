<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

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
     * Соединение возвращается уже открытым (connect() вызван).
     *
     * @throws ConnectionFailedException
     */
    public function imapClient(Mailbox $mailbox): Client
    {
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
                    'password' => $mailbox->password() ?? '',
                    'authentication' => null,
                    'timeout' => 30,
                ],
            ],
            'default' => $accountKey,
        ]);

        $client = new Client($config);
        $client->connect();

        return $client;
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
     * Foundation §1: Yandex использует русские имена. Пробуем варианты в порядке:
     * 1) "Отправленные" — Yandex
     * 2) "Sent" — RFC-стандартное имя многих серверов
     * 3) "[Gmail]/Sent Mail" — Gmail-пространство (на всякий случай)
     *
     * Будущая итерация: использовать LIST-EXTENDED с RETURN (SPECIAL-USE),
     * чтобы автоматически находить \Sent на любом сервере.
     */
    public function findSent(Client $client): Folder
    {
        foreach (['Отправленные', 'Sent', '[Gmail]/Sent Mail', 'Sent Items'] as $candidate) {
            $folder = $client->getFolderByPath($candidate, soft_fail: true);
            if ($folder) {
                return $folder;
            }
        }

        throw new \RuntimeException('Sent folder not found (tried: Отправленные, Sent, [Gmail]/Sent Mail, Sent Items).');
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
