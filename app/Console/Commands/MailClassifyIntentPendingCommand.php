<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\DocumentDetector\InboundIntentClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Догоняющая классификация интента входящих ответов клиента, чья
 * классификация пролетела в окно сбоя AI (Foundation §7.2). InboundIntentClassifier
 * fail-safe: при 429/quota возвращает null и НЕ ставит intent_classified_at →
 * заявка может зависнуть в Quoted, хотя клиент ответил «на согласовании» /
 * «выставите счёт» / «отказываемся». Этот крон находит такие письма (привязаны
 * к заявке, без отметки и без inbound-решения, свежие) и повторяет разбор —
 * транзиентные сбои само-залечиваются. Кейс M-2026-2302 (OpenAI 429 15.06).
 *
 *   php artisan mail:classify-intent-pending --dry-run
 *   php artisan mail:classify-intent-pending --limit=100 --days=3
 */
class MailClassifyIntentPendingCommand extends Command
{
    protected $signature = 'mail:classify-intent-pending
        {--limit=100 : Максимум писем за прогон}
        {--days=3 : Брать письма не старше N дней (без исторического бэкфилла)}
        {--dry-run : Только показать кандидатов, без LLM и применения}';

    protected $description = 'Догнать классификацию интента входящих, пролетевших в окно сбоя AI';

    public function handle(InboundIntentClassifier $classifier, AiDecisionService $aiDecisions): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');

        $candidates = EmailMessage::query()
            ->where('direction', 'inbound')
            ->whereNotNull('related_request_id')
            ->whereNull('intent_classified_at')
            ->where('created_at', '>=', now()->subDays($days))
            // Уже есть inbound-решение (классифицировано до появления отметки) —
            // не трогаем (защита исторических данных от повторного применения).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('ai_decisions')
                    ->whereColumn('ai_decisions.email_message_id', 'email_messages.id')
                    ->where('ai_decisions.detector_type', 'like', 'inbound_%');
            })
            ->with('request')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Нет писем для догоняющей классификации интента.');

            return self::SUCCESS;
        }

        $stats = ['scanned' => 0, 'applied' => 0, 'no_action' => 0, 'not_applicable' => 0, 'failed' => 0];

        foreach ($candidates as $m) {
            $stats['scanned']++;
            $request = $m->request;
            if ($request === null) {
                continue;
            }

            if (! $classifier->isApplicable($request)) {
                // Заявка уже вне eligible-статусов — классифицировать нечего;
                // помечаем, чтобы не перебирать снова.
                $stats['not_applicable']++;
                if (! $dry) {
                    EmailMessage::query()->whereKey($m->id)->update(['intent_classified_at' => now()]);
                }
                continue;
            }

            if ($dry) {
                $this->line(sprintf('  [dry] msg#%d → %s (%s): %s',
                    $m->id, $request->internal_code, $request->status->value,
                    mb_substr(trim((string) ($m->subject ?? '')), 0, 40)));
                continue;
            }

            try {
                // classify() сам проставит intent_classified_at при успешном
                // ответе LLM (включая unclear). При 429 вернёт null без отметки
                // → попадёт в следующий прогон.
                $res = $classifier->classify($m->fresh(), $request);
                if ($res !== null && ($res['type'] ?? null) !== null) {
                    $aiDecisions->recordSuggestion(
                        $res['type'], $request, $m->fresh(), (float) $res['confidence'], $res['payload'],
                    );
                    $stats['applied']++;
                } else {
                    // null = транзиентный сбой (не отмечено, повторим) ИЛИ
                    // unclear/new_request (отмечено в classify, повторять не будем).
                    $stats['no_action']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::warning('mail:classify-intent-pending: classify failed', [
                    'email_message_id' => $m->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($dry) {
            $this->info("Кандидатов: {$stats['scanned']} (показаны выше).");
        } else {
            $this->table(['metric', 'value'], collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());
        }

        return self::SUCCESS;
    }
}
