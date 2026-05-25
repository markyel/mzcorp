<?php

namespace App\Console\Commands;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Models\AiDecision;
use App\Models\OutboundQuote;
use App\Services\DocumentDetector\AiDecisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Подобрать AiDecision'ы, застрявшие в `suggested` с низкой confidence,
 * для которых ParseOutboundQuoteJob уже успешно сматчил позиции
 * КП/счёта с RequestItem'ами заявки — но boost по какой-то причине
 * не сработал (race condition, AiDecision создан позже OutboundQuote,
 * исключение в boost-блоке, OpenAI 429 во время Job).
 *
 * Кейс M-2026-1558: detector выдал 0.6 на body-keyword «коммерческое
 * предложение», OutboundQuote #53 успешно матчил 1 позицию КП с
 * RequestItem'ом заявки. Boost не вызвался, заявка месяц висела
 * с подсказкой «Уверенность 60%, перевести в КП отправлено».
 *
 *   php artisan quotes:reboost-stuck-decisions               # dry-list
 *   php artisan quotes:reboost-stuck-decisions --apply       # реально буст + auto-apply
 *   php artisan quotes:reboost-stuck-decisions --apply --limit=100
 */
class QuotesReboostStuckDecisionsCommand extends Command
{
    protected $signature = 'quotes:reboost-stuck-decisions
        {--apply : Реально бустить и auto-apply (без флага — dry-list)}
        {--limit=50 : Сколько decisions обработать за прогон}
        {--since-hours=720 : Брать только за последние N часов (default 30 дней)}';

    protected $description = 'Бустит confidence у AiDecision, чей OutboundQuote успешно сматчил позиции (boost-fallback)';

    public function handle(AiDecisionService $aiService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $sinceHours = max(1, (int) $this->option('since-hours'));

        // OutboundQuote: matched + есть payload.match_stats.matched_request > 0.
        // Через raw JSON path (Postgres jsonb).
        $quotes = OutboundQuote::query()
            ->where('status', OutboundQuote::STATUS_MATCHED)
            ->whereNotNull('email_message_id')
            ->whereRaw("COALESCE((payload->'match_stats'->>'matched_request')::int, 0) > 0")
            ->where('created_at', '>=', now()->subHours($sinceHours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($quotes->isEmpty()) {
            $this->info('Нет matched OutboundQuote\'ов для проверки.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d OutboundQuote (mode: %s)…',
            $apply ? 'Обрабатываю' : 'Проверяю',
            $quotes->count(),
            $apply ? 'apply' : 'dry-list',
        ));

        $stats = ['checked' => 0, 'no_decision' => 0, 'already_high' => 0, 'boosted' => 0, 'auto_applied' => 0, 'errors' => 0];

        foreach ($quotes as $quote) {
            $stats['checked']++;
            $detectorType = $this->resolveDetectorType($quote);
            if (! $detectorType) {
                continue;
            }

            $decision = AiDecision::query()
                ->where('request_id', $quote->request_id)
                ->where('email_message_id', $quote->email_message_id)
                ->where('detector_type', $detectorType->value)
                ->where('status', AiDecisionStatus::Suggested->value)
                ->orderByDesc('id')
                ->first();

            if (! $decision) {
                $stats['no_decision']++;
                continue;
            }

            $oldConf = (float) $decision->confidence;
            if ($oldConf >= 0.95) {
                $stats['already_high']++;
                continue;
            }

            $line = sprintf(
                '  decision #%d (req #%d, %s) conf %.2f → 0.95',
                $decision->id,
                $decision->request_id,
                $detectorType->value,
                $oldConf,
            );

            if (! $apply) {
                $this->line($line . '  [dry]');
                continue;
            }

            try {
                DB::transaction(function () use ($decision, $oldConf, $quote) {
                    $payload = is_array($decision->payload) ? $decision->payload : [];
                    $payload['quote_parser_boost'] = [
                        'matched_request_count' => (int) data_get($quote->payload, 'match_stats.matched_request', 0),
                        'matched_catalog_count' => (int) data_get($quote->payload, 'match_stats.matched_catalog', 0),
                        'old_confidence' => $oldConf,
                        'new_confidence' => 0.95,
                        'boosted_at' => now()->toIso8601String(),
                        'boosted_by' => 'cli:quotes:reboost-stuck-decisions',
                    ];
                    $decision->update([
                        'confidence' => 0.95,
                        'payload' => $payload,
                    ]);
                });
                $stats['boosted']++;
                Log::info('quotes:reboost-stuck-decisions: boosted', [
                    'decision_id' => $decision->id,
                    'request_id' => $decision->request_id,
                    'old_confidence' => $oldConf,
                ]);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->line($line . '  ERROR: ' . $e->getMessage());
                continue;
            }

            // Auto-apply если разрешено настройками — та же логика что в
            // ParseOutboundQuoteJob::boostAiDecisionFromQuoteMatch.
            $autoEnabled = (bool) app_setting('detector.auto_mode.' . $detectorType->value, false);
            $threshold = (float) app_setting('detector.confidence_threshold', 0.85);
            if ($autoEnabled && 0.95 >= $threshold) {
                try {
                    $aiService->apply($decision->refresh(), null, ['auto' => true, 'source' => 'cli:reboost']);
                    $stats['auto_applied']++;
                    $this->line($line . '  → boosted + auto-applied');
                } catch (\Throwable $e) {
                    $this->line($line . '  → boosted, auto-apply FAIL: ' . $e->getMessage());
                    Log::warning('quotes:reboost-stuck-decisions: auto-apply failed (non-fatal)', [
                        'decision_id' => $decision->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->line($line . '  → boosted (auto-mode выкл, ждёт ручного apply)');
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

    /**
     * Преобразовать строковое значение OutboundQuote.document_type в enum.
     * Тот же mapping, что в ParseOutboundQuoteJob::boostAiDecisionFromQuoteMatch.
     */
    private function resolveDetectorType(OutboundQuote $quote): ?DetectorType
    {
        $value = $quote->document_type?->value ?? null;
        if (! $value) {
            return null;
        }
        return match ($value) {
            'outbound_quotation_full' => DetectorType::OutboundQuotationFull,
            'outbound_quotation_partial' => DetectorType::OutboundQuotationPartial,
            'outbound_invoice' => DetectorType::OutboundInvoice,
            default => null,
        };
    }
}
