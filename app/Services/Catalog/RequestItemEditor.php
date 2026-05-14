<?php

namespace App\Services\Catalog;

use App\Enums\QualityAssessmentStatus;
use App\Enums\Role as RoleEnum;
use App\Models\CatalogItem;
use App\Models\EmailAttachment;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручные действия оператора над позициями заявки (Priority 1).
 *
 * Все операции:
 *  - проходят authorization (assigned manager заявки + privileged-роли);
 *  - пишут запись в quality_assessment_payload.manual_edits[] (audit trail);
 *  - идемпотентны где возможно (linkToCatalog на тот же SKU — no-op).
 *
 * Audit-формат:
 *   {
 *     action: edit|soft_delete|restore|unbind|link|refresh|mark_not_found,
 *     field?: parsed_qty|parsed_name|...,
 *     old: mixed,
 *     new: mixed,
 *     by_user_id: int,
 *     by_name: string,
 *     at: ISO-8601,
 *   }
 * FIFO truncate до MAX_AUDIT_ENTRIES записей чтобы payload не разрастался.
 */
class RequestItemEditor
{
    public const MAX_AUDIT_ENTRIES = 50;

    /** Whitelist полей для editFields(). */
    public const EDITABLE_FIELDS = [
        'parsed_name',
        'parsed_brand',
        'parsed_article',
        'parsed_qty',
        'parsed_unit',
        'supplier_note',
    ];

    public function __construct(
        private readonly CatalogResolutionService $resolver,
        private readonly CatalogEmbeddingService $embedder,
    ) {
    }

    /**
     * Top-N похожих позиций каталога для UI-режима «Похожие» в
     * ItemCatalogLinkDialog. Без LLM/safety-фильтров — preview, оператор
     * сам выбирает что привязать (через `linkToCatalog`).
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float}>
     */
    public function findSimilar(RequestItem $item, User $author, int $limit = 10): array
    {
        $this->ensureCanEdit($item, $author);
        return $this->embedder->topNByRequestItem($item, $limit);
    }

    /**
     * Bulk re-match всех позиций заявки. Для каждой active-позиции:
     *  - не трогаем `internal_catalog_not_found` (оператор подтвердил, что
     *    SKU не появится — bulk не должен пересматривать без явного refresh);
     *  - сбрасываем catalog_item_id если был и пробуем заново matchOrResolve.
     *
     * Используется после массового редактирования названий: оператор
     * правит несколько позиций, нажимает «Refresh всех» — система пытается
     * подобрать каталожный аналог по новым данным.
     *
     * @return array{checked: int, matched: int, unchanged: int, skipped: int}
     */
    public function rematchAll(\App\Models\Request $request, User $author): array
    {
        // Авторизация — assigned manager + privileged. Проверяем через
        // первую active-позицию (всё внутри одной заявки).
        $firstItem = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->first();
        if ($firstItem !== null) {
            $this->ensureCanEdit($firstItem, $author);
        }

        $stats = ['checked' => 0, 'matched' => 0, 'unchanged' => 0, 'skipped' => 0];

        RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->chunkById(50, function ($items) use ($author, &$stats) {
                foreach ($items as $item) {
                    $stats['checked']++;
                    if ($item->quality_assessment_status === QualityAssessmentStatus::InternalCatalogNotFound->value) {
                        $stats['skipped']++;
                        continue;
                    }

                    $oldCatalogId = $item->catalog_item_id;
                    // Сбрасываем привязку (если была), чтобы matchOrResolve
                    // действительно пересчитал по новым parsed_name/article.
                    if ($oldCatalogId !== null) {
                        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
                        if (! empty($payload['catalog_match'])) {
                            $payload['previous_catalog_match'] = $payload['catalog_match'];
                        }
                        unset($payload['catalog_match'], $payload['catalog']);
                        $item->quality_assessment_payload = $payload;
                        $item->catalog_item_id = null;
                        $item->save();
                    }

                    $matched = $this->resolver->matchOrResolve($item->fresh());
                    $item->refresh();

                    $this->appendAudit($item, [
                        'action' => 'bulk_rematch',
                        'old' => ['catalog_item_id' => $oldCatalogId],
                        'new' => ['matched' => $matched, 'catalog_item_id' => $item->catalog_item_id],
                    ], $author);
                    $item->save();

                    if ($matched) {
                        $stats['matched']++;
                    } else {
                        $stats['unchanged']++;
                    }
                }
            });

        Log::info('RequestItemEditor: bulk rematch done', [
            'request_id' => $request->id,
            'stats' => $stats,
            'by' => $author->id,
        ]);

        return $stats;
    }

