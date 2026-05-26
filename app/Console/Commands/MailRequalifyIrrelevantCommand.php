<?php

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Services\Mail\MailRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Перепрогон писем, ошибочно помеченных irrelevant старыми версиями
 * детекторов. Целевые reasoning-паттерны:
 *   - "Unintended recipient: ..."          (UnintendedRecipientDetector)
 *   - "Empty body, no actionable content"  (IncomingMailProcessor auto-guard)
 *   - "Empty body, no%actionable%"         (старые формулировки)
 *
 * Фильтры безопасности:
 *   - direction = inbound
 *   - related_request_id IS NULL  (заявка ещё не восстановлена руками РОПа —
 *     не тревожим уже разобранные кейсы)
 *   - created_at >= now() - days  (default 2 дня — свежие письма, по которым
 *     IMAP-доставка в личный ящик ещё имеет смысл)
 *
 * Алгоритм per-message:
 *   1. Сбросить category-поля (`null`).
 *   2. Запустить `MailRouter::route($message->fresh())` — пройдёт всю
 *      pipeline заново: UnintendedRecipientDetector (с фиксом BCC-blast) →
 *      LLM categorize → ReplyLinker → IncomingMailProcessor (с фиксом
 *      subject+article в isContentEmpty).
 *   3. Зафиксировать новое значение category.
 *
 *   php artisan mail:requalify-irrelevant --dry-run
 *   php artisan mail:requalify-irrelevant --days=2 --limit=50
 */
class MailRequalifyIrrelevantCommand extends Command
{
    protected $signature = 'mail:requalify-irrelevant
        {--days=2 : За последние N дней}
        {--limit=200 : Максимум писем за прогон}
        {--dry-run : Только показать кандидатов, не трогать БД}';

    protected $description = 'Перепрогнать pipeline для писем, ошибочно помеченных irrelevant старыми детекторами';

    public function handle(MailRouter $router): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays($days);

        $candidates = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->where('category', EmailCategory::Irrelevant->value)
            ->whereNull('related_request_id')
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                $q->where('category_reasoning', 'like', 'Unintended recipient:%')
                  ->orWhere('category_reasoning', 'like', 'Empty body, no%');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Кандидатов нет.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Найдено %d писем за %d дн (с %s)%s',
            $candidates->count(),
            $days,
            $since->format('Y-m-d H:i'),
            $dryRun ? ' [DRY RUN]' : '',
        ));
        $this->newLine();

        $stats = ['kept_irrelevant' => 0, 'requalified' => 0, 'linked_to_request' => 0, 'failed' => 0];

        foreach ($candidates as $m) {
            $this->line(sprintf(
                'email#%d | %s | %s',
                $m->id,
                mb_substr((string) $m->from_email, 0, 40),
                mb_substr((string) $m->subject, 0, 70),
            ));
            $this->line('  was: ' . mb_substr((string) $m->category_reasoning, 0, 90));

            if ($dryRun) {
                continue;
            }

            $m->forceFill([
                'category' => null,
                'category_confidence' => null,
                'category_intent' => null,
                'category_reasoning' => null,
                'categorized_at' => null,
            ])->save();

            try {
                $router->route($m->fresh());
                $fresh = $m->fresh();
                $newCat = $fresh->category;
                $reqId = $fresh->related_request_id;

                if ($newCat === EmailCategory::Irrelevant->value) {
                    $stats['kept_irrelevant']++;
                    $this->line('  → still irrelevant: ' . mb_substr((string) $fresh->category_reasoning, 0, 80));
                } else {
                    $stats['requalified']++;
                    if ($reqId !== null) {
                        $stats['linked_to_request']++;
                    }
                    $this->line(sprintf(
                        '  → %s (conf=%.2f)%s',
                        $newCat ?? '—',
                        (float) $fresh->category_confidence,
                        $reqId !== null ? ' · request#' . $reqId : '',
                    ));
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->error('  FAIL: ' . $e->getMessage());
                Log::error('mail:requalify-irrelevant failed', [
                    'email_message_id' => $m->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $rows = [];
        foreach ($stats as $k => $v) {
            $rows[] = [$k, (string) $v];
        }
        $this->table(['metric', 'value'], $rows);

        return self::SUCCESS;
    }
}
