<?php

namespace App\Services\Kb;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\IdentificationRule;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Prompts\Kb\PhotoClassifierPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Photo Classifier v2 (2026-05-21, photo-centric): один Vision-вызов на
 * всю заявку. Раньше (v1) был item-centric — N вызовов на позицию,
 * каждая фотка анализировалась N раз. v2 даёт модели целостный контекст.
 *
 * Что делает classifyForRequest(Request):
 *  1. Собирает image-attachments всего треда (related_request_id).
 *  2. Собирает список активных позиций с identification_category_id +
 *     photo-slug'ами их категорий (через IdentificationRule.alternatives
 *     .required_parameter_ids + value_type='photo').
 *  3. Если ни фоток, ни позиций с photo-слотами — no-op.
 *  4. Один Vision-вызов с пачкой изображений + массивом позиций.
 *  5. Для каждой matched-сборки image+item+slug:
 *      · пишет в EmailAttachment.metadata.kb_slot_candidates[];
 *      · агрегирует в RequestItem.quality_assessment_payload
 *        .extracted_parameters[$slug] = true.
 *  6. Метаданные attachment'а пишутся ВСЕГДА (включая irrelevant/other)
 *     для аудит-следа.
 *
 * Идемпотентность: при повторе сначала стираем kb_slot_candidates всех
 * attachment'ов треда (от любых request_item этой заявки) — это
 * корректный сброс, т.к. сейчас классификация делается на уровне заявки.
 *
 * Метод classifyForItem(RequestItem) сохранён для CLI photo:classify —
 * под капотом вызывает classifyForRequest($item->request) и фильтрует
 * результат для одного item.
 */
class PhotoSlotClassifierService
{
    private const LLM_MODEL = 'gpt-4o';
    private const LLM_TEMPERATURE = 0.1;
    private const LLM_MAX_TOKENS = 3000;
    private const MAX_IMAGES_PER_CALL = 12; // OpenAI limit на content array
    private const MAX_ITEMS_PER_CALL = 8;   // прагматический лимит
    private const CONFIDENCE_FLOOR = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {
    }

