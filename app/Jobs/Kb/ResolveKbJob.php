<?php

namespace App\Jobs\Kb;

use App\Jobs\Concerns\OnHeavyQueue;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Services\Kb\PhotoSlotClassifierService;
use App\Services\Kb\QualityAssessmentService;
use App\Services\Kb\RequestContextAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KB-резолв для всей заявки целиком (Phase 2.0).
 *
 *   1. RequestContextAnalysisService::analyze($requestId)
 *      → создаёт RequestContext (один LLM-вызов на Request).
 *   2. Для каждой RequestItem заявки:
 *      QualityAssessmentService::assessItem($itemId)
 *      → enrichment (extractors → category → brand → unit) + evaluation
 *      → пишет identification_category_id, manufacturer_brand_id,
 *        equipment_unit_id, quality_assessment_status, quality_assessment_payload.
 *
 * Job невзрывной: любой LLM- или DB-сбой переводит позицию в статус
 * `assessment_failed` через QualityAssessmentService::assessItem (там try/catch),
 * RequestContext в статус `failed`. AssignmentService продолжает работать на
 * сырых parsed_*-полях.
 *
 * ShouldBeUnique на время задачи — защита от дублей если RequestItemPersister
 * случайно дёрнет дважды.
 *
 * Большая заявка (от queue.heavy.kb_items_threshold позиций) — в очередь heavy на 30 минут:
 * ТЗ на 186 позиций (ДМА, 09.11.2026) не укладывалось в 5 минут, и каждая попытка
 * начинала заново и снова обрывалась. Обычная — в общей очереди на 10 минут: в heavy
 * она ждала бы, пока воркеры заняты большими (09.11: заявки в одну позицию стояли по
 * 5+ минут за ДМА). Повтор продолжает с места обрыва: оценённые в этом прогоне позиции
 * и анализ контекста помнятся в кэше (progressKey) и пропускаются. Первая попытка
 * список очищает — после переразбора заявка оценивается целиком.
 *
 * Стоимость: ~$0.05–0.10 на Request (1 context + N items × refinement).
 */
class ResolveKbJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, OnHeavyQueue, Queueable, SerializesModels;

    /** Общая очередь — меньше её retry_after (960); большая заявка получает 1800 в heavy. */
    public int $timeout = 600;

    public int $tries = 3;

    public int $uniqueFor = 1800;

    /** Сколько помнить сделанное в прогоне: дольше всех попыток с паузами между ними. */
    private const PROGRESS_TTL_SECONDS = 4 * 3600;

    /** @param  int|null  $itemCount  позиций в заявке; не передан — считается по базе */
    public function __construct(public readonly int $requestId, ?int $itemCount = null)
    {
        $itemCount ??= RequestItem::query()->where('request_id', $requestId)->count();
        if ($itemCount >= (int) config('queue.heavy.kb_items_threshold', 40)) {
            $this->onHeavyQueue();
            $this->timeout = 1800;
        }
    }

    public static function progressKey(int $requestId): string
    {
        return 'kb-resolve:progress:'.$requestId;
    }

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    public function backoff(): array
    {
        // 30s, 2min, 5min — даём LLM-проксе время восстановиться при rate-limit.
        return [30, 120, 300];
    }

    public function handle(
        RequestContextAnalysisService $contextAnalyzer,
        QualityAssessmentService $assessor,
        PhotoSlotClassifierService $photoClassifier,
    ): void {
        $request = RequestModel::query()
            ->with('items:id,request_id,quality_assessment_status')
            ->find($this->requestId);

        if (! $request) {
            Log::warning('ResolveKbJob: request not found', ['request_id' => $this->requestId]);

            return;
        }

        // Первая попытка — прогон с нуля; повтор (оборвали по таймауту) продолжает
        // с места обрыва, а не начинает заново.
        $key = self::progressKey($this->requestId);
        if ($this->attempts() <= 1) {
            Cache::forget($key);
        }
        $progress = (array) Cache::get($key, []);
        $progress += ['context' => false, 'items' => []];
        $done = array_flip(array_map('intval', (array) $progress['items']));

        // Phase 1: RequestContext (LLM-анализ email body на equipment_units).
        if (! $progress['context']) {
            try {
                $contextAnalyzer->analyze($this->requestId);
            } catch (Throwable $e) {
                // Не валим job — context остаётся со status=failed, items
                // обработаются без context (brand resolver обойдётся без OEM
                // подсказок, unit matcher вернёт null).
                Log::warning('ResolveKbJob: context analysis failed (non-fatal)', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
            $progress['context'] = true;
            Cache::put($key, $progress, self::PROGRESS_TTL_SECONDS);
        }

        // Phase 2: per-item assessment. Идемпотентно — assessItem перезаписывает
        // status и payload каждый раз.
        foreach ($request->items as $item) {
            if (isset($done[$item->id])) {
                continue;
            }
            try {
                $assessor->assessItem($item->id);
            } catch (Throwable $e) {
                // QualityAssessmentService::assessItem уже ловит большинство
                // ошибок и пишет assessment_failed. Снаружи ловим только
                // фатальные DB-сбои.
                Log::error('ResolveKbJob: assess failed for item (fatal)', [
                    'request_id' => $this->requestId,
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $progress['items'][] = $item->id;
            Cache::put($key, $progress, self::PROGRESS_TTL_SECONDS);
        }

        // Phase 3 (2026-05-21, v2 photo-centric): Photo classifier —
        // ОДИН Vision-вызов на всю заявку. Модель получает целостный
        // контекст (все позиции + все фотки треда) и сама раскладывает
        // фотки между позициями и photo-slug'ами. Раньше (v1) был
        // item-centric — N вызовов, каждая фотка анализировалась N раз,
        // были противоречия. Non-fatal: Vision-сбой не валит KB-resolve.
        try {
            $photoClassifier->classifyForRequest($request->fresh(['items']));
        } catch (Throwable $e) {
            Log::warning('ResolveKbJob: photo classifier failed (non-fatal)', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
            ]);
        }

        Cache::forget($key);

        Log::info('ResolveKbJob: done', [
            'request_id' => $this->requestId,
            'items' => $request->items->count(),
            'resumed_after' => count($done),
            'attempt' => $this->attempts(),
        ]);
    }
}
