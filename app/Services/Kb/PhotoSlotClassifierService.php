<?php

namespace App\Services\Kb;

use App\Models\EmailAttachment;
use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\IdentificationRule;
use App\Models\RequestItem;
use App\Prompts\Kb\PhotoClassifierPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Photo Classifier (2026-05-21): Vision-классификация фоток треда по
 * KB photo-slug'ам для конкретной позиции.
 *
 * Что делает:
 *  1. Берёт RequestItem, определяет его identification_category_id.
 *  2. Подтягивает все photo-type параметры (`value_type='photo'`),
 *     которые упомянуты в IdentificationRule'ах этой категории.
 *  3. Собирает изображения из всего треда (EmailMessage'ы с
 *     related_request_id == request_id).
 *  4. Делает один Vision-вызов с пачкой изображений + списком slug'ов.
 *  5. Для каждого matched изображения:
 *     - пишет в EmailAttachment.metadata.kb_slot_candidates[];
 *     - агрегирует в item.quality_assessment_payload.extracted_parameters[$slug] = true.
 *
 * Триггер: после QualityAssessmentService::assessItem(), в ResolveKbJob.
 * Идемпотентность: повторный вызов перезаписывает kb_slot_candidates для
 * данного request_item_id (старые матчи к этой же позиции стираются).
 */
class PhotoSlotClassifierService
{
    private const LLM_MODEL = 'gpt-4o';
    private const LLM_TEMPERATURE = 0.1;
    private const LLM_MAX_TOKENS = 2000;
    private const MAX_IMAGES_PER_CALL = 8; // OpenAI limit на content array
    private const CONFIDENCE_FLOOR = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {
    }