    /**
     * Главный entry-point: классифицировать все фото треда заявки.
     *
     * @return array{considered_photos: int, items_with_slots: int, matched: int, slugs_covered: int, vision_called: bool}
     */
    public function classifyForRequest(RequestModel $request): array
    {
        $itemsWithSlots = $this->collectItemsWithSlots($request);
        $attachments = $this->collectImageAttachmentsForRequest($request);

        if ($itemsWithSlots->isEmpty() || $attachments->isEmpty()) {
            // Нет ни позиций с photo-слотами, ни фоток — нечего делать.
            return [
                'considered_photos' => $attachments->count(),
                'items_with_slots' => $itemsWithSlots->count(),
                'matched' => 0,
                'slugs_covered' => 0,
                'vision_called' => false,
            ];
        }

        // Ограничиваем размер пачки.
        $batchAttachments = $attachments->take(self::MAX_IMAGES_PER_CALL);
        $batchItems = $itemsWithSlots->take(self::MAX_ITEMS_PER_CALL);

        [$imagesBase64, $attachmentIds] = $this->encodeImages($batchAttachments);
        if (empty($imagesBase64)) {
            return [
                'considered_photos' => 0,
                'items_with_slots' => $itemsWithSlots->count(),
                'matched' => 0,
                'slugs_covered' => 0,
                'vision_called' => false,
            ];
        }

        // Подготовка items для промпта (item_index = индекс в массиве).
        $itemsForPrompt = $batchItems
            ->values()
            ->map(fn (array $row, int $i) => [
                'position_id' => $row['item']->position,
                'item_index' => $i,
                'name' => (string) $row['item']->parsed_name,
                'brand' => $row['item']->parsed_brand,
                'article' => $row['item']->parsed_article,
                'category_name' => $row['item']->kbCategory?->name,
                'photo_slots' => $row['photo_slots'],
            ])
            ->all();

        // Параллельный массив: item_index → RequestItem (для записи payload).
        $itemByIndex = $batchItems->values()->map(fn ($row) => $row['item'])->all();

        $messages = PhotoClassifierPrompt::build($itemsForPrompt, $imagesBase64);

        try {
            $response = $this->openai->chat($messages, self::LLM_MODEL, [
                'response_format' => ['type' => 'json_object'],
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PhotoSlotClassifierService: LLM call failed', [
                'request_id' => $request->id,
                'images' => count($imagesBase64),
                'items' => count($itemsForPrompt),
                'error' => $e->getMessage(),
            ]);
            return [
                'considered_photos' => count($imagesBase64),
                'items_with_slots' => $itemsWithSlots->count(),
                'matched' => 0,
                'slugs_covered' => 0,
                'vision_called' => true,
            ];
        }

        $raw = (string) ($response['content'] ?? '');
        $parsed = json_decode($raw, true);
        $assignments = is_array($parsed['assignments'] ?? null) ? $parsed['assignments'] : [];

        // Idempotent: чистим старые kb_slot_candidates всех attachment'ов
        // батча — они принадлежат этой же заявке, перезаписываем заново.
        foreach ($batchAttachments as $att) {
            $this->resetCandidatesForRequest($att, $request->id);
        }

        $matchedCount = 0;
        $matchedSlugsPerItem = []; // [item_id => [slug => true]]

        foreach ($assignments as $a) {
            if (! is_array($a)) {
                continue;
            }
            $imgIdx = (int) ($a['image_index'] ?? -1);
            $itemIdx = isset($a['item_index']) && $a['item_index'] !== null ? (int) $a['item_index'] : null;
            $slug = is_string($a['slug'] ?? null) && trim($a['slug']) !== '' ? trim($a['slug']) : null;
            $confidence = (float) ($a['confidence'] ?? 0);
            $description = trim((string) ($a['description'] ?? ''));
            $status = (string) ($a['status'] ?? 'matched');

            if ($imgIdx < 0 || $imgIdx >= count($attachmentIds)) {
                continue;
            }
            $attachmentId = $attachmentIds[$imgIdx];
            $targetItem = ($itemIdx !== null && isset($itemByIndex[$itemIdx])) ? $itemByIndex[$itemIdx] : null;
            $targetItemId = $targetItem?->id;

            $candidate = [
                'request_id' => $request->id,
                'request_item_id' => $targetItemId,
                'slug' => $slug,
                'confidence' => round($confidence, 2),
                'status' => $status,
                'description' => mb_substr($description, 0, 500),
                'classified_at' => now()->toIso8601String(),
                'classifier_version' => 'v2-photo-centric',
            ];
            $this->appendCandidateToAttachment($attachmentId, $candidate);

            $isMatched = $status === 'matched'
                && $slug !== null
                && $targetItemId !== null
                && $confidence >= self::CONFIDENCE_FLOOR;
            if ($isMatched) {
                $matchedCount++;
                $matchedSlugsPerItem[$targetItemId][$slug] = true;
            }
        }

        // Агрегируем в items.quality_assessment_payload.extracted_parameters.
        foreach ($matchedSlugsPerItem as $itemId => $slugs) {
            $item = RequestItem::find((int) $itemId);
            if (! $item) {
                continue;
            }
            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            $extracted = is_array($payload['extracted_parameters'] ?? null) ? $payload['extracted_parameters'] : [];
            foreach (array_keys($slugs) as $slug) {
                $extracted[$slug] = true;
            }
            $payload['extracted_parameters'] = $extracted;
            $payload['photo_classifier_run_at'] = now()->toIso8601String();
            $item->quality_assessment_payload = $payload;
            $item->save();
        }

        Log::info('PhotoSlotClassifierService: done', [
            'request_id' => $request->id,
            'considered_photos' => count($imagesBase64),
            'items_with_slots' => $itemsWithSlots->count(),
            'matched' => $matchedCount,
            'slugs_covered' => array_sum(array_map('count', $matchedSlugsPerItem)),
            'usage' => $response['usage'] ?? null,
        ]);

        return [
            'considered_photos' => count($imagesBase64),
            'items_with_slots' => $itemsWithSlots->count(),
            'matched' => $matchedCount,
            'slugs_covered' => array_sum(array_map('count', $matchedSlugsPerItem)),
            'vision_called' => true,
        ];
    }

