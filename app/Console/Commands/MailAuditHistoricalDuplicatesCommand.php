<?php

namespace App\Console\Commands;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Services\Mail\MailboxConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Webklex\PHPIMAP\IMAP;

/**
 * Исторический аудит cross-mailbox дублей напрямую через IMAP.
 *
 * Зачем: пользователь спрашивает «были ли дубли В ЛИЧНЫХ ЯЩИКАХ Яндекса
 * ДО подключения этих ящиков в MyLift». В нашей БД эти дубли видны не
 * могли бы — мы тогда не sync'ались с этими ящиками. Но письма по-прежнему
 * физически лежат в IMAP-INBOX каждого менеджера, и через наш OAuth-токен
 * мы можем их перечитать.
 *
 * Алгоритм:
 *  1. Для каждого active personal-mailbox через IMAP запрашиваем заголовки
 *     писем за период [--from, --to] из INBOX. Тяжёлые body не тянем
 *     (setFetchBody(false)) — нам нужен только Message-ID + From + Subject +
 *     X-Yandex-Forward.
 *  2. Собираем mapping `[message_id] => [{mailbox, from, subject, x_fwd}, ...]`.
 *  3. Оставляем message_id, встретившиеся в 2+ ящиках — это и есть
 *     исторические cross-mailbox дубли (включая те, что были до подключения
 *     ящиков в MyLift).
 *  4. Отчёт: разбивка по парам source→dest + проверка X-Yandex-Forward
 *     (наличие маркера = доказательство forwarding на уровне Yandex).
 *
 * Usage:
 *   php artisan mail:audit-historical-duplicates --from=2026-05-15 --to=2026-05-22
 *   php artisan mail:audit-historical-duplicates --from=2026-05-01 --to=2026-05-22 --mailboxes=7,8,11,14
 *
 * NB: для Yandex IMAP период не должен быть слишком большой — иначе fetch
 * тянет много заголовков. ~7-14 дней оптимально.
 */
class MailAuditHistoricalDuplicatesCommand extends Command
{
    protected $signature = 'mail:audit-historical-duplicates
        {--from= : Начало периода YYYY-MM-DD (включительно)}
        {--to= : Конец периода YYYY-MM-DD (включительно)}
        {--mailboxes= : CSV id ящиков (default — все active personal)}
        {--folder=INBOX : IMAP-папка (default INBOX)}';

    protected $description = 'Исторический аудит cross-mailbox дублей через IMAP — поиск дублей, которых ещё не видела БД.';

    public function handle(MailboxConnector $connector): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if (! $from || ! $to) {
            $this->error('Нужно указать --from=YYYY-MM-DD и --to=YYYY-MM-DD.');

            return self::INVALID;
        }
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();
        $folder = (string) $this->option('folder');

        $mailboxes = Mailbox::query()
            ->where('type', MailboxType::Personal->value)
            ->where('is_active', true);
        if ($this->option('mailboxes')) {
            $ids = array_filter(array_map('intval', explode(',', (string) $this->option('mailboxes'))));
            $mailboxes->whereIn('id', $ids);
        }
        $mailboxes = $mailboxes->get();

        $this->info(sprintf(
            'Period: %s — %s · Folder: %s · Mailboxes: %d',
            $fromDate->toDateString(), $toDate->toDateString(), $folder, $mailboxes->count()
        ));

        // [message_id => [['mb_email' => ..., 'from' => ..., 'subject' => ..., 'x_fwd' => ..., 'date' => ...], ...]]
        $index = [];
        $perMailboxCount = [];

