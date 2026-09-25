<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\MailboxFolderState;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;

/**
 * Зеркало истории личных ящиков менеджеров (заказчик, 2026-09-26: «полная
 * синхронизация ящиков, в т.ч. папок»).
 *
 * Живой синк (SyncMailboxFolderJob) забирает письма, пришедшие после
 * подключения ящика, и прогоняет их через конвейер. Всё, что лежало раньше
 * (ниже водяного знака INBOX/Sent), и всё, что живёт в пользовательских
 * папках Яндекса, заводится здесь — шапкой, без тела:
 *   - письмо видно в списке, в счётчиках, в поиске по теме и отправителю;
 *   - тело и вложения скачиваются при первом открытии (fetchBody);
 *   - is_history = true: конвейер, заявки, детекторы и отчёты его не видят
 *     (ExcludeMailHistoryScope).
 * Прочитанность и флаг берутся с сервера — это правда владельца ящика.
 *
 * Идём от свежих писем к старым: польза видна сразу, а прерванный проход
 * продолжается с history_low_uid.
 */
class MailHistoryMirrorService
{
    /** Поля шапки для FETCH: всё, что нужно строке списка и треду. */
    private const HEADER_ITEM = 'BODY.PEEK[HEADER.FIELDS (MESSAGE-ID FROM TO CC SUBJECT DATE IN-REPLY-TO REFERENCES CONTENT-TYPE)]';

    /** Писем в одной IMAP-команде: 500 шапок старых писем Яндекс отдаёт за ~40–50 с. */
    private const FETCH_CHUNK = 500;