    /**
     * Совместимость с CLI photo:classify {item_id}. Запускает
     * classifyForRequest и возвращает срез статистики для одной позиции.
     *
     * @return array{considered_photos: int, matched: int, slugs_covered: int}
     */
    public function classifyForItem(RequestItem $item): array
    {
        $request = $item->request;
        if (! $request) {
            return ['considered_photos' => 0, 'matched' => 0, 'slugs_covered' => 0];
        }
        $full = $this->classifyForRequest($request);
        // Сводим к ракурсу одной позиции: посчитать сколько слотов и
        // фоток приписано именно этой позиции.
        $matched = 0;
        $slugs = [];
        $fresh = $item->fresh();
        $payload = is_array($fresh?->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
        $extracted = is_array($payload['extracted_parameters'] ?? null) ? $payload['extracted_parameters'] : [];
        foreach ($extracted as $k => $v) {
            if (str_starts_with($k, 'photo_') && $v === true) {
                $slugs[$k] = true;
            }
        }
        // matched-фото для этой позиции — считаем по kb_slot_candidates.
        $msgIds = EmailMessage::where('related_request_id', $request->id)->pluck('id');
        $atts = EmailAttachment::whereIn('email_message_id', $msgIds)->get(['metadata']);
        foreach ($atts as $a) {
            $cands = is_array($a->metadata['kb_slot_candidates'] ?? null) ? $a->metadata['kb_slot_candidates'] : [];
            foreach ($cands as $c) {
                if (is_array($c)
                    && (int) ($c['request_item_id'] ?? 0) === $item->id
                    && ($c['status'] ?? null) === 'matched') {
                    $matched++;
                }
            }
        }
        return [
            'considered_photos' => $full['considered_photos'],
            'matched' => $matched,
            'slugs_covered' => count($slugs),
        ];
    }

    /**
     * Активные позиции заявки + photo-параметры их категорий.
     *
     * @return Collection<int, array{item: RequestItem, photo_slots: array<int, array{slug: string, name: string, question_template: ?string}>}>
     */
    private function collectItemsWithSlots(RequestModel $request): Collection
    {
        $items = $request->items()
            ->where('is_active', true)
            ->whereNotNull('identification_category_id')
            ->with('kbCategory')
            ->orderBy('position')
            ->get();

        $result = collect();
        foreach ($items as $item) {
            $photoSlots = $this->photoSlotsForCategory((int) $item->identification_category_id);
            if (empty($photoSlots)) {
                continue;
            }
            $result->push(['item' => $item, 'photo_slots' => $photoSlots]);
        }
        return $result;
    }

    /**
     * Все image-аттачменты треда. HEIC/HEIF пропускаем (Vision не ест).
     *
     * @return Collection<int, EmailAttachment>
     */
    private function collectImageAttachmentsForRequest(RequestModel $request): Collection
    {
        $messageIds = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->pluck('id');
        if ($messageIds->isEmpty()) {
            return collect();
        }
        return EmailAttachment::query()
            ->whereIn('email_message_id', $messageIds)
            ->where('mime_type', 'like', 'image/%')
            ->whereNotIn('mime_type', ['image/heic', 'image/heif'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{slug: string, name: string, question_template: ?string}>
     */
    private function photoSlotsForCategory(int $categoryId): array
    {
        $rules = IdentificationRule::query()
            ->with('alternatives')
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->get();

        $paramIds = [];
        foreach ($rules as $rule) {
            foreach ($rule->alternatives as $alt) {
                $ids = is_array($alt->required_parameter_ids) ? $alt->required_parameter_ids : [];
                foreach ($ids as $id) {
                    $paramIds[(int) $id] = true;
                }
            }
        }
        if (empty($paramIds)) {
            return [];
        }
        return IdentificationParameter::query()
            ->whereIn('id', array_keys($paramIds))
            ->where('value_type', 'photo')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['slug', 'name', 'question_template'])
            ->map(fn (IdentificationParameter $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'question_template' => $p->question_template,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, EmailAttachment>  $attachments
     * @return array{0: array<int, string>, 1: array<int, int>}
     */
    private function encodeImages(Collection $attachments): array
    {
        $images = [];
        $ids = [];
        foreach ($attachments as $att) {
            try {
                $content = Storage::disk($att->disk ?: 'local')->get($att->file_path);
            } catch (\Throwable $e) {
                Log::warning('PhotoSlotClassifierService: image read failed', [
                    'attachment_id' => $att->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if ($content === null || $content === '') {
                continue;
            }
            $mime = $att->mime_type ?: 'image/jpeg';
            $images[] = 'data:'.$mime.';base64,'.base64_encode($content);
            $ids[] = (int) $att->id;
        }
        return [$images, $ids];
    }

    private function appendCandidateToAttachment(int $attachmentId, array $candidate): void
    {
        $att = EmailAttachment::find($attachmentId);
        if (! $att) {
            return;
        }
        $meta = is_array($att->metadata) ? $att->metadata : [];
        $list = is_array($meta['kb_slot_candidates'] ?? null) ? $meta['kb_slot_candidates'] : [];
        $list[] = $candidate;
        $meta['kb_slot_candidates'] = $list;
        $meta['vision_classified_at'] = now()->toIso8601String();
        $att->metadata = $meta;
        $att->save();
    }

    /**
     * При перепрогоне на заявку — стираем все kb_slot_candidates этой
     * заявки (по request_id), оставляя только от других заявок (если
     * фото каким-то образом фигурирует в нескольких заявках).
     */
    private function resetCandidatesForRequest(EmailAttachment $att, int $requestId): void
    {
        $meta = is_array($att->metadata) ? $att->metadata : [];
        $list = is_array($meta['kb_slot_candidates'] ?? null) ? $meta['kb_slot_candidates'] : [];
        if (empty($list)) {
            return;
        }
        $kept = array_values(array_filter($list, function ($c) use ($requestId) {
            if (! is_array($c)) {
                return true;
            }
            // Старый v1 не писал request_id — у тех записей чистим всегда
            // (это первая v2-перезапись, лучше переписать).
            if (! array_key_exists('request_id', $c)) {
                return false;
            }
            return (int) $c['request_id'] !== $requestId;
        }));
        if (count($kept) === count($list)) {
            return;
        }
        $meta['kb_slot_candidates'] = $kept;
        $att->metadata = $meta;
        $att->save();
    }
}