        foreach ($mailboxes as $mb) {
            $this->line(sprintf('  · %s (mb#%d) — fetching headers...', $mb->email, $mb->id));
            $client = null;
            try {
                $client = $connector->imapClient($mb);
                $f = $client->getFolderByPath($folder, soft_fail: true);
                if (! $f) {
                    $this->warn("    folder {$folder} not found, skip");
                    continue;
                }
                // ОБЯЗАТЕЛЬНО: явный SELECT — без него webklex может
                // не отправить SEARCH в правильный folder context и
                // получить «Empty response» от Yandex.
                $f->select();

                // Yandex IMAP SEARCH принимает дату в формате `DD-Mon-YYYY`
                // (RFC 3501). Carbon::format('d-M-Y') даёт «15-May-2026».
                // BEFORE — строго ДО, поэтому добавляем 1 день к --to.
                $sinceStr = $fromDate->format('d-M-Y');
                $beforeStr = $toDate->copy()->addDay()->format('d-M-Y');

                $msgs = $f->query()
                    ->setFetchOptions(IMAP::FT_PEEK)
                    ->setFetchBody(false)
                    ->setFetchFlags(false)
                    ->whereSince($sinceStr)
                    ->whereBefore($beforeStr)
                    ->get();

                $count = 0;
                foreach ($msgs as $msg) {
                    try {
                        $hdr = $msg->getHeader();
                        // Webklex normalizes Message-ID variants
                        $rawId = (string) ($hdr->get('message_id') ?? '');
                        if ($rawId === '') {
                            continue;
                        }
                        $messageId = trim($rawId, " <>\t\r\n");
                        if ($messageId === '') {
                            continue;
                        }

                        // Yandex может не отдавать INTERNALDATE через webklex
                        // в headers-only fetch. Доверяем SEARCH SINCE/BEFORE
                        // на стороне сервера. Для аналитики берём Date: header.
                        $internalDate = $msg->getInternalDate();
                        $dateHeader = (string) ($hdr->get('date') ?? '');

                        $from = (string) ($hdr->get('from') ?? '');
                        $subject = (string) ($hdr->get('subject') ?? '');
                        $xFwd = (string) ($hdr->get('x_yandex_forward') ?? '');

                        $index[$messageId][] = [
                            'mb_email' => $mb->email,
                            'mb_id' => $mb->id,
                            'from' => mb_substr($from, 0, 120),
                            'subject' => mb_substr($subject, 0, 80),
                            'x_fwd' => $xFwd,
                            'date' => $dateHeader ?: $internalDate,
                        ];
                        $count++;
                    } catch (\Throwable $e) {
                        // skip individual message errors
                    }
                }
                $perMailboxCount[$mb->email] = $count;
                $this->line("    fetched: {$count}");
            } catch (\Throwable $e) {
                $this->error("    IMAP error: " . $e->getMessage());
            } finally {
                try {
                    $client?->disconnect();
                } catch (\Throwable) {
                }
            }
        }

        $this->newLine();
        $this->info('=== Per-mailbox fetched counts ===');
        foreach ($perMailboxCount as $email => $cnt) {
            $this->line("  {$email}: {$cnt}");
        }

        // Filter to cross-mailbox dups (2+ different mailboxes)
        $dups = [];
        foreach ($index as $mid => $occurrences) {
            $uniqueBoxes = array_unique(array_column($occurrences, 'mb_email'));
            if (count($uniqueBoxes) >= 2) {
                $dups[$mid] = $occurrences;
            }
        }

        $this->newLine();
        $this->info(sprintf('=== Cross-mailbox дубли: %d уникальных Message-ID ===', count($dups)));

        // Pair counts
        $pairCounts = [];
        foreach ($dups as $mid => $occ) {
            // Сортируем mailbox'ы по email — пара canonical
            $boxes = array_values(array_unique(array_column($occ, 'mb_email')));
            sort($boxes);
            for ($i = 0; $i < count($boxes); $i++) {
                for ($j = $i + 1; $j < count($boxes); $j++) {
                    $key = $boxes[$i] . ' <-> ' . $boxes[$j];
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }
        arsort($pairCounts);

        $this->info('=== Pair distribution ===');
        foreach ($pairCounts as $pair => $cnt) {
            $this->line("  {$pair} = {$cnt}");
        }

        // Доказательство Yandex-side: сколько дублей с X-Yandex-Forward хотя бы в одной копии
        $withFwd = 0;
        foreach ($dups as $mid => $occ) {
            foreach ($occ as $o) {
                if ($o['x_fwd'] !== '') {
                    $withFwd++;
                    break;
                }
            }
        }
        $this->newLine();
        $this->info(sprintf(
            '=== Yandex-forward маркер: %d из %d дублей имеют X-Yandex-Forward (%.1f%%) ===',
            $withFwd, count($dups), count($dups) > 0 ? ($withFwd / count($dups) * 100) : 0
        ));

        // Sample 10 примеров
        $this->newLine();
        $this->info('=== Sample examples ===');
        $sampled = 0;
        foreach ($dups as $mid => $occ) {
            if ($sampled >= 10) {
                break;
            }
            $this->line('  message-id: ' . mb_substr($mid, 0, 70));
            foreach ($occ as $o) {
                $this->line(sprintf(
                    '    %s | from=%s subj=%s x_fwd=%s',
                    $o['mb_email'], mb_substr($o['from'], 0, 40), mb_substr($o['subject'], 0, 50),
                    $o['x_fwd'] !== '' ? mb_substr($o['x_fwd'], 0, 50) : '—'
                ));
            }
            $sampled++;
        }

        return self::SUCCESS;
    }
}
