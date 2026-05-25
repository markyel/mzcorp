<?php

namespace App\Services\Mail;

use App\Exceptions\Mail\TransientImapException;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\IMAP;

/**
 * Перекладывает старую копию письма из INBOX личного ящика бывшего
 * менеджера в подпапку `MZ/Reassigned` и помечает оригинал `\Seen`.
 *
 * Триггерится из `ReassignService::reassign` после смены `assigned_user_id`.
 * Эффект для бывшего менеджера в Yandex Web UI:
 *   - Письмо уходит из INBOX в подпапку `MZ/Reassigned` — менеджер видит,
 *     что заявка была передана.
 *   - Счётчик непрочитанных падает: STORE `\Seen` на оригинал. Если
 *     Yandex выполнил EXPUNGE (что бывает не всегда — известный quirk,
 *     см. MailFolderRouter:180-202) — STORE сработает no-op на исчезнувший
 *     UID и упадёт fail-soft.
 *
 * Идемпотентность через `detected_artifacts.inbox_deliveries[i].archived_at`:
 * если у delivery-записи старого менеджера уже есть archived_at — no-op.
 *
 * Skip-кейсы:
 *   - У старого менеджера нет личного Mailbox (не подключал OAuth);
 *   - Копия ещё не доставлена / sync не подобрал (imap_uid=null);
 *   - Folder копии не INBOX (менеджер сам переложил — уважаем выбор);
 *   - Уже archived (повторный dispatch).
 */
