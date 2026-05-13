<?php

namespace App\Services\Catalog;

use App\Enums\QualityAssessmentStatus;
use App\Enums\Role as RoleEnum;
use App\Models\CatalogItem;
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

        $assignedUserId = $item->request->assigned_user_id ?? null;
        if ($assignedUserId === $author->id) {
            return;
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
