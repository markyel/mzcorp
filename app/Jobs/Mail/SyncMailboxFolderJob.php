<?php

namespace App\Jobs\Mail;

use App\Enums\MailDirection;
use App\Models\Mailbox;
use App\Models\MailboxFolderState;
use App\Services\Mail\MailboxConnector;
use App\Services\Mail\MessagePersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

/**
 * Синхронизирует одну папку одного ящика.
 *
 * Foundation §1 «Обработка входящего письма» pipeline:
 *   1. fetchNewMessages(mailbox, folder) — UID-инкрементальный fetch
 *   2. для каждого Message: dedup, сохранить EmailMessage
 *   3. сохранить state: last_uid_seen, uid_validity per mailbox per folder
 *
 * Этот job отвечает только за шаги 1 и 3 + базовое сохранение в БД.
 * AI-классификация (1.6), routing rules (1.5), создание Request (1.8) —
 * на следующих фазах, делается в отдельных job'ах после persist.
 *
 * ShouldBeUnique: в очереди не может быть параллельно двух одинаковых
 * sync-job'ов на одну (mailbox, folder) пару.
 */
class SyncMailboxFolderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Чанк fetch'а — больше = меньше IMAP round-trips, но больше памяти. */
    private const FETCH_CHUNK = 50;

    /** Ограничение на одну итерацию sync-а, чтобы при первом подключении к старому ящику не зависнуть. */
    private const MAX_MESSAGES_PER_RUN = 500;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $mailboxId,
        public readonly string $folderType, // 'inbox' | 'sent'
    ) {
    }

    public function uniqueId(): string
    {
        return sprintf('sync:%d:%s', $this->mailboxId, $this->folderType);
    }

    public function uniqueFor(): int
    {
        return 5 * 60; // секунд: после этого считаем job «зависшим» и разблокируем.
    }

    public function handle(MailboxConnector $connector, MessagePersister $persister): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            Log::info('SyncMailboxFolderJob: mailbox missing or inactive — skip.', [
                'mailbox_id' => $this->mailboxId,
            ]);

            return;
        }

        try {
            $client = $connector->imapClient($mailbox);
        } catch (\Throwable $e) {
            $this->markError($mailbox, $e);
            throw $e;
        }

        try {
            [$folder, $direction] = match ($this->folderType) {
                'inbox' => [$connector->findInbox($client), MailDirection::Inbound],
                'sent' => [$connector->findSent($client), MailDirection::Outbound],
                default => throw new \InvalidArgumentException("Unknown folder type: {$this->folderType}"),
            };

            $state = $this->getOrCreateState($mailbox, $folder);

            $status = $folder->examine();
            $serverUidValidity = (int) ($status['uidvalidity'] ?? 0);

            // Foundation: при смене UIDVALIDITY делаем full resync.
            if ($state->uid_validity !== null && $state->uid_validity !== $serverUidValidity) {
                Log::warning('UIDVALIDITY changed, full resync of folder', [
                    'mailbox_id' => $mailbox->id,
                    'folder' => $folder->path,
                    'old' => $state->uid_validity,
                    'new' => $serverUidValidity,
                ]);
                $state->last_uid_seen = 0;
            }

            $state->uid_validity = $serverUidValidity;

            $newSinceUid = $state->last_uid_seen + 1;
            $stats = $this->fetchAndPersist($folder, $newSinceUid, $mailbox, $direction, $persister);

            // Обновляем state, только если что-то засинкали (или совсем впервые).
            if ($stats['max_uid'] > $state->last_uid_seen) {
                $state->last_uid_seen = $stats['max_uid'];
            }
            $state->last_synced_at = now();
            $state->sync_count = (int) $state->sync_count + 1;
            $state->save();

            $mailbox->forceFill([
                'last_synced_at' => now(),
                'last_error_at' => null,
                'last_error_message' => null,
            ])->save();

            Log::info('SyncMailboxFolderJob completed', [
                'mailbox_id' => $mailbox->id,
                'folder' => $folder->path,
                'fetched' => $stats['fetched'],
                'saved' => $stats['saved'],
                'skipped_dup' => $stats['skipped_dup'],
                'last_uid_seen' => $state->last_uid_seen,
            ]);
        } catch (\Throwable $e) {
            $this->markError($mailbox, $e);
            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{fetched: int, saved: int, skipped_dup: int, max_uid: int}
     */
    private function fetchAndPersist(
        Folder $folder,
        int $sinceUid,
        Mailbox $mailbox,
        MailDirection $direction,
        MessagePersister $persister,
    ): array {
        $fetched = 0;
        $saved = 0;
        $skippedDup = 0;
        $maxUid = 0;

        // FT_PEEK — не ставим \Seen. Foundation §1: «\Seen явно НЕ ставим».
        // Вложения webklex подгрузит автоматически при getAttachments() в persister'е.
        //
        // ВАЖНО: webklex 6.x в whereUid('N:*') оборачивает значение в кавычки
        // (UID "N:*"), что невалидно для IMAP SEARCH (Yandex отвечает BAD).
        // Поэтому тянем без серверного UID-фильтра и отсеиваем уже виденные
        // письма по last_uid_seen на стороне приложения. Для инкрементальных
        // syncs это слегка избыточно по трафику, но безопасно и работает.
        $messages = $folder->query()
            ->setFetchOptions(IMAP::FT_PEEK)
            ->setFetchBody(true)
            ->setFetchFlags(true)
            ->limit(self::MAX_MESSAGES_PER_RUN)
            ->get();

        foreach ($messages as $msg) {
            $uid = $msg->getUid();

            // Сообщения с UID меньше уже виденного пропускаем.
            if ($uid < $sinceUid) {
                continue;
            }

            $fetched++;
            $maxUid = max($maxUid, $uid);

            try {
                $email = $persister->persist($msg, $mailbox, $folder->path, $direction);
                if ($email === null) {
                    $skippedDup++;
                } else {
                    $saved++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to persist message', [
                    'mailbox_id' => $mailbox->id,
                    'folder' => $folder->path,
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
                // Не валим весь job — продолжаем со следующим письмом.
            }
        }

        return [
            'fetched' => $fetched,
            'saved' => $saved,
            'skipped_dup' => $skippedDup,
            'max_uid' => $maxUid,
        ];
    }

    private function getOrCreateState(Mailbox $mailbox, Folder $folder): MailboxFolderState
    {
        return MailboxFolderState::firstOrNew([
            'mailbox_id' => $mailbox->id,
            'folder' => $folder->path,
        ]);
    }

    private function markError(Mailbox $mailbox, \Throwable $e): void
    {
        $mailbox->forceFill([
            'last_error_at' => now(),
            'last_error_message' => mb_substr($e->getMessage(), 0, 1000),
        ])->save();
    }
}
