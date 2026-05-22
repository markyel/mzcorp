<?php

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request as ClientRequest;
use App\Services\Mail\MailFolderRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Диагностика «почему письма остались в INBOX».
 *
 * Группирует inbound-письма из общих папок INBOX (без /MZ/) по причине
 * застревания: ещё не классифицировано, irrelevant, новая заявка ждёт
 * парсинга, привязано к Request но routing не отработал, и т.п.
 *
 * По умолчанию READ-ONLY — печатает таблицу + sample.
 *
 *   php artisan mail:diag-inbox
 *   php artisan mail:diag-inbox --mailbox=5
 *   php artisan mail:diag-inbox --since=24      # часов назад
 *   php artisan mail:diag-inbox --reroute       # принудительно переместить
 *                                                 письма с привязкой к Request
 *                                                 в папку менеджера
 *   php artisan mail:diag-inbox --reroute --apply  # реально, без --apply dry-run
 */
class MailDiagInboxCommand extends Command
{
    protected $signature = 'mail:diag-inbox
        {--mailbox= : id ящика (по умолчанию все inbound)}
        {--since=72 : окно поиска в часах (default 72)}
        {--reroute : попытаться перепрогнать routing для группы routable}
        {--apply : снять dry-run у --reroute (по умолчанию только показывает)}
        {--limit=20 : размер sample на группу в выводе}';

    protected $description = 'Разбор почему письма остались в INBOX (категория / заявка / routing).';

