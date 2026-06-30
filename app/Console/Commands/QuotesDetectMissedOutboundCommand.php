<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use App\Services\Mail\MailRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Self-healing: догоняющий детект исходящих КП/счетов, пропущенных из-за
 * гонки «КП ушёл раньше создания заявки».
 *
 * Кейс (M-2026-6323 / req 6324): менеджер отправил КП напрямую из почтового
 * клиента; письмо засинкалось в CRM за 2 секунды ДО создания заявки. Детектор
 * исходящих (MailRouter) гейтится на `related_request_id != null` — в момент
 * синка заявки ещё не было → письмо пропущено. Потом reply-linker привязал
 * письмо к заявке, но детектор повторно НЕ запускается. Итог: КП не распознан
 * (нет OutboundQuote/ai_decision), статус застрял на «Назначена»/«В работе».
 *
 * Команда находит привязанные к НЕзакрытой заявке исходящие письма с КП/счёт-
 * вложением (по имени файла), у которых детектор ни разу не отработал (нет
 * ai_decision), и прогоняет тот же путь, что и синк
 * (MailRouter::runOutboundDocumentDetection): rule-based → LLM → recordSuggestion
 * (auto-apply переводит заявку в Quoted/Invoiced) → парсер вложений.
 *
 * Идемпотентно: после прогона появляется ai_decision → письмо больше не
 * eligible. Параллель с `quotes:reparse-failed` / `mail:relink-deferred-outbound`.
 *
 *   php artisan quotes:detect-missed-outbound                  # dry-list
 *   php artisan quotes:detect-missed-outbound --apply          # реально детектить
 *   php artisan quotes:detect-missed-outbound --apply --days=60
 *   php artisan quotes:detect-missed-outbound --apply --include-terminal
 *   php artisan quotes:detect-missed-outbound --request=M-2026-6323 --apply
 */
class QuotesDetectMissedOutboundCommand extends Command
{
    protected $signature = 'quotes:detect-missed-outbound
        {--apply : Реально прогнать детектор (без флага — dry-list)}
        {--days=14 : Брать письма не старше N дней по sent_at (0 = без ограничения)}
        {--limit=200 : Максимум писем за прогон}
        {--include-terminal : Включить заявки в терминальном статусе (closed_*)}
        {--request= : Ограничить одной заявкой по internal_code}';

    protected $description = 'Self-healing: догоняющий детект исходящих КП/счетов (гонка «КП раньше заявки»)';

    public function handle(OutboundDocumentDetector $detector, MailRouter $router): int
    {
        $apply = (bool) $this->option('apply');
        $days = (int) $this->option('days');
        $limit = max(1, (int) $this->option('limit'));
        $includeTerminal = (bool) $this->option('include-terminal');
        $reqCode = trim((string) $this->option('request'));

        $terminal = [\App\Enums\RequestStatus::ClosedWon->value, \App\Enums\RequestStatus::ClosedLost->value];

        $query = EmailMessage::query()
            ->where('email_messages.direction', 'outbound')
            ->whereNotNull('email_messages.related_request_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('email_attachments as ea')
                ->whereColumn('ea.email_message_id', 'email_messages.id'))
            // детектор ни разу не отработал по письму
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('ai_decisions as ad')
                ->whereColumn('ad.email_message_id', 'email_messages.id'))
            ->whereExists(function ($q) use ($includeTerminal, $terminal, $reqCode) {
                $q->select(DB::raw(1))->from('requests as r')
                    ->whereColumn('r.id', 'email_messages.related_request_id');
                if (! $includeTerminal) {
                    $q->whereNotIn('r.status', $terminal);
                }
                if ($reqCode !== '') {
                    $q->where('r.internal_code', $reqCode);
                }
            });

        if ($days > 0) {
            $query->where('email_messages.sent_at', '>=', now()->subDays($days));
        }

        $messages = $query->with('attachments')
            ->orderBy('email_messages.id')
            ->limit($limit)
            ->get();

        $eligible = 0;
        $processed = 0;
        $moved = 0;

        foreach ($messages as $message) {
            // Фильтр по имени вложения: берём только письма с явным КП/счёт-
            // вложением — дёшево (rule-based), без LLM-спама на случайные PDF.
            $kpAtt = $message->attachments->first(
                fn ($a) => $detector->classifyAttachmentByFilename((string) $a->filename) !== null,
            );
            if ($kpAtt === null) {
                continue;
            }

            $request = Request::find($message->related_request_id);
            if ($request === null) {
                continue;
            }

            $eligible++;
            $before = $request->status->value;

            if (! $apply) {
                $this->line(sprintf(
                    '  [dry] %s [%s] em=%d  %s',
                    $request->internal_code, $before, $message->id, mb_substr((string) $kpAtt->filename, 0, 50),
                ));

                continue;
            }

            $router->runOutboundDocumentDetection($message, $request);
            $processed++;

            $after = $request->fresh()->status->value;
            $changed = $before !== $after;
            $moved += $changed ? 1 : 0;
            $this->line(sprintf(
                '  %s em=%d  %s%s',
                $request->internal_code, $message->id,
                $changed ? "$before → $after" : "$before (без смены статуса)",
                $changed ? '  ✓' : '',
            ));
        }

        $this->info(sprintf(
            'Кандидатов с КП-вложением: %d. %s',
            $eligible,
            $apply
                ? "Прогнано: $processed, сменили статус: $moved."
                : 'Dry-run — запусти с --apply для реального детекта.',
        ));

        return self::SUCCESS;
    }
}
