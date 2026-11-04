<?php

namespace App\Services\Retro;

use App\Enums\DetectorType;
use App\Enums\EmailCategory;
use App\Enums\InvoiceStatus;
use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\OutboundQuote;
use App\Services\Calendar\RussianWorkingDayService;
use App\Services\Invoices\InvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Запись результата RetroAssembler в базу. Только прямые вставки: модельные
 * наблюдатели, очереди и уведомления здесь не срабатывают, поэтому загрузка
 * не может ни отправить письмо, ни разбудить конвейер.
 */
class RetroWriter
{
    private const EVENT = 'retro_restore';

    private const CHUNK = 500;

    public const COMMENT = 'Восстановлено из почты после потери базы 02.11.2026';

    public const CLOSE_COMMENT = 'Восстановлено из почты: нет активности, причина закрытия предположительная';

    /**
     * @param  array<string, array<int, true>>  $unseenByBox  Ящик → папка → непрочитанные UID.
     * @return array<string, int>
     */
    public function write(array $result, array $unseenByBox, int $invoiceValidityDays): array
    {
        $rows = $result['rows'];
        $now = now()->format('Y-m-d H:i:s');
        $mailboxes = Mailbox::query()->get(['id', 'email', 'type', 'owner_user_id'])->keyBy(fn ($m) => mb_strtolower($m->email));
        $users = DB::table('users')->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [mb_strtolower($email) => $id])->all();
        $folderIds = $this->personalFolders($rows, $mailboxes);

        $requestIds = $this->insertRequests($result['requests'], $users, $now);
        $messageIds = $this->insertMessages($result['requests'], $rows, $requestIds, $mailboxes, $folderIds, $now);

        $counts = [
            'requests' => count($requestIds),
            'messages' => count($messageIds),
            'assignments' => 0,
            'state_changes' => 0,
            'quotes' => 0,
            'invoices' => 0,
        ];

        $assignments = [];
        $changes = [];
        $quotes = [];
        $invoices = [];
        foreach ($result['requests'] as $number => $plan) {
            $id = $requestIds[$plan['code']] ?? null;
            if ($id === null) {
                continue;
            }
            $message = $messageIds[$plan['first_message']] ?? null;
            if ($message !== null) {
                DB::table('requests')->where('id', $id)->update(['email_message_id' => $message]);
            }
            foreach ($this->assignmentRows($plan, $id, $users, $now) as $row) {
                $assignments[] = $row;
            }
            foreach ($this->stateRows($plan, $id, $now) as $row) {
                $changes[] = $row;
            }
            foreach ($plan['quotes'] as $doc) {
                if (isset($messageIds[$doc['i']])) {
                    $quotes[] = $this->quoteRow($doc, $id, $messageIds[$doc['i']], $now);
                }
            }
            foreach ($plan['invoices'] as $doc) {
                $invoices[] = $this->invoiceRow($doc, $id, $messageIds[$doc['i']] ?? null, $users, $invoiceValidityDays, $now);
                // Как в живом потоке: файл счёта и его реквизиты хранит OutboundQuote, Invoice — учёт и оплату.
                if (isset($messageIds[$doc['i']])) {
                    $quotes[] = $this->quoteRow($doc, $id, $messageIds[$doc['i']], $now, DetectorType::OutboundInvoice);
                }
            }
        }
        foreach ([
            ['request_assignments', $assignments, 'assignments'],
            ['request_state_changes', $changes, 'state_changes'],
            ['outbound_quotes', $quotes, 'quotes'],
            ['invoices', $invoices, 'invoices'],
        ] as [$table, $data, $key]) {
            foreach (array_chunk($data, self::CHUNK) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            $counts[$key] = count($data);
        }

        $counts['read_states'] = $this->insertReadStates($rows, $messageIds, $mailboxes, $unseenByBox, $now);

        return $counts;
    }