    /**
     * Inline / modal edit. $fields — ассоциативный массив, только из EDITABLE_FIELDS.
     *
     * @param  array<string, mixed>  $fields
     */
    public function editFields(RequestItem $item, array $fields, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $changes = [];
        foreach ($fields as $field => $value) {
            if (! in_array($field, self::EDITABLE_FIELDS, true)) {
                continue;
            }
            $oldValue = $item->getAttribute($field);
            $newValue = $this->normalizeFieldValue($field, $value);
            if ($this->valuesEqual($oldValue, $newValue)) {
                continue;
            }
            $item->setAttribute($field, $newValue);
            $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
        }

        if ($changes === []) {
            return $item;
        }

        DB::transaction(function () use ($item, $changes, $author) {
            foreach ($changes as $field => $diff) {
                $this->appendAudit($item, [
                    'action' => 'edit',
                    'field' => $field,
                    'old' => $diff['old'],
                    'new' => $diff['new'],
                ], $author);
            }
            $item->save();
        });

        Log::info('RequestItemEditor: fields edited', [
            'request_item_id' => $item->id,
            'fields' => array_keys($changes),
            'by' => $author->id,
        ]);

        return $item;
    }

    public function softDelete(RequestItem $item, User $author): void
    {
        $this->ensureCanEdit($item, $author);
        if (! $item->is_active) {
            return;
        }

        DB::transaction(function () use ($item, $author) {
            $item->is_active = false;
            $this->appendAudit($item, ['action' => 'soft_delete'], $author);
            $item->save();
        });

        Log::info('RequestItemEditor: item soft-deleted', [
            'request_item_id' => $item->id,
            'by' => $author->id,
        ]);
    }

    public function restore(RequestItem $item, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);
        if ($item->is_active) {
            return $item;
        }

        DB::transaction(function () use ($item, $author) {
            $item->is_active = true;
            $this->appendAudit($item, ['action' => 'restore'], $author);
            $item->save();
        });

        Log::info('RequestItemEditor: item restored', [
            'request_item_id' => $item->id,
            'by' => $author->id,
        ]);

