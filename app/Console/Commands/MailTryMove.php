<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\MailboxConnector;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\IMAP;

/**
 * Экспериментальная команда для проверки, поддерживает ли Yandex IMAP
 * расширение MOVE (RFC 6851) и работает ли оно для нашего сценария.
 *
 * Что делает:
 *   1. Подключается к указанному ящику.
 *   2. Запрашивает CAPABILITY → ищет 'MOVE'.
 *   3. SELECT исходной папки в read-write режиме.
 *   4. Пробует UID MOVE одного письма в целевую папку.
 *   5. Печатает все ответы сервера, чтобы видеть «BAD CLIENTBUG» если есть.
 *
 * Не маршрутизирует через MailFolderRouter — только сырой webklex.
 *
 * Использование:
 *   php artisan mail:try-move {mailbox_id} {uid} {target_folder} [--source=INBOX]
 *
 * Пример:
 *   php artisan mail:try-move 1 12345 "MZ|Test"
 */
class MailTryMove extends Command
{
    protected $signature = 'mail:try-move
                            {mailbox_id : ID ящика из таблицы mailboxes}
                            {uid : IMAP UID письма в исходной папке}
                            {target : Целевая папка (пример: MZ|Test)}
                            {--source=INBOX : Исходная папка}';

    protected $description = 'Тест UID MOVE одного письма на Yandex IMAP';

    public function handle(MailboxConnector $connector): int
    {
        $mailboxId = (int) $this->argument('mailbox_id');
        $uid = (int) $this->argument('uid');
        $target = (string) $this->argument('target');
        $source = (string) $this->option('source');

        $mailbox = Mailbox::find($mailboxId);
        if (! $mailbox) {
            $this->error("Mailbox #{$mailboxId} не найден");
            return self::FAILURE;
        }

        $this->line("=== Ящик #{$mailbox->id} {$mailbox->email} ({$mailbox->type->value}) ===");
        $this->line("Source: {$source}");
        $this->line("Target: {$target}");
        $this->line("UID:    {$uid}");
        $this->line('');

        $client = $connector->imapClient($mailbox);
        $connection = $client->getConnection();

        // 1. CAPABILITY
        $this->line('--- 1. CAPABILITY ---');
        try {
            $caps = $connection->getCapabilities()->validatedData();
            $capsList = is_array($caps) ? $caps : [];
            $this->line('  ' . implode(' ', $capsList));
            if (in_array('MOVE', $capsList, true)) {
                $this->info('  ✓ MOVE supported');
            } else {
                $this->warn('  ✗ MOVE NOT in capabilities — webklex упадёт на fallback COPY+STORE+EXPUNGE');
            }
        } catch (\Throwable $e) {
            $this->error('  CAPABILITY failed: ' . $e->getMessage());
        }
        $this->line('');

        // 2. Гарантировать существование целевой папки (рекурсивно по разделителю).
        $this->line('--- 2. Ensure target folder ---');
        try {
            $existing = $client->getFolderByPath($target, soft_fail: true);
            if ($existing) {
                $this->info('  ✓ Папка ' . $target . ' уже существует');
            } else {
                // Создание по частям: 'MZ|Test' → сначала 'MZ', потом 'MZ|Test'.
                $delimiter = str_contains($target, '|') ? '|' : '/';
                $parts = explode($delimiter, $target);
                $current = '';
                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }
                    $current = $current === '' ? $part : $current . $delimiter . $part;
                    $check = $client->getFolderByPath($current, soft_fail: true);
                    if (! $check) {
                        $client->createFolder($current);
                        $this->info('  + Создана: ' . $current);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error('  Ensure folder failed: ' . $e->getMessage());
            $client->disconnect();
            return self::FAILURE;
        }
        $this->line('');

        // 3. SELECT source folder (write mode)
        $this->line('--- 3. SELECT source folder (write mode) ---');
        try {
            $client->openFolder($source, force_select: true);
            $this->info('  ✓ SELECT ' . $source . ' OK');
        } catch (\Throwable $e) {
            $this->error('  SELECT failed: ' . $e->getMessage());
            $client->disconnect();
            return self::FAILURE;
        }
        $this->line('');

        // 4. Проверка наличия письма по UID
        $this->line('--- 4. FETCH flags по UID ---');
        try {
            $flagsResp = $connection->flags($uid, IMAP::ST_UID)->validatedData();
            $flags = $flagsResp[$uid] ?? null;
            if ($flags === null) {
                $this->warn("  ✗ UID {$uid} не найден в {$source}");
            } else {
                $this->info('  ✓ Письмо найдено, flags=' . implode(',', (array) $flags));
            }
        } catch (\Throwable $e) {
            $this->error('  getFlags failed: ' . $e->getMessage());
        }
        $this->line('');

        // 5. MOVE
        $this->line('--- 5. UID MOVE ---');
        try {
            $resp = $connection->moveMessage($target, $uid, null, IMAP::ST_UID);
            $ok = $resp->boolean();
            $this->line('  Response boolean: ' . var_export($ok, true));
            $this->line('  Response data: ' . substr(json_encode($resp->validatedData(), JSON_UNESCAPED_UNICODE), 0, 500));
            if ($ok) {
                $this->info('  ✓ MOVE прошёл (либо нативный UID MOVE, либо webklex-fallback COPY+STORE+EXPUNGE)');
            } else {
                $this->error('  ✗ MOVE вернул false');
            }
        } catch (\Throwable $e) {
            $this->error('  MOVE failed: ' . $e->getMessage());
        }
        $this->line('');

        // 6. Контрольная проверка
        $this->line('--- 6. POST-CHECK: письмо ещё в source? ---');
        try {
            $flagsResp = $connection->flags($uid, IMAP::ST_UID)->validatedData();
            $flags = $flagsResp[$uid] ?? null;
            if ($flags === null) {
                $this->info("  ✓ UID {$uid} удалён из {$source} — MOVE сработал");
            } else {
                $this->warn("  ✗ UID {$uid} ещё в {$source}, flags=" . implode(',', (array) $flags));
                if (in_array('\\Deleted', (array) $flags, true) || in_array('Deleted', (array) $flags, true)) {
                    $this->warn('    (но помечен как \\Deleted — EXPUNGE не прошёл, оригинал остался)');
                }
            }
        } catch (\Throwable $e) {
            $this->error('  POST-CHECK failed: ' . $e->getMessage());
        }
        $this->line('');

        $client->disconnect();
        $this->info('Done.');
        return self::SUCCESS;
    }
}