    /** Папки личных ящиков: письма в них должны быть видны в разделе «Почта» там же, где на сервере. */
    private function personalFolders(array $rows, $mailboxes): array
    {
        $paths = [];
        foreach ($rows as $row) {
            if ($row['number'] !== null && ! in_array($row['folder'], ['INBOX', 'Sent'], true)) {
                $paths[$row['box']][$row['folder']] = true;
            }
        }
        $out = [];
        foreach ($paths as $box => $folders) {
            $mailbox = $mailboxes[mb_strtolower($box)] ?? null;
            if ($mailbox === null || $mailbox->type !== MailboxType::Personal) {
                continue;
            }
            foreach (array_keys($folders) as $path) {
                $folder = MailboxFolder::query()->firstOrCreate(
                    ['mailbox_id' => $mailbox->id, 'imap_path' => (string) $path],
                    ['name' => MailboxFolder::displayNameFromImapPath((string) $path, '|'), 'imap_synced_at' => now()],
                );
                $out[$box][$path] = $folder->id;
            }
        }

        return $out;
    }

    private function insertRequests(array $plans, array $users, string $now): array
    {
        $rows = [];
        foreach ($plans as $plan) {
            $assigned = $plan['assigned'] !== null ? ($users[mb_strtolower($plan['assigned'])] ?? null) : null;
            $closed = $plan['closed'];
            $rows[] = [
                'internal_code' => $plan['code'],
                'assigned_user_id' => $assigned,
                'assigned_at' => $assigned !== null ? $this->date($plan['created_ts']) : null,
                'status' => $plan['status']->value,
                'peak_status' => $plan['peak']?->value,
                'client_email' => $this->text($plan['client_email'] ?? '', 255),
                'client_name' => $plan['client_name'] !== null ? $this->text($plan['client_name'], 255) : null,
                'subject' => $this->text($plan['subject'], 998),
                'onec_number' => $plan['onec_number'],
                'last_activity_at' => $this->date($plan['last_ts']),
                'last_activity_type' => $plan['last_type']->value,
                'closed_at' => $closed !== null ? $this->date($closed['ts']) : null,
                'closed_lost_reason' => $closed !== null ? $closed['reason']->value : null,
                'closed_lost_comment' => $closed !== null ? self::CLOSE_COMMENT : null,
                'parsing_meta' => json_encode(['retro' => true, 'parser_finished_at' => $now]),
                'created_at' => $this->date($plan['created_ts']),
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('requests')->insert($chunk);
        }

        return DB::table('requests')->pluck('id', 'internal_code')->all();
    }

    /** @return array<int, int> Позиция письма в индексе → id строки. */
    private function insertMessages(array $plans, array $rows, array $requestIds, $mailboxes, array $folderIds, string $now): array
    {
        $primary = [];
        $copies = [];
        foreach ($plans as $plan) {
            foreach ($plan['messages'] as $m) {
                if ($m['copy_of'] === null) {
                    $primary[] = $m + ['request' => $requestIds[$plan['code']]];
                } else {
                    $copies[] = $m + ['request' => $requestIds[$plan['code']]];
                }
            }
        }

        $firstOfRequest = [];
        foreach ($plans as $plan) {
            $firstOfRequest[$plan['first_message']] = true;
        }

        $build = function (array $m, ?int $copyOf) use ($rows, $mailboxes, $folderIds, $now, $firstOfRequest): ?array {
            $row = $rows[$m['i']];
            $mailbox = $mailboxes[mb_strtolower($row['box'])] ?? null;
            if ($mailbox === null) {
                return null;
            }
            $artifacts = ['retro' => ['attachments' => array_slice($row['names'], 0, 50)]];
            if ($copyOf !== null) {
                $artifacts['cross_mailbox_copy_of'] = $copyOf;
            }

            return [
                'mailbox_id' => $mailbox->id,
                'folder' => $this->text($row['folder'], 255),
                'mailbox_folder_id' => $folderIds[$row['box']][$row['folder']] ?? null,
                'direction' => $row['outbound'] ? 'outbound' : 'inbound',
                'imap_uid' => $row['uid'],
                'message_id' => $this->text($row['mid'] ?? sprintf('retro-%d-%s-%d@mzcorp', $mailbox->id, md5($row['folder']), $row['uid']), 998),
                'in_reply_to' => $row['in_reply_to'] !== null ? $this->text($row['in_reply_to'], 998) : null,
                'references_header' => $row['references'] !== [] ? json_encode(array_map(fn ($id) => $this->text($id, 998), $row['references']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'subject' => $this->text($row['subject'], 998),
                'from_email' => $this->text($row['from_email'], 255),
                'from_name' => $row['from_name'] !== null ? $this->text($row['from_name'], 255) : null,
                'to_recipients' => $this->recipients($row['to']),
                'cc_recipients' => $this->recipients($row['cc']),
                'sent_at' => $row['ts'] !== null ? $this->date($row['ts']) : null,
                'related_request_id' => $m['request'],
                // Отметки «уже обработано»: кроны категоризации и намерений не должны отдать старое письмо в GPT.
                'category' => $this->category($row, $m, $firstOfRequest),
                'categorized_at' => $now,
                'classified_at' => $now,
                'intent_classified_at' => $now,
                'detected_artifacts' => json_encode($artifacts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'history_has_attachments' => array_key_exists('names', $row) && $row['outbound'] ? $row['names'] !== [] : null,
                'is_draft' => false,
                'is_history' => false,
                'created_at' => $row['ts'] !== null ? $this->date($row['ts']) : $now,
                'updated_at' => $now,
            ];
        };

        foreach (array_chunk($primary, self::CHUNK) as $chunk) {
            DB::table('email_messages')->insertOrIgnore(array_values(array_filter(array_map(fn ($m) => $build($m, null), $chunk))));
        }
        $ids = $this->messageIds($rows, $mailboxes);

        foreach (array_chunk($copies, self::CHUNK) as $chunk) {
            DB::table('email_messages')->insertOrIgnore(array_values(array_filter(
                array_map(fn ($m) => $build($m, $ids[$m['copy_of']] ?? null), $chunk)
            )));
        }

        return $this->messageIds($rows, $mailboxes);
    }

    private function messageIds(array $rows, $mailboxes): array
    {
        $byKey = [];
        DB::table('email_messages')->whereNotNull('imap_uid')->whereNotNull('related_request_id')
            ->select(['id', 'mailbox_id', 'folder', 'imap_uid'])->orderBy('id')
            ->chunk(5000, function ($chunk) use (&$byKey) {
                foreach ($chunk as $r) {
                    $byKey[$r->mailbox_id.'|'.$r->folder.'|'.$r->imap_uid] = (int) $r->id;
                }
            });
        $out = [];
        foreach ($rows as $i => $row) {
            $mailbox = $mailboxes[mb_strtolower($row['box'])] ?? null;
            $key = $mailbox !== null ? $mailbox->id.'|'.$this->text($row['folder'], 255).'|'.$row['uid'] : null;
            if ($key !== null && isset($byKey[$key])) {
                $out[$i] = $byKey[$key];
            }
        }

        return $out;
    }

    private function category(array $row, array $m, array $firstOfRequest): ?string
    {
        if ($m['supplier']) {
            return EmailCategory::SupplierReply->value;
        }
        if ($row['outbound']) {
            return null;
        }

        return isset($firstOfRequest[$m['i']]) ? EmailCategory::ClientRequest->value : EmailCategory::ThreadReply->value;
    }

    private function assignmentRows(array $plan, int $requestId, array $users, string $now): array
    {
        $out = [];
        $first = $plan['first_assigned'] !== null ? ($users[mb_strtolower($plan['first_assigned'])] ?? null) : null;
        $last = $plan['assigned'] !== null ? ($users[mb_strtolower($plan['assigned'])] ?? null) : null;
        foreach (array_unique(array_filter([$first, $last])) as $k => $user) {
            $date = $this->date($user === $first ? $plan['created_ts'] : $plan['last_ts']);
            $out[] = [
                'request_id' => $requestId,
                'user_id' => $user,
                'by_user_id' => null,
                'reason' => self::COMMENT,
                'assigned_at' => $date,
                'created_at' => $date,
                'updated_at' => $now,
            ];
        }

        return $out;
    }

    private function stateRows(array $plan, int $requestId, string $now): array
    {
        $base = fn (?string $from, string $to, int $ts, ?string $comment) => [
            'request_id' => $requestId,
            'from_status' => $from,
            'to_status' => $to,
            'by_user_id' => null,
            'event' => self::EVENT,
            'comment' => $comment,
            'payload' => json_encode(['retro' => true]),
            'created_at' => $this->date($ts),
            'updated_at' => $now,
        ];
        if ($plan['closed'] === null) {
            return [$base(null, $plan['status']->value, $plan['last_ts'], self::COMMENT)];
        }
        $before = $plan['peak']?->value ?? 'in_progress';

        return [
            $base(null, $before, $plan['created_ts'], self::COMMENT),
            $base($before, $plan['status']->value, $plan['closed']['ts'], self::CLOSE_COMMENT),
        ];
    }

    private function quoteRow(array $doc, int $requestId, int $messageId, string $now, DetectorType $type = DetectorType::OutboundQuotationFull): array
    {
        return [
            'request_id' => $requestId,
            'email_message_id' => $messageId,
            'email_attachment_id' => null,
            'source' => OutboundQuote::SOURCE_ATTACHMENT,
            'document_type' => $type->value,
            'document_number' => $doc['number'],
            'document_date' => $doc['date'] ?? $this->date($doc['ts'], 'Y-m-d'),
            'currency' => 'RUB',
            // Вкладка «КП» показывает только matched (Requests\Detail).
            'status' => OutboundQuote::STATUS_MATCHED,
            'payload' => json_encode(['retro' => true, 'filename' => $doc['filename']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'parsed_at' => $now,
            'matched_at' => $now,
            'created_at' => $this->date($doc['ts']),
            'updated_at' => $now,
        ];
    }

    private function invoiceRow(array $doc, int $requestId, ?int $messageId, array $users, int $validityDays, string $now): array
    {
        $issued = $doc['date'] !== null ? strtotime($doc['date'].' 12:00:00') : $doc['ts'];
        [$expiresAt] = InvoiceService::computeExpiry(app(RussianWorkingDayService::class), Carbon::createFromTimestamp($issued, config('app.timezone')), null, $validityDays);
        $expires = $expiresAt->getTimestamp();

        return [
            'request_id' => $requestId,
            'invoice_number' => $this->text($doc['number'], 64),
            'issued_at' => $this->date($issued),
            'expires_at' => $this->date($expires),
            'validity_days' => $validityDays,
            'status' => ($expires < time() ? InvoiceStatus::Expired : InvoiceStatus::Pending)->value,
            'comment' => self::COMMENT,
            'created_by_user_id' => $users[mb_strtolower($doc['author'])] ?? null,
            'email_message_id' => $messageId,
            'created_at' => $this->date($doc['ts']),
            'updated_at' => $now,
        ];
    }

    /** Отсутствие строки значит «непрочитано», поэтому старая почта помечается прочитанной, как на сервере. */
    private function insertReadStates(array $rows, array $messageIds, $mailboxes, array $unseenByBox, string $now): int
    {
        $count = 0;
        $batch = [];
        foreach ($messageIds as $i => $id) {
            $row = $rows[$i];
            $mailbox = $mailboxes[mb_strtolower($row['box'])] ?? null;
            if ($mailbox === null || ! $mailbox->owner_user_id || isset($unseenByBox[$row['box']][$row['folder']][$row['uid']])) {
                continue;
            }
            $date = $row['ts'] !== null ? $this->date($row['ts']) : $now;
            $batch[] = ['email_message_id' => $id, 'user_id' => $mailbox->owner_user_id, 'read_at' => $date, 'created_at' => $now, 'updated_at' => $now];
            if (count($batch) >= self::CHUNK) {
                $count += DB::table('email_message_user_states')->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $count += DB::table('email_message_user_states')->insertOrIgnore($batch);
        }

        return $count;
    }

    private function recipients(array $list): ?string
    {
        if ($list === []) {
            return null;
        }

        return json_encode(array_map(fn ($a) => ['email' => $this->text($a['email'], 255), 'name' => $a['name'] !== null ? $this->text($a['name'], 255) : null], $list), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function date(int $ts, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, $ts);
    }

    /** PostgreSQL не принимает нулевой байт и битый UTF-8. */
    private function text(string $value, int $limit): string
    {
        return mb_substr(str_replace("\0", '', mb_scrub($value, 'UTF-8')), 0, $limit);
    }
}
