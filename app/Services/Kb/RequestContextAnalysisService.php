<?php

namespace App\Services\Kb;

use App\Models\Kb\RequestContext;
use App\Models\Request;
use App\Models\RequestItem;
use App\Prompts\Kb\RequestContextAnalysisPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Документ 2 §5: извлечение и сохранение контекста заявки целиком.
 *
 * Идемпотентен — повторный вызов перезаписывает результат.
 */
class RequestContextAnalysisService
{
    private const LLM_MODEL = 'gpt-4o';
    private const LLM_TEMPERATURE = 0.1;
    private const LLM_MAX_TOKENS = 2000;
    private const PARTIAL_CONFIDENCE_THRESHOLD = 0.5;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly SupplierResolverService $resolver,
    ) {}

    public function analyze(int $requestId): RequestContext
    {
        /** @var Request $request */
        $request = Request::with('items')->findOrFail($requestId);

        $context = RequestContext::firstOrNew(['request_id' => $request->id]);
        $context->analysis_status = 'pending';
        $context->error_message = null;
        $context->save();

        $itemsBrief = $request->items
            ->sortBy('position')
            ->values()
            ->map(fn (RequestItem $i) => [
                'parsed_name' => (string) $i->parsed_name,
                'parsed_qty' => (float) ($i->parsed_qty ?? 1),
                'parsed_unit' => (string) ($i->parsed_unit ?? 'шт.'),
            ])
            ->all();

        try {
            $messages = RequestContextAnalysisPrompt::build(
                (string) ($request->source_body ?? ''),
                $request->source_subject,
                $itemsBrief
            );

            $response = $this->openai->chat($messages, self::LLM_MODEL, [
                'response_format' => ['type' => 'json_object'],
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
            ]);

            $rawContent = (string) ($response['content'] ?? '');
            $parsed = $this->safeJsonDecode($rawContent);

            if ($parsed === null) {
                $this->markFailed($context, 'Failed to parse LLM JSON response', $rawContent);
                Log::warning('RequestContextAnalysisService: invalid JSON', [
                    'request_id' => $request->id,
                    'raw_content_excerpt' => mb_substr($rawContent, 0, 500),
                ]);
                return $context;
            }

            $equipmentUnits = $this->normalizeEquipmentUnits($parsed['equipment_units'] ?? []);
            $mentionedSources = $this->resolveMentionedSources($parsed['mentioned_sources'] ?? []);
            $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];

            $assignments = is_array($parsed['position_to_unit_assignments'] ?? null)
                ? $parsed['position_to_unit_assignments']
                : [];

            $hasLowConfidence = $this->hasLowConfidence($equipmentUnits);

            DB::transaction(function () use (
                $context,
                $request,
                $equipmentUnits,
                $mentionedSources,
                $metadata,
                $assignments,
                $rawContent,
                $hasLowConfidence
            ) {
                $context->equipment_units = $equipmentUnits;
                $context->mentioned_sources = $mentionedSources;
                $context->metadata = $metadata;
                $context->llm_raw_response = ['content' => $rawContent];
                $context->llm_model_version = self::LLM_MODEL;
                $context->analyzed_at = now();
                $context->analysis_status = $hasLowConfidence ? 'partial' : 'completed';
                $context->error_message = null;
                $context->save();

                $this->propagateAssignmentsToItems($request, $equipmentUnits, $assignments);
            });

            Log::info('RequestContextAnalysisService: completed', [
                'request_id' => $request->id,
                'equipment_units_count' => count($equipmentUnits),
                'mentioned_sources_count' => count($mentionedSources),
                'status' => $context->analysis_status,
            ]);

            return $context;
        } catch (Throwable $e) {
            $this->markFailed($context, $e->getMessage(), null);
            Log::error('RequestContextAnalysisService: failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
            return $context;
        }
    }

    private function safeJsonDecode(string $content): ?array
    {
        try {
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($parsed) ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Гарантирует наличие id у каждой единицы; чистит лишнее.
     *
     * @param mixed $units
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEquipmentUnits($units): array
    {
        if (!is_array($units)) {
            return [];
        }

        $normalized = [];
        foreach ($units as $idx => $u) {
            if (!is_array($u)) {
                continue;
            }
            $id = isset($u['id']) && is_string($u['id']) && $u['id'] !== ''
                ? $u['id']
                : 'unit_' . ($idx + 1);
            $normalized[] = [
                'id' => $id,
                'type' => $u['type'] ?? null,
                'label' => $u['label'] ?? null,
                'brand' => $u['brand'] ?? null,
                'model' => $u['model'] ?? null,
                'series' => $u['series'] ?? null,
                'drive_type' => $u['drive_type'] ?? null,
                'object_address' => $u['object_address'] ?? null,
                'capacity_kg' => $u['capacity_kg'] ?? null,
                'speed_mps' => $u['speed_mps'] ?? null,
                'stops_count' => $u['stops_count'] ?? null,
                'raw_mention' => $u['raw_mention'] ?? null,
                'confidence' => isset($u['confidence']) ? (float) $u['confidence'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $sources
     * @return array<int, array<string, mixed>>
     */
    private function resolveMentionedSources($sources): array
    {
        if (!is_array($sources)) {
            return [];
        }

        $resolved = [];
        foreach ($sources as $s) {
            if (!is_array($s)) {
                continue;
            }
            $supplierId = $this->resolver->resolve($s);
            $entry = $s;
            if ($supplierId !== null) {
                $entry['supplier_id'] = $supplierId;
            }
            $resolved[] = $entry;
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $units
     */
    private function hasLowConfidence(array $units): bool
    {
        foreach ($units as $u) {
            if (isset($u['confidence']) && (float) $u['confidence'] < self::PARTIAL_CONFIDENCE_THRESHOLD) {
                return true;
            }
        }
        return false;
    }

    /**
     * Распространяет привязки позиций на request_items.equipment_unit_id.
     *
     * Алгоритм (документ 2 §5.3):
     *  1. Если LLM явно указал привязку — используем её.
     *  2. Если у заявки одна единица оборудования — все позиции к ней.
     *  3. Иначе оставляем null (уровень 2 разберётся).
     *
     * @param array<int, array<string, mixed>> $equipmentUnits
     * @param array<int, mixed> $assignments
     */
    private function propagateAssignmentsToItems(Request $request, array $equipmentUnits, array $assignments): void
    {
        $unitIds = array_column($equipmentUnits, 'id');
        $singleUnitId = count($unitIds) === 1 ? $unitIds[0] : null;

        // Индекс позиций по 0-based position_index (LLM считает с 0)
        $itemsByIndex = $request->items->sortBy('position')->values()->all();

        // 1) Прямые привязки от LLM
        $directlyAssigned = [];
        foreach ($assignments as $a) {
            if (!is_array($a)) {
                continue;
            }
            $idx = $a['position_index'] ?? null;
            $unitId = $a['unit_id'] ?? null;
            if (!is_int($idx) || !is_string($unitId) || !in_array($unitId, $unitIds, true)) {
                continue;
            }
            if (!isset($itemsByIndex[$idx])) {
                continue;
            }
            /** @var RequestItem $item */
            $item = $itemsByIndex[$idx];
            $item->equipment_unit_id = $unitId;
            $item->save();
            $directlyAssigned[$item->id] = true;
        }

        // 2) Если в заявке одна единица — присвоить остальным
        if ($singleUnitId !== null) {
            foreach ($itemsByIndex as $item) {
                if (isset($directlyAssigned[$item->id])) {
                    continue;
                }
                if ($item->equipment_unit_id !== $singleUnitId) {
                    $item->equipment_unit_id = $singleUnitId;
                    $item->save();
                }
            }
        }
    }

    private function markFailed(RequestContext $context, string $errorMessage, ?string $rawContent): void
    {
        $context->analysis_status = 'failed';
        $context->error_message = $errorMessage;
        if ($rawContent !== null) {
            $context->llm_raw_response = ['content' => $rawContent];
        }
        $context->llm_model_version = self::LLM_MODEL;
        $context->analyzed_at = now();
        $context->save();
    }
}