    /**
     * @return array{considered_photos: int, matched: int, slugs_covered: int}
     */
    public function classifyForItem(RequestItem $item): array
    {
        $photoSlots = $this->photoSlotsForItem($item);
        if (empty($photoSlots)) {
            return ['considered_photos' => 0, 'matched' => 0, 'slugs_covered' => 0];
        }

        $attachments = $this->collectImageAttachments($item);
        if ($attachments->isEmpty()) {
            return ['considered_photos' => 0, 'matched' => 0, 'slugs_covered' => 0];
        }

        // Ограничиваем пачку — модель не умеет много изображений за раз.
        $batch = $attachments->take(self::MAX_IMAGES_PER_CALL);

        [$imagesBase64, $attachmentIds] = $this->encodeImages($batch);
        if (empty($imagesBase64)) {
            return ['considered_photos' => 0, 'matched' => 0, 'slugs_covered' => 0];
        }

        $messages = PhotoClassifierPrompt::build(
            photoSlots: $photoSlots,
            imagesBase64: $imagesBase64,
            itemContext: [
                'parsed_name' => (string) $item->parsed_name,
                'parsed_brand' => (string) $item->parsed_brand,
                'parsed_article' => (string) $item->parsed_article,
                'category_name' => $item->kbCategory?->name,
            ],
        );

        try {
            $response = $this->openai->chat($messages, self::LLM_MODEL, [
                'response_format' => ['type' => 'json_object'],
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PhotoSlotClassifierService: LLM call failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return ['considered_photos' => count($imagesBase64), 'matched' => 0, 'slugs_covered' => 0];
        }

        $raw = (string) ($response['content'] ?? '');
        $parsed = json_decode($raw, true);
        $classifications = is_array($parsed['classifications'] ?? null) ? $parsed['classifications'] : [];

        // Сначала — почистить старые kb_slot_candidates для ЭТОЙ request_item_id
        // (идемпотентность: при повторном классифицировании старые матчи стираются).
        foreach ($batch as $att) {
            $this->stripOldCandidatesForItem($att, $item->id);
        }

        $matchedCount = 0;
        $matchedSlugs = [];

        foreach ($classifications as $c) {
            if (! is_array($c)) {
                continue;
            }
            $idx = (int) ($c['image_index'] ?? -1);
            $slug = is_string($c['slug'] ?? null) && trim($c['slug']) !== '' ? trim($c['slug']) : null;
            $confidence = (float) ($c['confidence'] ?? 0);
            $description = trim((string) ($c['description'] ?? ''));
            $status = (string) ($c['status'] ?? 'matched');

            if ($idx < 0 || $idx >= count($attachmentIds)) {
                continue;
            }
            $attachmentId = $attachmentIds[$idx];

            // Записываем в attachment metadata даже irrelevant/other —
            // это полезный аудит-след. Но для extracted_parameters
            // учитываем только matched + slug != null + confidence пройден.
            $candidate = [
                'request_item_id' => $item->id,
                'slug' => $slug,
                'confidence' => round($confidence, 2),
                'status' => $status,
                'description' => mb_substr($description, 0, 500),
                'classified_at' => now()->toIso8601String(),
                'classifier_version' => 'v1',
            ];
            $this->appendCandidateToAttachment($attachmentId, $candidate);

            if ($status === 'matched' && $slug !== null && $confidence >= self::CONFIDENCE_FLOOR) {
                $matchedCount++;
                $matchedSlugs[$slug] = true;
            }
        }

        // Агрегируем в item.quality_assessment_payload — для каждого
        // matched-slug ставим extracted_parameters[$slug] = true. Это
        // позволит PositionSlotResolver показать ✓ в UI.
        if (! empty($matchedSlugs)) {
            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            $extracted = is_array($payload['extracted_parameters'] ?? null) ? $payload['extracted_parameters'] : [];
            foreach (array_keys($matchedSlugs) as $slug) {
                $extracted[$slug] = true;
            }
            $payload['extracted_parameters'] = $extracted;
            $payload['photo_classifier_run_at'] = now()->toIso8601String();
            $item->quality_assessment_payload = $payload;
            $item->save();
        }

        Log::info('PhotoSlotClassifierService: done', [
            'item_id' => $item->id,
            'considered' => count($imagesBase64),
            'matched' => $matchedCount,
            'slugs_covered' => count($matchedSlugs),
            'usage' => $response['usage'] ?? null,
        ]);

        return [
            'considered_photos' => count($imagesBase64),
            'matched' => $matchedCount,
            'slugs_covered' => count($matchedSlugs),
        ];
    }

    /**
     * Достать список photo-параметров для категории item'а.
     * Делаем union по всем active IdentificationRule этой категории.
     *
     * @return array<int, array{slug: string, name: string, question_template: ?string}>
     */
    private function photoSlotsForItem(RequestItem $item): array
    {
        $categoryId = $item->identification_category_id;
        if (! $categoryId) {
            return [];
        }

        $rules = IdentificationRule::query()
            ->with('alternatives')
            ->where('category_id', (int) $categoryId)
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
     * Все image-аттачменты треда позиции. Если у item есть привязанный
     * image_attachment_id — ставим его первым (как «первичная фотка»).
     *
     * @return \Illuminate\Support\Collection<int, EmailAttachment>
     */
    private function collectImageAttachments(RequestItem $item): \Illuminate\Support\Collection
    {
        $requestId = $item->request_id;
        $messageIds = \App\Models\EmailMessage::query()
            ->where('related_request_id', $requestId)
            ->pluck('id')
            ->all();
        if (empty($messageIds)) {
            return collect();
        }

        $attachments = EmailAttachment::query()
            ->whereIn('email_message_id', $messageIds)
            ->where(function ($q) {
                $q->where('mime_type', 'like', 'image/%');
            })
            ->whereNotIn('mime_type', ['image/heic', 'image/heif']) // OpenAI Vision не ест HEIC
            ->get();

        // image_attachment_id — приоритет первым.
        if ($item->image_attachment_id) {
            $primary = $attachments->firstWhere('id', (int) $item->image_attachment_id);
            if ($primary) {
                $attachments = $attachments->reject(fn ($a) => $a->id === $primary->id)->prepend($primary);
            }
        }

        return $attachments;
    }

    /**
     * Считать файлы и собрать data:URI массив + параллельный массив id.
     *
     * @param  \Illuminate\Support\Collection<int, EmailAttachment>  $attachments
     * @return array{0: array<int, string>, 1: array<int, int>}
     */
    private function encodeImages(\Illuminate\Support\Collection $attachments): array
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
        /** @var EmailAttachment|null $att */
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
     * Перед записью новых результатов чистим старые матчи для этой
     * request_item_id — иначе при повторном классифицировании накопятся
     * дубли. Матчи для других позиций (если фото использовалось в разных
     * заявках/позициях) НЕ трогаем.
     */
    private function stripOldCandidatesForItem(EmailAttachment $att, int $itemId): void
    {
        $meta = is_array($att->metadata) ? $att->metadata : [];
        $list = is_array($meta['kb_slot_candidates'] ?? null) ? $meta['kb_slot_candidates'] : [];
        if (empty($list)) {
            return;
        }
        $kept = array_values(array_filter($list, fn ($c) => ! (is_array($c) && (int) ($c['request_item_id'] ?? 0) === $itemId)));
        if (count($kept) === count($list)) {
            return; // ничего не было — экономим save
        }
        $meta['kb_slot_candidates'] = $kept;
        $att->metadata = $meta;
        $att->save();
    }
}
