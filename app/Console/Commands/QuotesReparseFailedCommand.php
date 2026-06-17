<?php

namespace App\Console\Commands;

use App\Enums\DetectorType;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\OutboundQuote;
use App\Services\AI\OpenAiCircuitBreaker;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing: переразбор упавших исходящих КП/счетов.
 *
 * Кейс (15.06.2026): OpenAI вернул `insufficient_quota` (429) на серию
 * исходящих счетов. ParseOutboundQuoteJob отрабатывал tries=2 и оставлял
 * OutboundQuote в `status=failed` НАВСЕГДА — без авто-восстановления и без
 * уведомления. В результате счета (6190, 6191, 6192, 6195, 6198, …) так и не
 * превратились в Invoice и не попали в /dashboard/invoices. После
 * восстановления квоты ничто их не перезапускало.
 *
 * Команда находит failed-quote'ы (привязанные к заявке и вложению) и повторно
 * дёргает ParseOutboundQuoteJob с force=true. Параллель с уже существующими
 * self-healing кронами (`mail:categorize`, `mail:relink-deferred`,
 * `quotes:reboost-stuck-decisions`).
 *
 * Гарды от лавины вызовов:
 *   - circuit-breaker: если квота кончилась (OpenAiCircuitBreaker открыт) —
 *     пропускаем прогон целиком, дождёмся восстановления (админ уже уведомлён);
 *   - max-age-days: старые failed-quote'ы не воскрешаем (заявка ушла дальше);
 *   - min-interval-hours: один quote переразбираем не чаще раза в N часов —
 *     чтобы при длительном простое OpenAI не сжечь cap за час;
 *   - max-attempts: после N безуспешных попыток считаем документ непарсимым.
 *
 *   php artisan quotes:reparse-failed                 # dry-list
 *   php artisan quotes:reparse-failed --apply         # реально dispatch
 *   php artisan quotes:reparse-failed --apply --max-age-days=30
 */