    /** Шард текущего прогона: [k, n] — только UID с uid % n = k. */
    private ?array $shard = null;

    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly MessagePersister $persister,
        private readonly ImapFolderSyncService $folderSync,
    ) {}

    /**
     * Ящики, чья история зеркалится: личные ящики сотрудников, которые синкает
     * mzCorp (Mailbox::syncable — роли менеджеров, РОП, закупки). Ящик
     * директора и общие ящики — нет.
     *
     * @return Collection<int, Mailbox>
     */
    public function mailboxes(): Collection
    {
        return Mailbox::query()->syncable()
            ->where('type', MailboxType::Personal->value)
            ->whereNotNull('owner_user_id')
            ->orderBy('id')
            ->get();
    }

    public function isMirrored(Mailbox $mailbox): bool
    {
        return $mailbox->type === MailboxType::Personal
            && $mailbox->owner_user_id !== null
            && Mailbox::query()->syncable()->whereKey($mailbox->id)->exists();
    }

    /**
     * Один проход по ящику: по каждой папке заводим недостающие письма, пока
     * не выйдет лимит писем или времени (0 — без ограничения).
     *
     * Первичный проход по миллиону писем идёт в несколько процессов: по
     * группам папок ($only: inbox | sent | folders) и по остатку UID ($shard
     * [k, n] — свои UID, где uid % n = k). Яндекс отдаёт ~10 шапок старых
     * писем в секунду на соединение, параллельные соединения складываются.
     *
     * @param  callable(string $folder, int $done, int $todo):void|null  $progress
     * @param  array{0:int, 1:int}|null  $shard
     * @return array{imported:int, rehomed:int, uid_filled:int, folders:array<string, array{todo:int, imported:int}>}
     */
    public function run(Mailbox $mailbox, int $budget = 0, int $seconds = 0, ?callable $progress = null, ?string $only = null, ?array $shard = null): array
    {
        $this->shard = $shard !== null && $shard[1] > 1 ? $shard : null;
        $stats = ['imported' => 0, 'rehomed' => 0, 'uid_filled' => 0, 'missing' => 0, 'folders' => []];
        if (! $this->isMirrored($mailbox)) {
            return $stats;
        }

        $deadline = $seconds > 0 ? microtime(true) + $seconds : null;
        $client = $this->connector->imapClient($mailbox);
        try {
            foreach ($this->folders($mailbox, $client) as $f) {
                $group = $f['folder_id'] !== null ? 'folders' : ($f['db'] === 'Sent' ? 'sent' : 'inbox');
                if ($only !== null && $only !== $group) {
                    continue;
                }
                if (($budget > 0 && $stats['imported'] >= $budget) || ($deadline && microtime(true) > $deadline)) {
                    break;
                }
                try {
                    $res = $this->mirrorFolder($mailbox, $client, $f, $budget > 0 ? $budget - $stats['imported'] : 0, $deadline, $progress);
                } catch (\Throwable $e) {
                    Log::warning('MailHistoryMirror: folder failed', [
                        'mailbox_id' => $mailbox->id, 'folder' => $f['db'], 'error' => $e->getMessage(),
                    ]);

                    continue;
                }
                $stats['imported'] += $res['imported'];
                $stats['rehomed'] += $res['rehomed'];
                $stats['uid_filled'] += $res['uid_filled'];
                $stats['missing'] += $res['missing'] ?? 0;
                $stats['folders'][$f['db']] = ['todo' => $res['todo'], 'imported' => $res['imported']];
            }
        } finally {
            $client->disconnect();
        }

        return $stats;
    }

    /**
     * Папки ящика для зеркала: INBOX и «Отправленные» — до водяного знака
     * живого синка (выше него письма приходят через конвейер), пользовательские
     * папки Яндекса — целиком (их письма конвейер не видит вовсе).
     *
     * @return list<array{server:string, db:string, direction:?MailDirection, folder_id:?int, bound:?int}>
     */
    private function folders(Mailbox $mailbox, Client $client): array
    {
        $out = [];
        $states = MailboxFolderState::query()->where('mailbox_id', $mailbox->id)->get()->keyBy('folder');

        // Без водяного знака живой синк ещё не начинал — историю не трогаем,
        // иначе граница «история / новые письма» не определена.
        if (($st = $states->get('INBOX')) && (int) $st->last_uid_seen > 0) {
            $out[] = ['server' => 'INBOX', 'db' => 'INBOX', 'direction' => MailDirection::Inbound, 'folder_id' => null, 'bound' => (int) $st->last_uid_seen];
        }
        try {
            $sent = $this->connector->findSent($client)->path;
            $st = $states->get($sent) ?? $states->get('Sent');
            if ($st && (int) $st->last_uid_seen > 0) {
                $out[] = ['server' => $sent, 'db' => 'Sent', 'direction' => MailDirection::Outbound, 'folder_id' => null, 'bound' => (int) $st->last_uid_seen];
            }
        } catch (\Throwable) {
            // нет «Отправленных» — пропускаем
        }

        foreach (MailboxFolder::query()->where('mailbox_id', $mailbox->id)
            ->whereNotNull('imap_path')->whereNotNull('imap_synced_at')->orderBy('id')->get() as $folder) {
            $out[] = ['server' => $folder->imap_path, 'db' => $folder->imap_path, 'direction' => null, 'folder_id' => $folder->id, 'bound' => null];
        }

        return $out;
    }

    /**
     * @param  array{server:string, db:string, direction:?MailDirection, folder_id:?int, bound:?int}  $f
     * @return array{todo:int, imported:int, rehomed:int, uid_filled:int, missing:int}
     */
    private function mirrorFolder(Mailbox $mailbox, Client $client, array $f, int $budget, ?float $deadline, ?callable $progress): array
    {
        $res = ['todo' => 0, 'imported' => 0, 'rehomed' => 0, 'uid_filled' => 0, 'missing' => 0];
        $conn = $client->getConnection();
        $status = (array) $client->openFolder($f['server'], force_select: true);
        $validity = (int) ($status['uidvalidity'] ?? 0);

        $state = MailboxFolderState::query()->firstOrNew(['mailbox_id' => $mailbox->id, 'folder' => $f['server']]);

        // История INBOX/Sent ограничена водяным знаком и после прохода не
        // растёт: всё выше знака приходит живым синком.
        if ($f['bound'] !== null && $state->history_completed_at !== null) {
            return $res;
        }

        // Быстрый выход: на сервере писем не больше, чем у нас в этой папке.
        $exists = (int) ($status['exists'] ?? 0);
        $known = EmailMessage::withHistory()
            ->where('mailbox_id', $mailbox->id)->where('folder', $f['db'])->whereNotNull('imap_uid')
            ->pluck('imap_uid')->map(fn ($u) => (int) $u)->flip()->all();
        if ($f['bound'] === null && $exists <= count($known) && $state->history_completed_at !== null) {
            return $res;
        }

        $serverUids = array_map('intval', (array) $conn->getUid()->validatedData());
        $shard = $this->shard;
        $todo = array_values(array_filter($serverUids, fn (int $u) => ! isset($known[$u])
            && ($f['bound'] === null || $u <= $f['bound'])
            && ($shard === null || $u % $shard[1] === $shard[0])));
        rsort($todo); // сначала свежие
        $res['todo'] = count($todo);

        // Шард видит только свою долю: «папка пройдена» отметит обычный прогон.
        if ($todo === []) {
            if ($shard === null && ($state->exists || $f['folder_id'] !== null)) {
                $state->forceFill(['history_completed_at' => now(), 'uid_validity' => $state->uid_validity ?? $validity])->save();
            }

            return $res;
        }
        if ($budget > 0) {
            $todo = array_slice($todo, 0, $budget);
        }

        $done = 0;
        foreach (array_chunk($todo, self::FETCH_CHUNK) as $chunk) {
            if ($deadline && microtime(true) > $deadline) {
                break;
            }
            $part = $this->importChunkWithRetry($mailbox, $client, $f, $chunk, $validity);
            foreach ($part as $k => $v) {
                $res[$k] = ($res[$k] ?? 0) + $v;
            }
            $done += count($chunk);

            // Несколько процессов пишут в одну строку состояния — атомарно.
            if (! $state->exists) {
                $state->forceFill(['uid_validity' => $validity])->save();
            }
            MailboxFolderState::query()->whereKey($state->id)->update([
                'history_low_uid' => DB::raw('LEAST(COALESCE(history_low_uid, '.(int) min($chunk).'), '.(int) min($chunk).')'),
                'history_imported' => DB::raw('history_imported + '.(int) $part['imported']),
            ]);
            if ($progress) {
                $progress($f['db'], $done, $res['todo']);
            }
        }

        // Папка пройдена, только если Яндекс отдал все письма: недополученные
        // доберёт следующий прогон (они не заведены — попадут в todo снова).
        if ($shard === null && $done >= $res['todo'] && ($res['missing'] ?? 0) === 0) {
            $state->forceFill(['history_completed_at' => now()])->save();
        }

        return $res;
    }

    /**
     * Пачка с повторами. Под нагрузкой (несколько соединений к одному
     * аккаунту) Яндекс отвечает пустым или обрезанным FETCH — webklex то
     * молча отдаёт меньше строк, то падает («empty response», «Uninitialized
     * string offset»). Недополученные UID запрашиваем снова, при сбое —
     * переподключаемся; что не удалось за 4 попытки, остаётся на следующий
     * прогон. Отступ растёт (5–10–20 с): при долгой выборке Яндекс придерживает
     * и одиночное соединение, и короткие паузы окно не перекрывали — у
     * Агрызкова и Якубовича так недополучили по ~40 тыс. писем.
     *
     * @param  list<int>  $uids
     * @return array{imported:int, rehomed:int, uid_filled:int, missing:int}
     */
    private function importChunkWithRetry(Mailbox $mailbox, Client $client, array $f, array $uids, int $validity): array
    {
        $out = ['imported' => 0, 'rehomed' => 0, 'uid_filled' => 0, 'missing' => 0];
        $left = $uids;
        for ($attempt = 1; $attempt <= 4 && $left !== []; $attempt++) {
            try {
                $part = $this->importChunk($mailbox, $client, $f, $left, $validity);
            } catch (\Throwable $e) {
                Log::info('MailHistoryMirror: chunk failed, reconnecting', [
                    'mailbox_id' => $mailbox->id, 'folder' => $f['db'], 'uids' => count($left),
                    'attempt' => $attempt, 'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
                sleep(5 * 2 ** ($attempt - 1));
                $this->reconnect($client, $f['server']);

                continue;
            }
            $out['imported'] += $part['imported'];
            $out['rehomed'] += $part['rehomed'];
            $out['uid_filled'] += $part['uid_filled'];
            $left = $part['missing'];
            if ($left !== []) {
                sleep(5 * 2 ** ($attempt - 1));
            }
        }
        $out['missing'] = count($left);

        return $out;
    }

    private function reconnect(Client $client, string $path): void
    {
        try {
            $client->disconnect();
        } catch (\Throwable) {
            // соединение уже мертво
        }
        try {
            $client->connect();
            $client->openFolder($path, force_select: true);
        } catch (\Throwable $e) {
            Log::warning('MailHistoryMirror: reconnect failed', ['folder' => $path, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * Шапки пачки UID → строки истории. Письмо, которое у нас уже есть под
     * тем же Message-ID, не задваиваем: в пользовательской папке переселяем
     * (как ImapFolderSyncService), в INBOX/Sent — проставляем UID.
     *
     * @param  list<int>  $uids
     * @return array{imported:int, rehomed:int, uid_filled:int, missing:list<int>} missing — UID, которых нет в ответе сервера
     */
    private function importChunk(Mailbox $mailbox, Client $client, array $f, array $uids, int $validity): array
    {
        $out = ['imported' => 0, 'rehomed' => 0, 'uid_filled' => 0, 'missing' => []];
        $rows = (array) $client->getConnection()
            ->fetch(['UID', 'FLAGS', self::HEADER_ITEM], $uids, null, IMAP::ST_UID)
            ->data();
        $config = $client->getConfig();
        $ownerEmail = mb_strtolower((string) $mailbox->email);

        $parsed = [];
        foreach ($rows as $uid => $data) {
            $raw = self::headerText((array) $data);
            if ($raw === null) {
                continue;
            }
            $parsed[(int) $uid] = [
                'raw' => $raw,
                'flags' => array_values(array_map(fn ($fl) => ltrim((string) $fl, '\\'), (array) ($data['FLAGS'] ?? []))),
                'mid' => ImapFolderSyncService::messageIdFromHeaders($raw),
            ];
        }
        $out['missing'] = array_values(array_diff($uids, array_keys($parsed)));

        // Уже известные письма пачки — одним запросом (живой синк, доставка
        // копии, прошлый проход): по одному на письмо миллион шапок не пройти.
        $existingByMid = $this->findByMessageIds($mailbox, array_values(array_filter(array_column($parsed, 'mid'))));

        $insert = [];
        $flagsByUid = [];
        foreach ($parsed as $uid => $p) {
            $flags = $p['flags'];
            $mid = $p['mid'];

            $existing = $mid !== null ? ($existingByMid[mb_strtolower($mid)] ?? null) : null;
            if ($existing !== null) {
                if ($f['folder_id'] !== null) {
                    if ($existing->folder !== $f['db']) {
                        if ($this->folderSync->rehome($existing, $f['db'], $uid, $f['folder_id'])) {
                            $out['rehomed']++;
                        }
                    } elseif ($existing->imap_uid === null) {
                        // Письмо уже в этой папке, но связь с сервером потеряна:
                        // клиент прячет такие входящие как удалённые. У
                        // Агрызкова так пропали 6 тыс. писем «Входящих
                        // (локально)» — разовый проход 08.09 остановили на 29k.
                        $existing->forceFill(['imap_uid' => $uid, 'mailbox_folder_id' => $f['folder_id']])->saveQuietly();
                        $out['uid_filled']++;
                    }
                } elseif ($existing->folder === $f['db'] && $existing->imap_uid === null) {
                    $existing->forceFill(['imap_uid' => $uid])->saveQuietly();
                    $out['uid_filled']++;
                }

                continue;
            }

            try {
                $insert[] = self::quietly(function () use ($p, $config, $flags, $f, $ownerEmail, $mailbox, $uid, $validity) {
                    $env = new HeaderEnvelope(new Header($p['raw'], $config), $flags);
                    $direction = $f['direction'] ?? $this->directionFor($env, $ownerEmail);

                    return $this->persister->historyRow(
                        $env, $mailbox, $f['db'], $uid, $direction, $f['folder_id'],
                        sprintf('hist-%d-%d-%d@mzcorp', $mailbox->id, $validity, $uid),
                    );
                });
                $flagsByUid[$uid] = $flags;
            } catch (\Throwable $e) {
                // Одна кривая шапка не должна ронять пачку из 500 писем, а
                // повтор её не вылечит — заводим письмо по-простому: тема и
                // отправитель регуляркой, текст подтянется при открытии.
                Log::info('MailHistoryMirror: header parsed by fallback', [
                    'mailbox_id' => $mailbox->id, 'folder' => $f['db'], 'uid' => $uid, 'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
                $insert[] = $this->fallbackRow($p, $mailbox, $f, $uid, $validity, $ownerEmail);
                $flagsByUid[$uid] = $flags;
            }
        }

        if ($insert !== []) {
            $out['imported'] = DB::table('email_messages')->insertOrIgnore($insert);
            $this->applyReadState($mailbox, $f['db'], $flagsByUid);
        }

        return $out;
    }

    /**
     * Прочитанность и флаг с сервера — состояние владельца ящика (как у
     * ImapSeenSyncService): отсутствие строки = непрочитано.
     *
     * @param  array<int, list<string>>  $flagsByUid
     */
    private function applyReadState(Mailbox $mailbox, string $folder, array $flagsByUid): void
    {
        $marked = array_filter($flagsByUid, fn ($fl) => in_array('Seen', $fl, true) || in_array('Flagged', $fl, true));
        if ($marked === [] || ! $mailbox->owner_user_id) {
            return;
        }
        $rows = DB::table('email_messages')
            ->where('mailbox_id', $mailbox->id)->where('folder', $folder)->where('is_history', true)
            ->whereIn('imap_uid', array_keys($marked))
            ->get(['id', 'imap_uid', 'sent_at']);

        $now = now();
        $states = [];
        foreach ($rows as $r) {
            $fl = $marked[(int) $r->imap_uid] ?? [];
            $states[] = [
                'email_message_id' => $r->id,
                'user_id' => (int) $mailbox->owner_user_id,
                'read_at' => in_array('Seen', $fl, true) ? ($r->sent_at ?? $now) : null,
                'flagged_at' => in_array('Flagged', $fl, true) ? ($r->sent_at ?? $now) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($states, 1000) as $chunk) {
            DB::table('email_message_user_states')->insertOrIgnore($chunk);
        }
    }

    /**
     * Скачать тело и вложения письма из истории при первом открытии. \Seen на
     * сервере не меняем: тело (RFC822.TEXT) Яндекс отдаёт с установкой флага,
     * webklex с FT_PEEK снимает его обратно у непрочитанного письма —
     * прочитанным его делает владелец, открыв письмо (MailReadService).
     *
     * @return bool false — письма на сервере уже нет (удалено / перенесено)
     */
    public function fetchBody(EmailMessage $row): bool
    {
        if (! $row->needsBodyFetch()) {
            return true;
        }
        if ($row->imap_uid === null) {
            return false;
        }
        $mailbox = Mailbox::query()->find($row->mailbox_id);
        if (! $mailbox) {
            return false;
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $folder = $row->folder === 'Sent' ? $this->connector->findSent($client) : $client->getFolderByPath($row->folder);
            if (! $folder) {
                return false;
            }
            // FT_PEEK: webklex читает флаги до тела и, если письмо было
            // непрочитанным, снимает \Seen после (Message::peek()).
            $msg = $folder->query()
                ->setFetchOptions(IMAP::FT_PEEK)
                ->setFetchBody(true)
                ->setFetchFlags(true)
                ->whereUid((int) $row->imap_uid)
                ->get()
                ->first();
            if (! $msg) {
                return false;
            }

            $this->persister->hydrateBody($row, $msg);

            return true;
        } catch (\Throwable $e) {
            Log::warning('MailHistoryMirror: body fetch failed', [
                'email_message_id' => $row->id, 'mailbox_id' => $row->mailbox_id, 'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $client?->disconnect();
        }
    }

    /**
     * Строка истории, когда webklex не разобрал шапку: Message-ID, тема,
     * отправитель и дата — простым разбором, без получателей.
     *
     * @param  array{raw:string, flags:list<string>, mid:?string}  $p
     * @return array<string, mixed>
     */
    private function fallbackRow(array $p, Mailbox $mailbox, array $f, int $uid, int $validity, string $ownerEmail): array
    {
        $raw = preg_replace('/\r?\n[ \t]+/', ' ', $p['raw']) ?? $p['raw'];
        $field = fn (string $name) => preg_match('/^'.$name.':\s*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
        $decode = fn (string $v) => (string) (@iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $v);
        $fromRaw = $field('From');
        $fromEmail = preg_match('/<([^>]+@[^>]+)>/', $fromRaw, $m) ? $m[1] : (preg_match('/[\w.+-]+@[\w.-]+/', $fromRaw, $m) ? $m[0] : '');
        $date = null;
        try {
            $date = $field('Date') !== '' ? Carbon::parse($field('Date'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
        }
        $now = now();
        $outbound = $f['direction'] === MailDirection::Outbound
            || ($f['direction'] === null && mb_strtolower($fromEmail) === $ownerEmail);

        return [
            'mailbox_id' => $mailbox->id,
            'folder' => $f['db'],
            'mailbox_folder_id' => $f['folder_id'],
            'direction' => ($outbound ? MailDirection::Outbound : MailDirection::Inbound)->value,
            'imap_uid' => $uid,
            'message_id' => $p['mid'] ?? sprintf('hist-%d-%d-%d@mzcorp', $mailbox->id, $validity, $uid),
            'in_reply_to' => null,
            'references_header' => null,
            'subject' => mb_substr($decode($field('Subject')), 0, 998),
            'from_email' => mb_substr($fromEmail, 0, 255),
            'from_name' => ($n = trim($decode((string) preg_replace('/<[^>]*>/', '', $fromRaw)), " \t\"")) !== '' ? mb_substr($n, 0, 255) : null,
            'to_recipients' => null,
            'cc_recipients' => null,
            'sent_at' => $date,
            'imap_flags' => json_encode($p['flags']),
            'is_draft' => false,
            'is_history' => true,
            'history_has_attachments' => str_contains(mb_strtolower($field('Content-Type')), 'multipart/mixed'),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Разбор шапки без предупреждений PHP. Декодер webklex на имени адресата
     * с обратным слэшем («Запорожец Павел \ Pavel Zaporozhets») зовёт
     * property_exists('') — автозагрузчик Composer пишет «Uninitialized string
     * offset 0», Laravel превращает это в исключение, и падала вся пачка из
     * 500 писем — на каждой попытке заново. Само предупреждение безвредно.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    private static function quietly(callable $fn): mixed
    {
        set_error_handler(fn (int $no) => in_array($no, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true));
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** Текст шапки из ответа FETCH: webklex кладёт его последним элементом «BODY[HEADER.FIELDS». */
    private static function headerText(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (! str_starts_with((string) $key, 'BODY[HEADER')) {
                continue;
            }
            $text = is_array($value) ? end($value) : $value;

            return is_string($text) && trim($text) !== '' ? $text : null;
        }

        return null;
    }

    /** В пользовательской папке лежат и входящие, и отправленные: наше — исходящее. */
    private function directionFor(HeaderEnvelope $env, string $ownerEmail): MailDirection
    {
        $from = '';
        foreach ((array) ($env->getFrom()?->toArray() ?? []) as $a) {
            $from = mb_strtolower((string) ($a->mail ?? ''));
            break;
        }

        return $from !== '' && $from === $ownerEmail ? MailDirection::Outbound : MailDirection::Inbound;
    }

    /**
     * Письма ящика по Message-ID: lower(mid) => письмо (сначала лежащее в
     * INBOX/Sent, как ImapFolderSyncService::findByMessageId). Индекс
     * email_messages_mailbox_lower_mid_idx.
     *
     * @param  list<string>  $mids
     * @return array<string, EmailMessage>
     */
    private function findByMessageIds(Mailbox $mailbox, array $mids): array
    {
        $lower = array_values(array_unique(array_map('mb_strtolower', $mids)));
        if ($lower === []) {
            return [];
        }
        $out = [];
        $rows = EmailMessage::withHistory()
            ->where('mailbox_id', $mailbox->id)
            ->where('is_draft', false)
            ->whereIn(DB::raw('lower(message_id)'), $lower)
            ->orderByRaw("CASE folder WHEN 'INBOX' THEN 0 WHEN 'Sent' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get(['id', 'mailbox_id', 'folder', 'imap_uid', 'mailbox_folder_id', 'message_id']);
        foreach ($rows as $r) {
            $out[mb_strtolower((string) $r->message_id)] ??= $r;
        }

        return $out;
    }
}