    public function handle(MailFolderRouter $router): int
    {
        $mailboxId = $this->option('mailbox') ? (int) $this->option('mailbox') : null;
        $sinceHours = max(1, (int) $this->option('since'));
        $sampleLimit = max(1, (int) $this->option('limit'));
        $reroute = (bool) $this->option('reroute');
        $apply = (bool) $this->option('apply');

        $since = now()->subHours($sinceHours);

        // Select только нужные поля: без body_plain/body_html/raw_source/headers
        // — это сотни KB на письмо. Без проекции команда падала OOM на
        // массовых INBOX'ах (500+ писем × 100KB body).
        // Тип ящика — personal vs general. Влияет ТОЛЬКО на классификацию
        // *_routable: в личном ящике письмо уже у нужного менеджера в его
        // INBOX и никуда ехать не должно (MailDeliverToManagerService так
        // и делает). Все прочие проблемы (awaiting_classify, *_pending,
        // *_no_link, *_no_manager) — показываем независимо от типа ящика,
        // т.к. это реальные проблемы и для personal (клиент мог написать
        // менеджеру напрямую, и pipeline всё равно должен создать Request).
        $personalMailboxIds = \App\Models\Mailbox::query()
            ->where('type', \App\Enums\MailboxType::Personal->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $personalSet = array_flip($personalMailboxIds);

        $q = EmailMessage::query()
            ->select([
                'id', 'mailbox_id', 'folder', 'direction', 'category',
                'category_confidence', 'related_request_id', 'subject',
                'from_email', 'from_name', 'created_at',
            ])
            ->where('direction', MailDirection::Inbound->value)
            ->where('created_at', '>=', $since)
            // INBOX (любой регистр и язык) — всё что не уехало в /MZ/...
            // Менеджерские папки на Yandex IMAP — «MZ|Ivanov», на других
            // серверах могут быть «MZ/Ivanov». Учитываем оба разделителя,
            // иначе письма уже в MZ-папках попадают в «застрявшие».
            ->where(function ($w) {
                $w->where(function ($w2) {
                    $w2->where('folder', 'not like', '%MZ/%')
                       ->where('folder', 'not like', '%MZ|%');
                })->orWhereNull('folder');
            });

        if ($mailboxId) {
            $q->where('mailbox_id', $mailboxId);
        }

        $messages = $q->orderByDesc('id')->get();
        if ($messages->isEmpty()) {
            $this->info('В INBOX за последние ' . $sinceHours . ' ч ничего не найдено.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Inbound писем в INBOX за %d ч: %d (mailbox=%s)',
            $sinceHours,
            $messages->count(),
            $mailboxId ?: 'все',
        ));
        $this->newLine();

        // Preload existing Request по related_request_id одним запросом.
        $reqIds = $messages->pluck('related_request_id')->filter()->unique()->all();
        $requests = ! empty($reqIds)
            ? ClientRequest::query()->whereIn('id', $reqIds)->with('assignedUser')->get()->keyBy('id')
            : collect();

        // Группы причин.
        $groups = [
            'awaiting_classify' => [],   // category null, недавно
            'irrelevant'        => [],   // category=irrelevant
            'thread_reply_no_link' => [], // category=thread_reply, related_request_id null
            'thread_reply_routable' => [], // related_request_id есть, есть manager — нужен routing
            'thread_reply_no_manager' => [], // related есть но нет менеджера
            'client_request_no_req' => [], // category=client_request, но Request не создалась
            'client_request_pending' => [], // Request есть, status=Pending, нет менеджера
            'client_request_routable' => [], // Request с менеджером, письмо в INBOX (общий ящик) — bug
            'in_personal_inbox' => [], // письмо в личном ящике менеджера — это норма
            'unknown'           => [],   // прочее (для debug)
        ];

        foreach ($messages as $m) {
            $cat = $m->category;
            $relId = $m->related_request_id;
            $related = $relId ? $requests->get($relId) : null;
            $hasManager = $related && $related->assigned_user_id;
            $isPersonal = isset($personalSet[(int) $m->mailbox_id]);

            if ($cat === null) {
                $groups['awaiting_classify'][] = $m;
                continue;
            }
            if ($cat === EmailCategory::Irrelevant->value) {
                $groups['irrelevant'][] = $m;
                continue;
            }
            if ($cat === EmailCategory::ThreadReply->value) {
                if (! $relId) {
                    $groups['thread_reply_no_link'][] = $m;
                } elseif (! $hasManager) {
                    $groups['thread_reply_no_manager'][] = $m;
                } elseif ($isPersonal) {
                    // Reply в личный ящик менеджера — норма (он уже у него).
                    $groups['in_personal_inbox'][] = $m;
                } else {
                    $groups['thread_reply_routable'][] = $m;
                }
                continue;
            }
            if ($cat === EmailCategory::ClientRequest->value) {
                if (! $relId) {
                    $groups['client_request_no_req'][] = $m;
                } elseif (! $hasManager) {
                    $groups['client_request_pending'][] = $m;
                } elseif ($isPersonal) {
                    // Заявка в личном INBOX менеджера (либо доставленная
                    // MailDeliverToManagerService копия, либо прямое письмо
                    // клиента менеджеру) — это норма, ехать ей некуда.
                    $groups['in_personal_inbox'][] = $m;
                } else {
                    $groups['client_request_routable'][] = $m;
                }
                continue;
            }
            $groups['unknown'][] = $m;
        }

        // Сводная таблица.
        $rows = [];
        foreach ($groups as $k => $list) {
            $rows[] = [$this->groupLabel($k), $k, count($list)];
        }
        $this->table(['Группа', 'Ключ', 'Кол-во'], $rows);

        // Sample каждой непустой группы.
        foreach ($groups as $k => $list) {
            if (empty($list)) {
                continue;
            }
            $this->newLine();
            $this->line("=== {$k} ({$this->groupLabel($k)}) — sample {$sampleLimit}/" . count($list) . " ===");
            $sample = array_slice($list, 0, $sampleLimit);
            foreach ($sample as $m) {
                $relId = $m->related_request_id;
                $related = $relId ? $requests->get($relId) : null;
                $managerLabel = $related?->assignedUser?->name ?? '—';
                $this->line(sprintf(
                    '  #%d  %s  cat=%s  rel=%s  mgr=%s  folder=%s  «%s»  ← %s',
                    $m->id,
                    $m->created_at?->format('m-d H:i') ?? '—',
                    $m->category ?? 'null',
                    $relId ? ($related?->internal_code ?? '?') : '—',
                    mb_strimwidth($managerLabel, 0, 18, '…'),
                    mb_strimwidth((string) $m->folder, 0, 18, '…'),
                    mb_strimwidth((string) $m->subject, 0, 50, '…'),
                    mb_strimwidth((string) $m->from_email, 0, 30, '…'),
                ));
            }
        }

        // Reroute (опционально).
        if ($reroute) {
            $candidates = array_merge(
                $groups['thread_reply_routable'],
                $groups['client_request_routable'],
            );
            $this->newLine();
            $this->info(sprintf(
                '=== Re-route candidates: %d (mode: %s) ===',
                count($candidates),
                $apply ? 'APPLY' : 'DRY-RUN',
            ));
            if (empty($candidates)) {
                $this->line('  Нечего перемещать.');
                return self::SUCCESS;
            }

            $stats = ['ok' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($candidates as $m) {
                $related = $requests->get($m->related_request_id);
                if (! $related || ! $related->assignedUser) {
                    $stats['skipped']++;
                    continue;
                }
                $this->line(sprintf(
                    '  → #%d  %s  → %s/MZ/%s',
                    $m->id,
                    mb_strimwidth((string) $m->subject, 0, 50, '…'),
                    $m->folder,
                    $related->assignedUser->name,
                ));
                if (! $apply) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    // Перегружаем полную модель — router читает imap_uid и др.
                    $full = EmailMessage::find($m->id);
                    if (! $full) {
                        $stats['skipped']++;
                        continue;
                    }
                    $r = $router->routeToManager($full, $related->assignedUser);
                    if ($r !== null) {
                        $stats['ok']++;
                    } else {
                        $stats['failed']++;
                        $this->warn("    ✗ routeToManager вернул null (IMAP fail / папка / COPYUID — см. логи)");
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $this->error("    ✗ exception: {$e->getMessage()}");
                    Log::error('mail:diag-inbox: route failed', [
                        'email_message_id' => $m->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->newLine();
            $this->table(
                ['metric', 'value'],
                collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
            );
        }

        return self::SUCCESS;
    }

    private function groupLabel(string $key): string
    {
        return [
            'awaiting_classify' => 'Ожидает классификации (category=null)',
            'irrelevant' => 'Не клиентская (спам / поставщик / служебное)',
            'thread_reply_no_link' => 'Reply, но не привязан к Request',
            'thread_reply_routable' => 'Reply ↔ Request с менеджером — РОУТИТЬ',
            'in_personal_inbox' => 'В личном INBOX менеджера (норма)',
            'thread_reply_no_manager' => 'Reply ↔ Request без менеджера',
            'client_request_no_req' => 'Заявка, но Request не создалась',
            'client_request_pending' => 'Заявка, Pending, нет менеджера',
            'client_request_routable' => 'Заявка с менеджером, но в INBOX — РОУТИТЬ',
            'unknown' => 'Неизвестная категория',
        ][$key] ?? $key;
    }
}