class QuotesReparseFailedCommand extends Command
{
    protected $signature = 'quotes:reparse-failed
        {--apply : Реально dispatch ParseOutboundQuoteJob (без флага — dry-list)}
        {--limit= : Максимум quote\'ов за прогон (default из конфига)}
        {--max-age-days= : Брать только failed-quote\'ы не старше N дней (default из конфига)}
        {--min-interval-hours= : Не повторять переразбор одного quote чаще, чем раз в N часов}
        {--max-attempts= : Cap авто-переразборов на quote (битые PDF не дёргаем вечно)}
        {--ignore-circuit : Не пропускать прогон, даже если OpenAI circuit-breaker открыт}';

    protected $description = 'Self-healing: переразбор упавших исходящих КП/счетов (OpenAI quota/429 и пр.)';

    public const ELIGIBLE = 'eligible';
    public const CAPPED = 'capped';
    public const THROTTLED = 'throttled';

    /**
     * Можно ли переразбирать quote прямо сейчас. Pure-функция (без БД/состояния)
     * — вынесена static для тестируемости границ cap/throttle.
     *
     *   capped    — attempts достигли потолка (документ считаем непарсимым);
     *   throttled — с прошлой попытки прошло меньше min-interval-hours;
     *   eligible  — можно dispatch'ить.
     */
    public static function classifyEligibility(
        int $attempts,
        ?CarbonInterface $lastAt,
        int $maxAttempts,
        int $minIntervalHours,
        CarbonInterface $now,
    ): string {
        if ($attempts >= $maxAttempts) {
            return self::CAPPED;
        }
        if ($lastAt !== null && $lastAt->copy()->addHours($minIntervalHours)->greaterThan($now)) {
            return self::THROTTLED;
        }

        return self::ELIGIBLE;
    }

    public function handle(OpenAiCircuitBreaker $breaker): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) ($this->option('limit') ?? config('services.quotes.reparse_failed.limit', 50));
        $maxAgeDays = (int) ($this->option('max-age-days') ?? config('services.quotes.reparse_failed.max_age_days', 14));
        $minIntervalHours = (int) ($this->option('min-interval-hours') ?? config('services.quotes.reparse_failed.min_interval_hours', 2));
        $maxAttempts = (int) ($this->option('max-attempts') ?? config('services.quotes.reparse_failed.max_attempts', 6));

        // Квота кончилась → circuit-breaker открыт после серии 429/quota.
        // Жечь новые вызовы бессмысленно — пропускаем прогон до восстановления.
        // Админ уже уведомлён OpenAiCircuitOpenedNotification при открытии.
        if (! $this->option('ignore-circuit') && $breaker->isOpen()) {
            $this->warn(sprintf(
                'OpenAI circuit-breaker открыт (≈%d мин до закрытия) — прогон пропущен.',
                $breaker->remainingMinutes(),
            ));

            return self::SUCCESS;
        }

        // Кандидаты: failed + есть вложение и заявка + парсимый тип документа.
        $candidates = OutboundQuote::query()
            ->where('status', OutboundQuote::STATUS_FAILED)
            ->whereNotNull('email_attachment_id')
            ->whereNotNull('request_id')
            ->whereIn('document_type', [
                DetectorType::OutboundInvoice->value,
                DetectorType::OutboundQuotationFull->value,
            ])
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('id')
            ->get();

        $this->info(sprintf(
            'Failed quotes (≤%dд): %d. Mode: %s. min-interval: %dч. max-attempts: %d. limit: %d.',
            $maxAgeDays,
            $candidates->count(),
            $apply ? 'APPLY' : 'DRY-RUN',
            $minIntervalHours,
            $maxAttempts,
            $limit,
        ));

        $stats = ['eligible' => 0, 'dispatched' => 0, 'throttled' => 0, 'capped' => 0];
        $now = now();

        foreach ($candidates as $quote) {
            if ($stats['dispatched'] >= $limit) {
                break;
            }

            $reparse = (array) data_get($quote->payload, 'reparse', []);
            $attempts = (int) ($reparse['attempts'] ?? 0);
            $lastAt = isset($reparse['last_at']) ? Carbon::parse((string) $reparse['last_at']) : null;

            $eligibility = self::classifyEligibility($attempts, $lastAt, $maxAttempts, $minIntervalHours, $now);
            if ($eligibility === self::CAPPED) {
                $stats['capped']++;

                continue;
            }
            if ($eligibility === self::THROTTLED) {
                $stats['throttled']++;

                continue;
            }

            $stats['eligible']++;
            $isQuota = str_contains((string) $quote->parse_error, 'insufficient_quota');
            $this->line(sprintf(
                '  %s oq#%d att#%d req#%d %s attempts=%d%s',
                $apply ? '[D]' : '[~]',
                $quote->id,
                $quote->email_attachment_id,
                $quote->request_id,
                $quote->document_type?->value,
                $attempts,
                $isQuota ? ' (quota)' : '',
            ));

            if (! $apply) {
                continue;
            }

            // Фиксируем попытку в payload ДО dispatch. Счётчик переживает обе
            // ветки job'а: на успехе ParseOutboundQuoteJob делает array_merge
            // (ключ reparse сохраняется), на неуспехе payload не трогается.
            $reparse['attempts'] = $attempts + 1;
            $reparse['last_at'] = $now->toIso8601String();
            $payload = is_array($quote->payload) ? $quote->payload : [];
            $payload['reparse'] = $reparse;
            $quote->payload = $payload;
            $quote->save();

            ParseOutboundQuoteJob::dispatch(
                $quote->email_attachment_id,
                $quote->document_type?->value ?? DetectorType::OutboundInvoice->value,
                true, // force: truncate items + пересчёт matcher + auto-issue invoice
            );
            $stats['dispatched']++;
        }

        $this->newLine();
        $this->table(
            ['metric', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        );

        if ($apply && $stats['dispatched'] > 0) {
            Log::info('quotes:reparse-failed: dispatched reparse jobs', $stats);
        }
        if (! $apply) {
            $this->warn('Это был DRY-RUN. Запусти с --apply чтобы реально dispatch\'нуть.');
        }

        return self::SUCCESS;
    }
}
