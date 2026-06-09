<?php

namespace App\Jobs\Mail;

use App\Enums\MailboxType;
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

    /**
     * Ограничение на одну итерацию sync-а, чтобы при первом подключении к
     * старому ящику не зависнуть. 100 — компромисс: меньше шансов на timeout
     * (см. ниже), кран успевает добежать до конца раньше уникального окна.
     * Раньше было 500 — на больших ящиках (Yandex 100k+ писем) `Query->get()`
     * per-UID + MailRouter (LLM categorize) на каждое письмо вылезали за
     * timeout=120, job попадал в failed_jobs каждые 6 минут (см. 2026-05-22).
     */
    private const MAX_MESSAGES_PER_RUN = 100;

    public int $tries = 3;

    /**
     * Раньше было 120с — на массивных ящиках Yandex IMAP не успевал отдать
     * headers + body для всех 500 писем (см. fetchAndPersist цикл), плюс
     * MailRouter::route внутри foreach дёргает MailCategoryClassifier (LLM,
     * 1-3s/письмо) и MailFolderRouter (отдельная IMAP-сессия). Job регулярно
     * вылетал в Illuminate\Queue\TimeoutExceededException, очередь копила
     * failed_jobs.
     *
     * 600с (10 мин) с MAX_MESSAGES_PER_RUN=100 даёт ~6с/письмо headroom —
     * хватает на IMAP fetch + LLM categorize + IMAP move.
     */
    public int $timeout = 600;

    public function __construct(
        public readonly int $mailboxId,
        public readonly string $folderType, // 'inbox' | 'sent'
    ) {
        // Очередь `mail-sync` — выделена, чтобы IMAP-синхронизация
        // (time-critical) не блокировалась тяжёлыми catalog/LLM-jobs
        // в общей `default`. Supervisor слушает очереди с приоритетом
        // mail-sync,default,catalog-resolve. Инцидент 2026-05-28.
        //
        // ВАЖНО: устанавливаем queue через `onQueue()` в __construct, а
        // НЕ через `public $queue = '...'` на уровне класса — PHP 8 trait
        // composition считает любое class-level default несовместимым с
        // `public $queue;` (null) из Queueable trait → Fatal Error при
        // загрузке класса. onQueue() устанавливает $this->queue runtime,
        // без property-level конфликта.
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return sprintf('sync:%d:%s', $this->mailboxId, $this->folderType);
    }

    public function uniqueFor(): int
    {
        // Должен быть БОЛЬШЕ $timeout, иначе при честном долгом fetch'е
        // (10 мин) cron может запустить дубль и пара будет конкурировать
        // за один IMAP-логин. 15 мин = timeout (10) + запас (5).
        return 15 * 60;
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

        // Guard 2: даже если is_active=true, ящик должен быть syncable
        // (shared ИЛИ personal с владельцем-request_handler). Личные ящики
        // директора/секретаря/админа не должны синкаться — там не клиентская
        // переписка, а закупки оборудования / внутренняя коммуникация
        // (кейс M-2026-1723 alexander.rodenkov@myzip.ru: ответ от поставщика
        // станка попал как клиентская заявка). Прямой dispatch с
        // `--mailbox=N` обходит scopeSyncable в mail:sync — этот guard
        // ловит такие случаи на стороне worker'а.
        if (! Mailbox::query()->syncable()->whereKey($mailbox->id)->exists()) {
            Log::warning('SyncMailboxFolderJob: mailbox not syncable (owner role или type) — skip.', [
                'mailbox_id' => $mailbox->id,
                'email' => $mailbox->email,
                'type' => $mailbox->type?->value,
                'owner_user_id' => $mailbox->owner_user_id,
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

            // Смена UIDVALIDITY: старый last_uid_seen больше не валиден (UID
            // переназначены сервером). РАНЬШЕ тут стояло last_uid_seen=0 ⇒
            // следующий fetch видел «бэклог» из всех писем папки, а срез
            // последних 100 (см. fetchAndPersist) выкидывал остальное, двигая
            // watermark на max — ИМЕННО ТАК была потеряна история Sent у
            // Dmitry.Rumiantsev (15560 писем). Для папок на десятки-сотни тысяч
            // (info@: 167k Sent) полный re-ingest вообще неподъёмен (+ LLM на
            // каждое outbound). Решение: при смене UIDVALIDITY ре-watermark на
            // текущий max (как first-sync) — пропускаем историю, ловим только
            // новое. Окно потери ≤ интервала cron'а (2 мин). Историю при нужде
            // добираем отдельной таргетной командой.
            $uidValidityChanged = $state->uid_validity !== null
                && $state->uid_validity !== $serverUidValidity;
            if ($uidValidityChanged) {
                Log::warning('UIDVALIDITY changed — re-watermark to current max (история не дренится массово)', [
                    'mailbox_id' => $mailbox->id,
                    'folder' => $folder->path,
                    'old' => $state->uid_validity,
                    'new' => $serverUidValidity,
                ]);
            }

            $state->uid_validity = $serverUidValidity;

            // FIRST-TIME WATERMARK: при первом подключении ящика мы НЕ хотим
            // ретроспективно затаскивать всю историю INBOX/Sent — это создавало
            // фантомные Request из старых писем (supplier-цепочки, уже
            // отработанные менеджером заявки и т.п.) + сотни LLM-вызовов на
            // классификацию. Семантика «начинаем читать с момента подключения».
            //
            // Определяем first-time по комбинации:
            //   - sync_count == 0  (ещё ни разу не синкались),
            //   - last_uid_seen == 0  (нет watermark'а от предыдущего синка),
            //   - state НЕ был только что переинициализирован UIDVALIDITY-сбросом
            //     (там тоже last_uid_seen=0, но это полный resync известного ящика,
            //     его обрабатываем штатно — а не watermark'ом).
            //
            // Старая история останется на IMAP-сервере, в email_messages не льётся.
            // Если нужно подтянуть конкретное письмо ретроспективно — отдельная
            // команда (mail:reingest-uid или ручной --mailbox=N --since-uid=K).
            $isFirstSync = ! $state->exists
                || ($state->sync_count === 0 && $state->last_uid_seen === 0);

            // first-sync ИЛИ смена UIDVALIDITY → ставим watermark на текущий max
            // и пропускаем историю (не тащим её в БД ретроспективно).
            if ($isFirstSync || $uidValidityChanged) {
                $allUids = (array) $folder->getClient()
                    ->getConnection()
                    ->getUid()
                    ->validatedData();
                $maxUid = ! empty($allUids) ? (int) max(array_map('intval', $allUids)) : 0;

                $state->last_uid_seen = $maxUid;
                $state->last_synced_at = now();
                $state->sync_count = (int) $state->sync_count + 1;
                $state->save();

                $mailbox->forceFill([
                    'last_synced_at' => now(),
                    'last_error_at' => null,
                    'last_error_message' => null,
                ])->save();

                Log::info('SyncMailboxFolderJob: watermark set, история пропущена', [
                    'mailbox_id' => $mailbox->id,
                    'folder' => $folder->path,
                    'watermark_uid' => $maxUid,
                    'reason' => $isFirstSync ? 'first_sync' : 'uidvalidity_changed',
                ]);

                return;
            }

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

        // ШАГ 1. Лёгкий запрос — забираем только список UIDs всей папки,
        // без headers/body/flags. Низкоуровневый вызов webklex
        // `$connection->getUid()` соответствует одной IMAP-команде
        // `UID FETCH 1:* (UID)` — несколько килобайт трафика для папки на
        // тысячи писем.
        //
        // Раньше тут было `whereAll()->limit(500)->get()` с setFetchBody(true) —
        // и оно пыталось вытащить 500 первых писем целиком за один FETCH.
        // Это:
        //   а) обрезало результат **по seq-number, а не UID**, поэтому при
        //      росте папки выше 500 писем новые UIDs (seq>500) переставали
        //      попадать в выборку и last_uid_seen замораживался;
        //   б) роняло worker по памяти/таймауту на больших ящиках без записи
        //      в production log (наблюдалось 2026-05-07 на mail@myzip.ru).
        $uidMap = (array) $folder->getClient()
            ->getConnection()
            ->getUid()
            ->validatedData();
        $allUids = array_values(array_map('intval', $uidMap));

        sort($allUids, SORT_NUMERIC);

        // Берём только новые относительно last_uid_seen. Если бэклог больше
        // лимита — берём САМЫЕ СТАРЫЕ MAX_MESSAGES_PER_RUN (голова, не хвост).
        //
        // КРИТИЧНО: раньше тут был `array_slice(-MAX)` (хвост = свежайшие). При
        // бэклоге >100 (догон после простоя воркера / резкий приток) джоб
        // забирал только новейшие 100, persist'ил их и двигал last_uid_seen на
        // MAX обработанного — а пропущенные СТАРЫЕ UID навсегда оставались ниже
        // watermark и больше не фетчились. Так была потеряна история Sent
        // (Dmitry.Rumiantsev: 15560 писем). Беря голову (oldest-first), мы
        // дренируем бэклог по 100 за заход (cron каждые 2 мин) без потерь:
        // watermark двигается только до максимума РЕАЛЬНО обработанной пачки,
        // остаток подберётся следующими заходами.
        $newUids = array_values(array_filter(
            $allUids,
            static fn (int $u): bool => $u >= $sinceUid,
        ));
        if (count($newUids) > self::MAX_MESSAGES_PER_RUN) {
            $newUids = array_slice($newUids, 0, self::MAX_MESSAGES_PER_RUN);
        }

        if (empty($newUids)) {
            return [
                'fetched' => 0,
                'saved' => 0,
                'skipped_dup' => 0,
                'max_uid' => 0,
            ];
        }

        // ШАГ 2. Per-UID fetch с body/headers/flags. Single numeric whereUid()
        // НЕ страдает от webklex-кавычек на ranges (это была проблема для
        // диапазонов вида '1:*'). Тянем по одному UID за раз — медленнее,
        // но не упирается в память и не падает целиком при битом письме.
        //
        // ВАЖНО: webklex 6.x WhereQuery не имеет метода `first()` — magic
        // __call трансформирует имена в `where(UPPERCASE, ...)`, и
        // `->whereUid($uid)->first()` падает с
        // «Method WhereQuery::whereFirst() is not supported». Поэтому
        // сначала `->get()` (это вернёт MessageCollection), а уже
        // на коллекции вызываем `->first()`.
        $connection = $folder->getClient()->getConnection();

        // POST-FIX применяем ТОЛЬКО для личных ящиков менеджеров. Логика:
        //   · Личный ящик: менеджер открывает Yandex и должен видеть письмо
        //     как новое (непрочитанное). Webklex body-fetch ставит \Seen
        //     как side-effect — это нужно откатить.
        //   · Общий ящик (mail@myzip.ru): MailFolderRouter::routeToManager
        //     явно ставит \Seen на оригинал в INBOX после COPY в MZ/Lastname,
        //     чтобы секретарь не видел «непрочитанный шум» от уже
        //     распределённых заявок. Webklex side-effect здесь как раз
        //     удобен — фиксирует \Seen ещё до явного setFlag, и попытка
        //     setFlag в routeToManager идёт уже на READ-WRITE сессии
        //     роутера (см. MailFolderRouter::routeToManager).
        //     Откатывать \Seen в общем ящике — значит сломать routing для
        //     секретаря: все письма копятся непрочитанными в INBOX
        //     независимо от того, маршрутизированы они в подпапку или нет.
        $applyUnreadFix = $mailbox->type === MailboxType::Personal;

        foreach ($newUids as $uid) {
            try {
                // PRE-FLIGHT: проверяем флаги ДО body-fetch'а. Webklex 6.x с
                // setFetchBody(true)+setFetchFlags(true) генерирует FETCH BODY[]
                // (а не BODY.PEEK[]) — даже при FT_PEEK сервер выставляет
                // \Seen на стороне сервера. Если письмо было непрочитанным
                // и это ЛИЧНЫЙ ящик — откатим \Seen после fetch'а (POST-FIX).
                $wasUnread = false;
                if ($applyUnreadFix) {
                    try {
                        $flagsBefore = (array) $connection->getFlags($uid)->validatedData();
                        // flagsBefore приходит как [$uid => ['\Seen', ...]] или [].
                        $flagsList = is_array($flagsBefore[$uid] ?? null) ? $flagsBefore[$uid] : [];
                        $wasUnread = ! in_array('\\Seen', $flagsList, true)
                            && ! in_array('Seen', $flagsList, true);
                    } catch (\Throwable $flagsErr) {
                        // Лог и продолжаем — будем считать «было прочитано»
                        // (безопасный default: не трогаем флаги).
                        Log::debug('SyncMailboxFolderJob: pre-flight flags fetch failed', [
                            'mailbox_id' => $mailbox->id,
                            'uid' => $uid,
                            'error' => $flagsErr->getMessage(),
                        ]);
                    }
                }

                $msg = $folder->query()
                    ->setFetchOptions(IMAP::FT_PEEK)
                    ->setFetchBody(true)
                    ->setFetchFlags(true)
                    ->whereUid($uid)
                    ->get()
                    ->first();

                if (! $msg) {
                    // UID мог исчезнуть между getUids() и FETCH (EXPUNGE на сервере).
                    continue;
                }

                // POST-FIX: только для личных ящиков. На общих не трогаем флаги —
                // там routeToManager сам решит, что должно быть \Seen.
                // ВАЖНО: 6-й параметр Connection::store — это флаг режима UID/MSGN
                // (IMAP::ST_UID=1 по умолчанию). Раньше передавали null → команда
                // уходила как обычный STORE по sequence number, а не UID STORE.
                // Это могло приводить к попаданию на чужое письмо или silent-no-op.
                if ($applyUnreadFix && $wasUnread) {
                    try {
                        $connection->store(['\\Seen'], $uid, $uid, '-', true, IMAP::ST_UID);
                    } catch (\Throwable $unseenErr) {
                        Log::debug('SyncMailboxFolderJob: failed to restore unread flag', [
                            'mailbox_id' => $mailbox->id,
                            'uid' => $uid,
                            'error' => $unseenErr->getMessage(),
                        ]);
                    }
                }

                $fetched++;
                $maxUid = max($maxUid, $uid);

                // Каноникализируем имя Sent-папки к 'Sent' (как пишет
                // EmailDraftService/OutgoingMailSender), иначе сырой MUTF-7
                // путь Yandex (&BB4...-, «Отправленные») не совпадёт с дедуп-
                // ключом (mailbox_id, folder, message_id) и Sent-sync создаст
                // дубль нашего же исходящего письма вместо обновления imap_uid
                // в существующей записи (MessagePersister Phase 1.9).
                $persistFolder = $this->folderType === 'sent' ? 'Sent' : $folder->path;

                $email = $persister->persist($msg, $mailbox, $persistFolder, $direction);
                if ($email === null) {
                    $skippedDup++;
                    continue;
                }

                $saved++;

                // Phase 1.5: применить правила маршрутизации к свежесохранённому
                // inbound-письму. Outbound (Sent) пропускаются внутри MailRouter.
                try {
                    app(\App\Services\Mail\MailRouter::class)->route($email);
                } catch (\Throwable $routeError) {
                    Log::error('MailRouter failed', [
                        'email_message_id' => $email->id,
                        'error' => $routeError->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to persist message', [
                    'mailbox_id' => $mailbox->id,
                    'folder' => $folder->path,
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
                // Не валим весь job — двигаем maxUid, чтобы битый UID не залип
                // навсегда, и продолжаем со следующим письмом.
                $maxUid = max($maxUid, $uid);
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