        return $item;
    }

    public function unbindCatalog(RequestItem $item, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);
        if ($item->catalog_item_id === null) {
            return $item;
        }

        $oldCatalogId = $item->catalog_item_id;

        DB::transaction(function () use ($item, $author, $oldCatalogId) {
            $item->catalog_item_id = null;

            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            // Сохраняем history предыдущей привязки, очищаем активный catalog_match.
            if (! empty($payload['catalog_match'])) {
                $payload['previous_catalog_match'] = $payload['catalog_match'];
            }
            unset($payload['catalog_match']);
            // catalog snapshot тоже снимаем — в UI цены/наличия больше не показываем.
            unset($payload['catalog']);
            $item->quality_assessment_payload = $payload;

            $this->appendAudit($item, [
                'action' => 'unbind',
                'old' => ['catalog_item_id' => $oldCatalogId],
            ], $author);

            $item->save();
        });

        Log::info('RequestItemEditor: catalog unbound', [
            'request_item_id' => $item->id,
            'previous_catalog_item_id' => $oldCatalogId,
            'by' => $author->id,
        ]);

        return $item;
    }

    public function linkToCatalog(RequestItem $item, CatalogItem $catalog, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);
        if ($item->catalog_item_id === $catalog->id) {
            return $item; // идемпотентность: тот же catalog — no-op
        }

        $oldCatalogId = $item->catalog_item_id;

        DB::transaction(function () use ($item, $catalog, $author, $oldCatalogId) {
            $item->catalog_item_id = $catalog->id;

            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            // Если был активный catalog_match — переносим в previous.
            if (! empty($payload['catalog_match'])) {
                $payload['previous_catalog_match'] = $payload['catalog_match'];
            }
            $payload['catalog_match'] = [
                'method' => 'manual_link',
                'matched_at' => now()->toIso8601String(),
                'catalog_item_id' => $catalog->id,
                'catalog_sku' => $catalog->sku,
                'by_user_id' => $author->id,
                'by_name' => $author->name,
            ];
            // Snapshot каталожных данных (как в applyCatalogToItem A-step).
            $payload['catalog'] = [
                'catalog_item_id' => $catalog->id,
                'sku' => $catalog->sku,
                'brand' => $catalog->brand,
                'brand_article' => $catalog->brand_article,
                'unit_name' => $catalog->unit_name,
                'part_type' => $catalog->part_type,
                'form_factor' => $catalog->form_factor,
                'price' => $catalog->price,
                'stock_available' => $catalog->stock_available,
            ];

            $item->quality_assessment_payload = $payload;

            $this->appendAudit($item, [
                'action' => 'link',
                'old' => ['catalog_item_id' => $oldCatalogId],
                'new' => ['catalog_item_id' => $catalog->id, 'catalog_sku' => $catalog->sku],
            ], $author);

            $item->save();
        });

        Log::info('RequestItemEditor: catalog manually linked', [
            'request_item_id' => $item->id,
            'catalog_item_id' => $catalog->id,
            'catalog_sku' => $catalog->sku,
            'by' => $author->id,
        ]);

        return $item;
    }

    /**
     * Re-run C-step (vector + LLM) для пересмотра привязки.
     * Сбрасывает активный catalog_match если был не C/manual.
     */
    public function refreshFromCatalog(RequestItem $item, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $oldCatalogId = $item->catalog_item_id;
        $oldStatus = $item->quality_assessment_status;

        // CatalogResolutionService::matchByName выходит, если catalog_item_id уже стоит.
        // Сначала очистим, чтобы он действительно пересчитал.
        if ($item->catalog_item_id !== null) {
            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            if (! empty($payload['catalog_match'])) {
                $payload['previous_catalog_match'] = $payload['catalog_match'];
            }
            unset($payload['catalog_match'], $payload['catalog']);
            $item->quality_assessment_payload = $payload;
            $item->catalog_item_id = null;
            $item->save();
        }

        // Если позиция была помечена `not_found` — refresh означает «пересмотри».
        // Снимаем not_found чтобы matchByName заработал; возвращаем not_assessed.
        if ($oldStatus === QualityAssessmentStatus::InternalCatalogNotFound->value) {
            $item->quality_assessment_status = QualityAssessmentStatus::NotAssessed->value;
            $item->save();
        }

        $matched = $this->resolver->matchByName($item->fresh());
        $item->refresh();

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $payload['last_refresh_attempt'] = [
            'at' => now()->toIso8601String(),
            'by_user_id' => $author->id,
            'by_name' => $author->name,
            'result' => $matched ? 'matched' : 'no_match',
        ];
        $item->quality_assessment_payload = $payload;
        $this->appendAudit($item, [
            'action' => 'refresh',
            'old' => ['catalog_item_id' => $oldCatalogId],
            'new' => ['matched' => $matched, 'catalog_item_id' => $item->catalog_item_id],
        ], $author);
        $item->save();

        Log::info('RequestItemEditor: refresh from catalog', [
            'request_item_id' => $item->id,
            'matched' => $matched,
            'old_catalog_item_id' => $oldCatalogId,
            'new_catalog_item_id' => $item->catalog_item_id,
            'by' => $author->id,
        ]);

        return $item;
    }

    /**
     * Перевод M-SKU позиции из «pending» в «not_found» (оператор подтвердил
     * что SKU не появится). Доступно только для internal_catalog_pending.
     */
    /**
     * Слить позицию $source как уточнение в $target — артикул и (опц.) бренд
     * из $source дописываются в $target, $source soft-удаляется.
     * Используется когда decideClarifications LLM ошибся и не распознал
     * голый артикул в reply как уточнение существующей позиции — оператор
     * вручную мержит через «⋮ → Это уточнение позиции…».
     *
     * Audit: запись `manual_merge` в обеих позициях.
     */
    public function mergeIntoExisting(RequestItem $source, RequestItem $target, User $author): void
    {
        $this->ensureCanEdit($source, $author);
        if ($source->id === $target->id) {
            throw new \DomainException('Нельзя слить позицию саму в себя.');
        }
        if ($source->request_id !== $target->request_id) {
            throw new \DomainException('Позиции должны принадлежать одной заявке.');
        }
        if (! $source->is_active || ! $target->is_active) {
            throw new \DomainException('Обе позиции должны быть активны.');
        }

        DB::transaction(function () use ($source, $target, $author) {
            $sourceArticle = trim((string) ($source->parsed_article ?? ''));
            $sourceBrand = trim((string) ($source->parsed_brand ?? ''));

            $changes = [];

            // Article — дописываем через запятую (с проверкой на дубль).
            if ($sourceArticle !== '') {
                $existingArt = (string) ($target->parsed_article ?? '');
                if (! $this->articleAlreadyPresent($existingArt, $sourceArticle)) {
                    $target->parsed_article = $existingArt === ''
                        ? $sourceArticle
                        : $existingArt . ', ' . $sourceArticle;
                    $changes['article'] = $sourceArticle;
                }
            }

            // Brand — переносим только если у target пусто.
            if ($sourceBrand !== '' && trim((string) ($target->parsed_brand ?? '')) === '') {
                $target->parsed_brand = $sourceBrand;
                $changes['brand'] = $sourceBrand;
            }

            // catalog_item_id — переносим если у target пусто и у source есть.
            if ($target->catalog_item_id === null && $source->catalog_item_id !== null) {
                $target->catalog_item_id = $source->catalog_item_id;
                $changes['catalog_item_id'] = $source->catalog_item_id;
                // Также переносим catalog snapshot из payload source.
                $sourcePayload = is_array($source->quality_assessment_payload)
                    ? $source->quality_assessment_payload : [];
                if (! empty($sourcePayload['catalog'])) {
                    $tp = is_array($target->quality_assessment_payload)
                        ? $target->quality_assessment_payload : [];
                    $tp['catalog'] = $sourcePayload['catalog'];
                    $tp['catalog_match'] = ($sourcePayload['catalog_match'] ?? null) ?: [
                        'method' => 'manual_merge_from_clarification',
                        'matched_at' => now()->toIso8601String(),
                        'catalog_item_id' => $source->catalog_item_id,
                    ];
                    $target->quality_assessment_payload = $tp;
                }
            }

            $this->appendAudit($target, [
                'action' => 'manual_merge_in',
                'old' => ['from_item_id' => $source->id, 'from_position' => $source->position],
                'new' => $changes,
            ], $author);
            $target->save();

            // Soft-delete source с audit.
            $source->is_active = false;
            $this->appendAudit($source, [
                'action' => 'manual_merge_out',
                'old' => ['was_active' => true],
                'new' => ['merged_into_item_id' => $target->id, 'target_position' => $target->position],
            ], $author);
            $source->save();
        });

        Log::info('RequestItemEditor: manual merge', [
            'source_id' => $source->id,
            'target_id' => $target->id,
            'by' => $author->id,
        ]);
    }

    /**
     * Тот же normalize что в Detail::articleAlreadyPresent (uppercase + strip separators).
     */
    private function articleAlreadyPresent(string $existing, string $candidate): bool
    {
        $norm = fn (string $s) => preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($s)));
        $candidateNorm = $norm($candidate);
        if ($candidateNorm === '') {
            return true;
        }
        foreach (preg_split('/\s*,\s*/', $existing) ?: [] as $part) {
            if ($norm((string) $part) === $candidateNorm) {
                return true;
            }
        }
        return false;
    }

    public function markCatalogNotFound(RequestItem $item, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        if ($item->quality_assessment_status !== QualityAssessmentStatus::InternalCatalogPending->value) {
            // guard: операция только для pending. UI должен скрыть кнопку, но
            // на всякий случай дополнительно защищаемся.
            return $item;
        }

        DB::transaction(function () use ($item, $author) {
            $oldStatus = $item->quality_assessment_status;
            $oldCatalogId = $item->catalog_item_id;

            $item->quality_assessment_status = QualityAssessmentStatus::InternalCatalogNotFound->value;
            $item->catalog_item_id = null;

            $this->appendAudit($item, [
                'action' => 'mark_not_found',
                'old' => ['status' => $oldStatus, 'catalog_item_id' => $oldCatalogId],
                'new' => ['status' => QualityAssessmentStatus::InternalCatalogNotFound->value],
            ], $author);

            $item->save();
        });

        Log::info('RequestItemEditor: marked as not_found', [
            'request_item_id' => $item->id,
            'by' => $author->id,
        ]);

        return $item;
    }

    /**
     * Перепривязка фото-вложения к позиции (Phase 2.4a, операторская правка
     * Vision-mistake'ов).
     *
     * Vision-промпт image_index не гарантирует уникальность и не различает
     * «главный объект» от «виден в кадре» — закрытие пробелов через UI.
     *
     * @param  ?int  $attachmentId  null = снять привязку
     * @throws \DomainException если attachment не принадлежит письму заявки
     */
    public function rebindPhoto(RequestItem $item, ?int $attachmentId, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $oldAttachmentId = $item->image_attachment_id;
        if ($oldAttachmentId === $attachmentId) {
            return $item; // идемпотентность
        }

        // Validate: attachment должен принадлежать тому же письму, что и заявка.
        // Это закрывает риск кросс-заявочной привязки — оператор не сможет
        // прикрепить случайную картинку из чужого треда.
        if ($attachmentId !== null) {
            $emailMessageId = $item->request?->email_message_id;
            if ($emailMessageId === null) {
                throw new \DomainException('У заявки нет привязанного письма — фото менять некуда.');
            }
            $attachment = EmailAttachment::query()
                ->where('email_message_id', $emailMessageId)
                ->whereKey($attachmentId)
                ->first(['id', 'mime_type']);
            if ($attachment === null) {
                throw new \DomainException('Это вложение не относится к письму заявки.');
            }
            if (! str_starts_with((string) $attachment->mime_type, 'image/')) {
                throw new \DomainException('Можно привязать только вложение типа image/*.');
            }
        }

        DB::transaction(function () use ($item, $attachmentId, $author, $oldAttachmentId) {
            $item->image_attachment_id = $attachmentId;
            $this->appendAudit($item, [
                'action' => 'rebind_photo',
                'old' => ['image_attachment_id' => $oldAttachmentId],
                'new' => ['image_attachment_id' => $attachmentId],
            ], $author);
            $item->save();
        });

        Log::info('RequestItemEditor: photo rebound', [
            'request_item_id' => $item->id,
            'old' => $oldAttachmentId,
            'new' => $attachmentId,
            'by' => $author->id,
        ]);

        return $item;
    }

    /**
     * Foundation §6.2 Phase C — применить enrichment suggestion из
     * LLM-извлечения ответа клиента к позиции (поле parsed_article /
     * parsed_brand / parsed_qty).
     *
     * Логика:
     *  - находим suggestion по id в quality_assessment_payload.enrichment_suggestions[];
     *  - если status != pending — no-op (уже применён или dismissed);
     *  - editFields([field => value]) — стандартный механизм правки + audit;
     *  - помечаем suggestion как applied + applied_at + applied_by.
     */
    public function applyEnrichmentSuggestion(RequestItem $item, string $suggestionId, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

        $idx = null;
        foreach ($suggestions as $i => $s) {
            if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return $item;
        }
        $sugg = $suggestions[$idx];
        if (($sugg['status'] ?? 'pending') !== 'pending') {
            return $item;
        }

        $field = (string) ($sugg['field'] ?? '');
        $value = (string) ($sugg['value'] ?? '');
        $isKb = str_starts_with($field, 'kb:');
        $isBase = in_array($field, self::EDITABLE_FIELDS, true);
        if ((! $isKb && ! $isBase) || $value === '') {
            return $item;
        }

        DB::transaction(function () use ($item, $field, $value, $suggestionId, $sugg, $author, $isKb) {
            if ($isKb) {
                // Phase D: KB-слот — пишем в extracted_parameters[slug].
                $slug = substr($field, 3);
                $fresh = $item->fresh();
                $p = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
                $extracted = is_array($p['extracted_parameters'] ?? null) ? $p['extracted_parameters'] : [];
                $extracted[$slug] = $value;
                $p['extracted_parameters'] = $extracted;
                $fresh->quality_assessment_payload = $p;
                $fresh->save();
                $this->appendAudit($fresh, [
                    'action' => 'enrichment_applied_to_kb_slot',
                    'slot' => $slug,
                    'value' => $value,
                    'from_suggestion' => $suggestionId,
                ], $author);
            } else {
                // editFields внутри делает audit. В той же транзакции
                // чтобы suggestion-mark + edit были атомарны.
                $this->editFields($item, [$field => $value], $author);
            }

            // Пометить suggestion как applied (re-load payload, потому что
            // editFields мог тронуть payload через manual_edits).
            $fresh = $item->fresh();
            $payload = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
            $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];
            foreach ($suggestions as $i => $s) {
                if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                    $suggestions[$i] = array_merge($s, [
                        'status' => 'applied',
                        'applied_at' => now()->toIso8601String(),
                        'applied_by_user_id' => $author->id,
                    ]);
                    break;
                }
            }
            $payload['enrichment_suggestions'] = $suggestions;
            $fresh->quality_assessment_payload = $payload;
            $fresh->save();
        });

        Log::info('RequestItemEditor: enrichment suggestion applied', [
            'request_item_id' => $item->id,
            'suggestion_id' => $suggestionId,
            'field' => $field,
            'value' => $value,
            'by' => $author->id,
        ]);

        return $item->fresh();
    }

    /**
     * Foundation §6.2 Phase C+ — применить enrichment suggestion в
     * ВЫБРАННЫЙ менеджером слот (а не тот, который угадал LLM).
     *
     * targetSlotKey:
     *  - 'brand' / 'article' / 'qty' → parsed_brand / parsed_article / parsed_qty
     *  - 'kb:<slug>' → quality_assessment_payload.extracted_parameters[slug]
     */
    public function applyEnrichmentSuggestionToSlot(
        RequestItem $item,
        string $suggestionId,
        string $targetSlotKey,
        User $author,
    ): RequestItem {
        $this->ensureCanEdit($item, $author);

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

        $idx = null;
        foreach ($suggestions as $i => $s) {
            if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return $item;
        }
        $sugg = $suggestions[$idx];
        if (($sugg['status'] ?? 'pending') !== 'pending') {
            return $item;
        }

        $value = trim((string) ($sugg['value'] ?? ''));
        if ($value === '') {
            return $item;
        }

        $baseSlotMap = [
            'brand' => 'parsed_brand',
            'article' => 'parsed_article',
            'qty' => 'parsed_qty',
        ];

        DB::transaction(function () use ($item, $value, $suggestionId, $sugg, $author, $targetSlotKey, $baseSlotMap) {
            if (isset($baseSlotMap[$targetSlotKey])) {
                // Base-slot — пишем через editFields (audit включён).
                $field = $baseSlotMap[$targetSlotKey];
                $this->editFields($item, [$field => $value], $author);
            } elseif (str_starts_with($targetSlotKey, 'kb:')) {
                // KB-slot — пишем в extracted_parameters[slug].
                $slug = substr($targetSlotKey, 3);
                $fresh = $item->fresh();
                $p = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
                $extracted = is_array($p['extracted_parameters'] ?? null) ? $p['extracted_parameters'] : [];
                $extracted[$slug] = $value;
                $p['extracted_parameters'] = $extracted;
                $fresh->quality_assessment_payload = $p;
                $fresh->save();

                $this->appendAudit($fresh, [
                    'action' => 'enrichment_applied_to_kb_slot',
                    'slot' => $slug,
                    'value' => $value,
                    'from_suggestion' => $suggestionId,
                ], $author);
            }

            // Mark suggestion applied + сохраняем какой слот выбрал менеджер
            // (отличается от sugg.field — для аудита и обучения).
            $fresh = $item->fresh();
            $payload = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
            $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];
            foreach ($suggestions as $i => $s) {
                if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                    $suggestions[$i] = array_merge($s, [
                        'status' => 'applied',
                        'applied_at' => now()->toIso8601String(),
                        'applied_by_user_id' => $author->id,
                        'applied_to_slot' => $targetSlotKey,
                    ]);
                    break;
                }
            }
            $payload['enrichment_suggestions'] = $suggestions;
            $fresh->quality_assessment_payload = $payload;
            $fresh->save();
        });

        Log::info('RequestItemEditor: enrichment suggestion applied to slot', [
            'request_item_id' => $item->id,
            'suggestion_id' => $suggestionId,
            'target_slot' => $targetSlotKey,
            'value' => $value,
            'by' => $author->id,
        ]);

        return $item->fresh();
    }

    /**
     * Foundation §6.2 Phase E.2 — откатить applied enrichment suggestion.
     * Сбрасывает значение поля обратно (для base — в null, для kb:* —
     * удаляет ключ из extracted_parameters). Помечает suggestion как
     * 'reverted', чтобы его снова можно было применить или отклонить.
     *
     * NB: для kb:* — мы не восстанавливаем «было», просто удаляем
     * заполнение, т.к. до применения было empty. Для base полей —
     * сетим null. Если хочется честный «был X» — нужен дополнительный
     * audit-чтение из manual_edits.
     */
    public function rollbackEnrichmentSuggestion(RequestItem $item, string $suggestionId, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

        $idx = null;
        foreach ($suggestions as $i => $s) {
            if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return $item;
        }
        $sugg = $suggestions[$idx];
        if (($sugg['status'] ?? '') !== 'applied') {
            return $item;
        }

        // Определяем какой слот заполнялся — applied_to_slot (если был «→ в слот»)
        // или иначе нативный field.
        $field = (string) ($sugg['applied_to_slot'] ?? $sugg['field'] ?? '');
        $baseSlotMap = [
            'brand' => 'parsed_brand',
            'article' => 'parsed_article',
            'qty' => 'parsed_qty',
        ];

        DB::transaction(function () use ($item, $field, $baseSlotMap, $suggestionId, $sugg, $author) {
            if (isset($baseSlotMap[$field])) {
                // Через editFields → null. Это перетрёт текущее значение.
                $this->editFields($item, [$baseSlotMap[$field] => null], $author);
            } elseif (in_array($field, self::EDITABLE_FIELDS, true)) {
                $this->editFields($item, [$field => null], $author);
            } elseif (str_starts_with($field, 'kb:')) {
                $slug = substr($field, 3);
                $fresh = $item->fresh();
                $p = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
                $extracted = is_array($p['extracted_parameters'] ?? null) ? $p['extracted_parameters'] : [];
                unset($extracted[$slug]);
                $p['extracted_parameters'] = $extracted;
                $fresh->quality_assessment_payload = $p;
                $fresh->save();
                $this->appendAudit($fresh, [
                    'action' => 'enrichment_rolled_back_kb',
                    'slot' => $slug,
                    'from_suggestion' => $suggestionId,
                ], $author);
            }

            // Помечаем suggestion как reverted (можно повторно применить).
            $fresh = $item->fresh();
            $payload = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
            $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];
            foreach ($suggestions as $i => $s) {
                if (is_array($s) && ($s['id'] ?? null) === $suggestionId) {
                    $suggestions[$i] = array_merge($s, [
                        'status' => 'reverted',
                        'reverted_at' => now()->toIso8601String(),
                        'reverted_by_user_id' => $author->id,
                    ]);
                    break;
                }
            }
            $payload['enrichment_suggestions'] = $suggestions;
            $fresh->quality_assessment_payload = $payload;
            $fresh->save();
        });

        Log::info('RequestItemEditor: enrichment suggestion rolled back', [
            'request_item_id' => $item->id,
            'suggestion_id' => $suggestionId,
            'field' => $field,
            'by' => $author->id,
        ]);

        return $item->fresh();
    }

    /**
     * Foundation §6.2 Phase C — отклонить enrichment suggestion (оператор
     * не согласен / клиент написал ошибочно).
     */
    public function dismissEnrichmentSuggestion(RequestItem $item, string $suggestionId, User $author): RequestItem
    {
        $this->ensureCanEdit($item, $author);

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $suggestions = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

        $changed = false;
        foreach ($suggestions as $i => $s) {
            if (is_array($s) && ($s['id'] ?? null) === $suggestionId && ($s['status'] ?? 'pending') === 'pending') {
                $suggestions[$i] = array_merge($s, [
                    'status' => 'dismissed',
                    'dismissed_at' => now()->toIso8601String(),
                    'dismissed_by_user_id' => $author->id,
                ]);
                $changed = true;
                break;
            }
        }
        if (! $changed) {
            return $item;
        }

        $payload['enrichment_suggestions'] = $suggestions;
        $item->quality_assessment_payload = $payload;
        $item->save();

        return $item;
    }

    /* ----------------------- internals ----------------------- */

    private function ensureCanEdit(RequestItem $item, User $author): void
    {
        $privileged = $author->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Secretary->value,
        ]);
        if ($privileged) {
            return;
        }

        $request = $item->request;
        if ($request !== null) {
            if (($request->assigned_user_id ?? null) === $author->id) {
                return;
            }
            // Foundation Фаза 2: acting (active delegation) тоже может
            // править позиции на время отсутствия оригинального менеджера.
            if ($request->isDelegatedTo($author)) {
                return;
            }
        }

        abort(403, 'Эта позиция принадлежит заявке другого менеджера.');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function appendAudit(RequestItem $item, array $entry, User $author): void
    {
        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        $audit = is_array($payload['manual_edits'] ?? null) ? $payload['manual_edits'] : [];

        $audit[] = array_merge($entry, [
            'by_user_id' => $author->id,
            'by_name' => $author->name,
            'at' => now()->toIso8601String(),
        ]);

        // FIFO-truncate чтобы payload не разрастался.
        if (count($audit) > self::MAX_AUDIT_ENTRIES) {
            $audit = array_slice($audit, -self::MAX_AUDIT_ENTRIES);
        }

        $payload['manual_edits'] = $audit;
        $item->quality_assessment_payload = $payload;
    }

    private function normalizeFieldValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return match ($field) {
                'parsed_qty' => null,
                default => null,
            };
        }
        if ($field === 'parsed_qty') {
            // decimal:3 cast в модели; принимаем строку с запятой/точкой.
            $normalized = (float) str_replace(',', '.', (string) $value);
            return $normalized > 0 ? $normalized : null;
        }
        $str = (string) $value;
        // Лимиты — соответствуют схеме БД (varchar(255) обычно).
        return mb_substr(trim($str), 0, match ($field) {
            'parsed_name' => 500,
            'parsed_article' => 500,
            'supplier_note' => 1000,
            default => 250,
        });
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        // decimal cast: 1 vs 1.000 — equal.
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0005;
        }
        return $a === $b || (string) $a === (string) $b;
    }
}
