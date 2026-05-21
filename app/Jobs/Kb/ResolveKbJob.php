<?php

namespace App\Jobs\Kb;

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
 * ShouldBeUnique на 5 мин — защита от дублей если RequestItemPersister
 * случайно дёрнет дважды.
 *
 * Стоимость: ~$0.05–0.10 на Request (1 context + N items × refinement).
 */
class ResolveKbJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 минут на всю заявку — обычно хватает с большим запасом

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $requestId)
    {
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

        // Phase 1: RequestContext (LLM-анализ email body на equipment_units).
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

        // Phase 2: per-item assessment. Идемпотентно — assessItem перезаписывает
        // status и payload каждый раз.
        foreach ($request->items as $item) {
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
        }

        // Phase 3 (2026-05-21): Photo classifier — Vision-проход по фоткам
        // треда для каждой позиции, если у её категории есть photo-slot'ы.
        // Свой try/catch на каждую позицию, чтобы Vision-таймаут одной
        // позиции не блокировал остальные. Внутри сервис сам ничего не
        // запустит если у item нет identification_category_id или нет
        // image-attachment'ов в треде — это дешёвый no-op.
        foreach ($request->items as $itemStub) {
            try {
                $fresh = RequestItem::with('kbCategory')->find($itemStub->id);
                if ($fresh === null) {
                    continue;
                }
                $photoClassifier->classifyForItem($fresh);
            } catch (Throwable $e) {
                Log::warning('ResolveKbJob: photo classifier failed (non-fatal)', [
                    'request_id' => $this->requestId,
                    'item_id' => $itemStub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ResolveKbJob: done', [
            'request_id' => $this->requestId,
            'items' => $request->items->count(),
        ]);
    }
}