class MailReassignArchiverService
{
    private const ARCHIVE_FOLDER_NAME = 'Reassigned';

    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly MailFolderRouter $router,
    ) {
    }

    public function archive(EmailMessage $original, User $oldManager): bool
    {
        $oldEmail = mb_strtolower(trim((string) $oldManager->email));
        if ($oldEmail === '') {
            return false;
        }

        $mailbox = Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [$oldEmail])
            ->first();
        if (! $mailbox) {
            Log::info('MailReassignArchiverService: no personal mailbox, skip', [
                'email_message_id' => $original->id,
                'old_manager_id' => $oldManager->id,
            ]);
            return false;
        }

        // Идемпотентность — проверяем у ОРИГИНАЛА в общем ящике.
        $artifacts = (array) ($original->detected_artifacts ?? []);
        $deliveries = (array) ($artifacts['inbox_deliveries'] ?? []);
        $deliveryIdx = null;
        foreach ($deliveries as $i => $d) {
            if ((int) ($d['user_id'] ?? 0) === (int) $oldManager->id) {
                $deliveryIdx = $i;
                if (! empty($d['archived_at'])) {
                    Log::info('MailReassignArchiverService: already archived, skip', [
                        'email_message_id' => $original->id,
                        'old_manager_id' => $oldManager->id,
                    ]);
                    return false;
                }
                break;
            }
        }

        // Найти копию в личном ящике старого менеджера (cross-mailbox row,
        // см. MailDeliverToManagerService:128-170). После sync личного ящика
        // там должны быть imap_uid + folder='INBOX' + raw_source.
        $copy = EmailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('message_id', $original->message_id)
            ->whereNotNull('imap_uid')
            ->first();
        if (! $copy) {
            Log::info('MailReassignArchiverService: no delivered copy yet, skip', [
                'email_message_id' => $original->id,
                'old_manager_id' => $oldManager->id,
                'mailbox_id' => $mailbox->id,
            ]);
            return false;
        }

        $currentFolder = (string) $copy->folder;
        if (! str_starts_with($currentFolder, 'INBOX')) {
            Log::info('MailReassignArchiverService: copy not in INBOX, skip', [
                'copy_email_message_id' => $copy->id,
                'folder' => $currentFolder,
            ]);
            return false;
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $delimiter = $this->router->detectDelimiter($client, $currentFolder);
            $targetPath = 'MZ' . $delimiter . self::ARCHIVE_FOLDER_NAME;

            // Идемпотентность на уровне IMAP: если копия УЖЕ в MZ/Reassigned
            // (повторный dispatch когда БД-запись обновилась но artifacts
            // ещё не успели), запись в БД могла остаться с folder=INBOX до
            // обновления. Дополнительная защита.
            if ($currentFolder === $targetPath) {
                return false;
            }

            $this->router->ensureFolder($client, $targetPath, $delimiter);

            $sourceFolder = $client->getFolderByPath($currentFolder, soft_fail: true);
            if (! $sourceFolder) {
                Log::warning('MailReassignArchiverService: source folder not found', [
                    'copy_email_message_id' => $copy->id,
                    'folder' => $currentFolder,
                ]);
                return false;
            }

            $client->openFolder($sourceFolder->path, force_select: true);
            $connection = $client->getConnection();

            // UID MOVE (RFC 6851). Yandex 360 поддерживает.
            $newUid = null;
            try {
                $resp = $connection->moveMessage(
                    $targetPath,
                    (int) $copy->imap_uid,
                    null,
                    IMAP::ST_UID,
                );
                $newUid = $this->router->parseCopyUid($resp->validatedData());
            } catch (\Throwable $moveError) {
                Log::warning('MailReassignArchiverService: UID MOVE threw', [
                    'copy_email_message_id' => $copy->id,
                    'imap_uid' => $copy->imap_uid,
                    'from' => $sourceFolder->path,
                    'to' => $targetPath,
                    'error' => $moveError->getMessage(),
                ]);
                throw new TransientImapException(
                    sprintf('UID MOVE failed for copy email_message=%d: %s', $copy->id, $moveError->getMessage()),
                    0,
                    $moveError,
                );
            }

            if ($newUid === null) {
                Log::warning('MailReassignArchiverService: UID MOVE no-op (нет COPYUID)', [
                    'copy_email_message_id' => $copy->id,
                    'imap_uid' => $copy->imap_uid,
                ]);
                throw new TransientImapException(
                    sprintf('UID MOVE no-op (no COPYUID) for copy email_message=%d', $copy->id),
                );
            }

            // STORE `\Seen` на оригинальный UID (Yandex может не EXPUNGE'нуть
            // после MOVE — известный quirk на shared ящиках, на личных
            // поведение может отличаться). Fail-soft: если EXPUNGE прошёл
            // нормально, STORE на отсутствующий UID просто упадёт без
            // последствий.
            try {
                $connection->store(
                    ['\Seen'],
                    (int) $copy->imap_uid,
                    (int) $copy->imap_uid,
                    '+',
                    true,
                    IMAP::ST_UID,
                );
            } catch (\Throwable $seenErr) {
                Log::info('MailReassignArchiverService: STORE \\Seen на оригинал не удался (вероятно EXPUNGE прошёл)', [
                    'copy_email_message_id' => $copy->id,
                    'old_uid' => $copy->imap_uid,
                    'error' => $seenErr->getMessage(),
                ]);
            }

            // Обновляем запись копии: folder + UID.
            $copy->update([
                'folder' => $targetPath,
                'imap_uid' => $newUid,
            ]);

            // Audit на оригинале.
            if ($deliveryIdx !== null) {
                $deliveries[$deliveryIdx]['archived_at'] = now()->toIso8601String();
                $deliveries[$deliveryIdx]['archived_to'] = $targetPath;
            } else {
                // Не было delivery-записи у этого user'а (исторический случай,
                // backfill). Добавляем post-factum, чтобы повторный dispatch
                // не задвоил.
                $deliveries[] = [
                    'user_id' => $oldManager->id,
                    'mailbox_id' => $mailbox->id,
                    'delivered_at' => null,
                    'archived_at' => now()->toIso8601String(),
                    'archived_to' => $targetPath,
                ];
            }
            $artifacts['inbox_deliveries'] = $deliveries;
            $original->forceFill(['detected_artifacts' => $artifacts])->save();

            Log::info('MailReassignArchiverService: archived', [
                'email_message_id' => $original->id,
                'old_manager_id' => $oldManager->id,
                'mailbox_id' => $mailbox->id,
                'from_folder' => $sourceFolder->path,
                'to_folder' => $targetPath,
                'old_uid' => $copy->getOriginal('imap_uid'),
                'new_uid' => $newUid,
            ]);

            return true;
        } finally {
            if ($client !== null) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
