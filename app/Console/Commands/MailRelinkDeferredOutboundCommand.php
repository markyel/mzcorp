<?php

namespace App\Console\Commands;

use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailMessage;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use App\Services\Mail\OutgoingMailLinker;
use Carbon\Carbon;
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
 * по СВЕЖИМ самоопределяющимся счёт/КП-вложениям → OutboundQuote → Invoice.
 *
 * Гард свежести: разбираем только документы, чья дата в имени файла ≤ fresh-days.
 * Иначе авто-привязка воскрешала бы пересылки архивных счетов («Счет МЗ-368 от
 * 2025-01» как напоминание) — их место в ручном триаже, не в авто-создании.
 *
 *   php artisan mail:relink-deferred-outbound                 # dry-list
 *   php artisan mail:relink-deferred-outbound --apply         # реально
 *   php artisan mail:relink-deferred-outbound --apply --fresh-days=45
 */
class MailRelinkDeferredOutboundCommand extends Command
{
    protected $signature = 'mail:relink-deferred-outbound
        {--apply : Реально перепривязать + dispatch разбор (без флага — dry-list)}
        {--limit=50 : Сколько писем обработать за прогон}
        {--since-hours=168 : Брать только письма за последние N часов (default 7 дней)}
        {--fresh-days=30 : Разбирать только документы с датой ≤ N дней (анти-воскрешение архива)}';

    protected $description = 'Перепривязать исходящие письма со свежим счётом/КП, зависшие без заявки (гонка отложенной привязки)';

    public function handle(OutgoingMailLinker $linker, OutboundDocumentDetector $detector): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $sinceHours = max(1, (int) $this->option('since-hours'));
        $freshDays = max(1, (int) $this->option('fresh-days'));
        $freshCutoff = now()->subDays($freshDays)->startOfDay();

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
            ->with('attachments')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Нет зависших исходящих документов для перепривязки.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d писем (mode: %s, fresh ≤%dд).',
            $apply ? 'Обрабатываю' : 'Найдено',
            $candidates->count(),
            $apply ? 'apply' : 'dry-list',
            $freshDays,
        ));

        $stats = ['examined' => 0, 'skipped_stale' => 0, 'linked' => 0, 'parse_dispatched' => 0, 'still_unlinked' => 0];

        foreach ($candidates as $m) {
            $stats['examined']++;

            // Свежие самоопределяющиеся документы (счёт/КП с датой ≤ fresh-days).
            // Авто-привязку делаем ТОЛЬКО ради них; старьё — в ручной триаж.
            $freshDocs = [];
            foreach ($m->attachments as $att) {
                $type = $detector->classifyAttachmentByFilename((string) $att->filename);
                if ($type === null) {
                    continue;
                }
                $date = $this->docDateFromFilename((string) $att->filename);
                if ($date === null || $date->lt($freshCutoff)) {
                    continue;
                }
                $freshDocs[] = ['att' => $att, 'type' => $type];
            }

            $line = sprintf(
                '  #%d  %s  → %s',
                $m->id,
                mb_substr((string) $m->subject, 0, 45),
                mb_substr((string) $m->from_email, 0, 30),
            );

            if (empty($freshDocs)) {
                $stats['skipped_stale']++;
                if (! $apply) {
                    $this->line($line . '  [нет свежих документов — пропуск]');
                }

                continue;
            }

            if (! $apply) {
                $this->line($line . sprintf('  [свежих док-в: %d]', count($freshDocs)));

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
            $dispatched = 0;
            foreach ($freshDocs as $fd) {
                ParseOutboundQuoteJob::dispatch($fd['att']->id, $fd['type']->value, true);
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

    /**
     * Дата документа из имени файла «… от 2026-06-15_14-29-16.pdf» (YYYY-MM-DD).
     */
    private function docDateFromFilename(string $filename): ?Carbon
    {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $filename, $m)) {
            try {
                return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
