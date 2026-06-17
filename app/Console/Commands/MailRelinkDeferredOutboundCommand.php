<?php

namespace App\Console\Commands;

use App\Enums\DetectorType;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailMessage;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use App\Services\Mail\OutgoingMailLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing: перепривязать ИСХОДЯЩИЕ письма со счётом/КП, зависшие без
 * заявки из-за ГОНКИ отложенной привязки.
 *
 * Кейс 6197 (17.06.2026): менеджер отправил счёт в 14:29 (`In-Reply-To` →
 * msg#20839), а заявка M-2026-4445 и привязка родителя msg#20839 появились
 * только в 15:10. На момент обработки исходящего `OutgoingMailLinker` L1 не
 * нашёл привязанного родителя → ушёл в fuzzy L4 → отказ → счёт остался
 * `related_request_id=NULL` → детектор счёта не запустился → Invoice не создан.
 *
 * Для ВХОДЯЩИХ эту гонку лечит `mail:relink-deferred`; это — его исходящий
 * близнец. Повторно гоняет `tryLink` ТОЛЬКО по детерминированным заголовкам/
 * коду (без fuzzy L4 — ошибочная авто-привязка хуже, чем оставить в триаже
 * /dashboard/invoices/unlinked). На успехе дёргает `ParseOutboundQuoteJob`
 * по самоопределяющимся счёт/КП-вложениям → OutboundQuote → Invoice.
 *
 *   php artisan mail:relink-deferred-outbound                 # dry-list
 *   php artisan mail:relink-deferred-outbound --apply         # реально
 *   php artisan mail:relink-deferred-outbound --apply --since-hours=336
 */
class MailRelinkDeferredOutboundCommand extends Command
{
    protected $signature = 'mail:relink-deferred-outbound
        {--apply : Реально перепривязать + dispatch разбор (без флага — dry-list)}
        {--limit=50 : Сколько писем обработать за прогон}
        {--since-hours=168 : Брать только за последние N часов (default 7 дней)}';

    protected $description = 'Перепривязать исходящие письма со счётом/КП, зависшие без заявки (гонка отложенной привязки)';

    public function handle(OutgoingMailLinker $linker, OutboundDocumentDetector $detector): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $sinceHours = max(1, (int) $this->option('since-hours'));

        // Кандидаты: исходящие без заявки, со ссылками на тред (in_reply_to /
        // references — иначе по заголовкам линковать не по чему) и хотя бы одним
        // вложением-документом (счёт/КП по имени файла).
        $candidates = EmailMessage::query()
            ->where('direction', 'outbound')
            ->whereNull('related_request_id')
            ->where('created_at', '>=', now()->subHours($sinceHours))
            ->where(function ($q) {
                $q->whereNotNull('in_reply_to')->orWhereNotNull('references_header');
            })
            ->whereHas('attachments', function ($q) {
                $q->where('filename', 'ilike', 'Счет %')
                    ->orWhere('filename', 'ilike', 'Счёт %')
                    ->orWhere('filename', 'ilike', 'Инвойс %')
                    ->orWhere('filename', 'ilike', 'Предложение %');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Нет зависших исходящих документов для перепривязки.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d писем (mode: %s).',
            $apply ? 'Обрабатываю' : 'Найдено',
            $candidates->count(),
            $apply ? 'apply' : 'dry-list',
        ));

        $stats = ['examined' => 0, 'linked' => 0, 'parse_dispatched' => 0, 'still_unlinked' => 0];

        foreach ($candidates as $m) {
            $stats['examined']++;
            $line = sprintf(
                '  #%d  %s  → %s  in_reply_to=%s',
                $m->id,
                mb_substr((string) $m->subject, 0, 45),
                mb_substr((string) $m->from_email, 0, 30),
                $m->in_reply_to ? mb_substr($m->in_reply_to, 0, 28) : '-',
            );

            if (! $apply) {
                $this->line($line);

                continue;
            }

            try {
                // Только заголовки/код — без fuzzy L4.
                $request = $linker->tryLink($m->fresh(), allowFuzzyRecipientMatch: false);
            } catch (\Throwable $e) {
                $this->line($line . '  → ERROR: ' . $e->getMessage());
                Log::warning('mail:relink-deferred-outbound: linker failed', [
                    'email_message_id' => $m->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $request) {
                $stats['still_unlinked']++;
                $this->line($line . '  → ещё без родителя (в триаж)');

                continue;
            }

            $stats['linked']++;

            // Перепривязали — теперь разобрать документы (счёт → Invoice, КП →
            // OutboundQuote). Парсим только самоопределяющиеся по имени вложения,
            // каждое по СВОЕМУ типу; force=true пересоздаёт quote.
            $dispatched = 0;
            foreach ($m->attachments as $att) {
                $type = $detector->classifyAttachmentByFilename((string) $att->filename);
                if ($type === null) {
                    continue;
                }
                ParseOutboundQuoteJob::dispatch($att->id, $type->value, true);
                $dispatched++;
                $stats['parse_dispatched']++;
            }

            $this->line($line . sprintf('  → linked %s, разбор×%d', $request->internal_code, $dispatched));
            Log::info('mail:relink-deferred-outbound: relinked outbound doc', [
                'email_message_id' => $m->id,
                'request_id' => $request->id,
                'internal_code' => $request->internal_code,
                'parse_dispatched' => $dispatched,
            ]);
        }

        $this->newLine();
        $this->table(
            ['metric', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        );

        if (! $apply) {
            $this->warn('Это был DRY-RUN. Запусти с --apply чтобы реально перепривязать.');
        }

        return self::SUCCESS;
    }
}
