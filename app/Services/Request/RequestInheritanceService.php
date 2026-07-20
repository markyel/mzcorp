<?php

namespace App\Services\Request;

use App\Enums\RequestActivityType;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\RequestItemLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Наследование заявок: новая Request связывается с архивной (closed_lost)
 * как «продолжение того же запроса клиента». Source: LazyLift @ 7fee1f77
 * `RequestInheritanceService` (адаптация под mzCorp).
 *
 * Архитектура: один child-item ← один parent-item (1:1). Связь хранится
 * в `request_item_links` (unique partial index на is_active=true). История
 * неактивных связей сохраняется.
 *
 * Триггер автоматического link'а — `CheckInheritanceJob` после LLM-
 * подтверждения. Manual relink/unlink — UI (Phase 2.2).
 *
 * Цепочки запрещены: parent не может быть child, и наоборот.
 */
class RequestInheritanceService
{
    /**
     * Подсказать соответствие позиций между parent и child.
     * Словарный матчер: (1) exact article, (2) similar_text по name
     * с brand match при threshold ≥ 85%.
     *
     * @return array<int, array{child_item_id:int, parent_item_id:int, source:string, confidence:float}>
     */
    public function suggestLinks(Request $parent, Request $child): array
    {
        $parent->loadMissing('items');
        $child->loadMissing('items');

        $parentItems = $parent->items->where('is_active', true)->values();
        $childItems = $child->items->where('is_active', true)->values();

        $suggestions = [];
        $usedParentIds = [];

        // 1. Exact article match — самый надёжный сигнал.
        foreach ($childItems as $ci) {
            $cArt = $this->normalizeArticle($ci->parsed_article);
            if ($cArt === '') {
                continue;
            }
            foreach ($parentItems as $pi) {
                if (in_array($pi->id, $usedParentIds, true)) {
                    continue;
                }
                if ($this->normalizeArticle($pi->parsed_article) === $cArt) {
                    $suggestions[] = [
                        'child_item_id' => $ci->id,
                        'parent_item_id' => $pi->id,
                        'source' => 'auto_article',
                        'confidence' => 1.0,
                    ];
                    $usedParentIds[] = $pi->id;
                    break;
                }
            }
        }

        $matchedChildIds = collect($suggestions)->pluck('child_item_id')->all();

        // 2. Similar name + brand match для оставшихся (≥85%).
        foreach ($childItems as $ci) {
            if (in_array($ci->id, $matchedChildIds, true)) {
                continue;
            }
            $ciName = mb_strtolower(trim((string) $ci->parsed_name));
            $ciBrand = mb_strtolower(trim((string) $ci->parsed_brand));
            if ($ciName === '') {
                continue;
            }

            $best = null;
            $bestPct = 0;
            foreach ($parentItems as $pi) {
                if (in_array($pi->id, $usedParentIds, true)) {
                    continue;
                }
                $piName = mb_strtolower(trim((string) $pi->parsed_name));
                $piBrand = mb_strtolower(trim((string) $pi->parsed_brand));
                if ($piName === '') {
                    continue;
                }
                // Бренды либо оба пустые, либо должны совпадать.
                if ($ciBrand !== '' && $piBrand !== '' && $ciBrand !== $piBrand) {
                    continue;
                }
                similar_text($ciName, $piName, $pct);
                if ($pct >= 85 && $pct > $bestPct) {
                    $best = $pi;
                    $bestPct = $pct;
                }
            }

            if ($best) {
                $suggestions[] = [
                    'child_item_id' => $ci->id,
                    'parent_item_id' => $best->id,
                    'source' => 'auto_similarity',
                    'confidence' => round($bestPct / 100, 2),
                ];
                $usedParentIds[] = $best->id;
            }
        }

        return $suggestions;
    }

    /**
     * Назначить родителя для child-заявки и создать item-маппинги.
     *
     * @param array<int, array{child_item_id:int, parent_item_id:int, source?:string, confidence?:float}> $itemMappings
     *
     * @throws \InvalidArgumentException
     */
    public function linkChild(
        Request $parent,
        Request $child,
        array $itemMappings,
        string $linkedBy = 'system',
    ): Request {
        $this->assertCanLink($parent, $child);

        return DB::transaction(function () use ($parent, $child, $itemMappings, $linkedBy) {
            $groupId = $parent->inheritance_group_id ?? (string) Str::uuid();

            $parent->update([
                'inheritance_group_id' => $groupId,
                'inheritance_role' => 'parent',
            ]);

            $child->update([
                'inheritance_group_id' => $groupId,
                'inheritance_role' => 'child',
                'inheritance_parent_id' => $parent->id,
            ]);

            $createdCount = 0;
            $seenChildIds = [];

            foreach ($itemMappings as $m) {
                $childItemId = (int) ($m['child_item_id'] ?? 0);
                $parentItemId = (int) ($m['parent_item_id'] ?? 0);
                if ($childItemId <= 0 || $parentItemId <= 0) {
                    continue;
                }
                if (in_array($childItemId, $seenChildIds, true)) {
                    continue; // одна активная связь на child-item (unique idx)
                }

                $childItem = RequestItem::query()
                    ->where('id', $childItemId)
                    ->where('request_id', $child->id)
                    ->first();
                $parentItem = RequestItem::query()
                    ->where('id', $parentItemId)
                    ->where('request_id', $parent->id)
                    ->first();
                if (! $childItem || ! $parentItem) {
                    Log::warning('RequestInheritanceService::linkChild skipped invalid mapping', [
                        'child_request_id' => $child->id,
                        'parent_request_id' => $parent->id,
                        'child_item_id' => $childItemId,
                        'parent_item_id' => $parentItemId,
                    ]);
                    continue;
                }

                RequestItemLink::query()
                    ->where('child_item_id', $childItemId)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                RequestItemLink::create([
                    'child_item_id' => $childItemId,
                    'parent_item_id' => $parentItemId,
                    'qty_ratio' => $this->calcQtyRatio($childItem, $parentItem),
                    'mapping_source' => $m['source'] ?? 'manual',
                    'mapping_confidence' => $m['confidence'] ?? null,
                    'is_active' => true,
                    'linked_by' => $linkedBy,
                ]);

                $createdCount++;
                $seenChildIds[] = $childItemId;
            }

            Log::info('Request inheritance linked', [
                'parent_request_id' => $parent->id,
                'parent_code' => $parent->internal_code,
                'child_request_id' => $child->id,
                'child_code' => $child->internal_code,
                'items_linked' => $createdCount,
                'group_id' => $groupId,
                'linked_by' => $linkedBy,
            ]);

            return $child->fresh();
        });
    }

    /**
     * Усыновить child из parent: для случая когда у child НЕТ позиций
     * (типичный partner-reminder: subject «Re: …», body «Напоминаем…»,
     * парсер правильно возвращает items=[] по правилу промпта Пример 8).
     * Копируем активные позиции parent в child + сразу регистрируем
     * RequestItemLink + связываем inheritance_parent_id.
     *
     * Отличие от `linkChild`: тот ожидает что child УЖЕ имеет items и
     * мапит их к parent items. `adoptFromParent` создаёт items в child
     * из parent (clone) и сразу же связывает 1:1.
     *
     * Кейсы: Liftway reminder'ы по `LZ-REQ-NNNN` на закрытые заявки
     * (M-2026-1839, 1838, …) — реальный товар уже в parent, body reminder'а
     * содержит только напоминание + HTML-таблицу с теми же позициями,
     * парсер их не дублирует, child остаётся пустым.
     *
     * @return Request fresh child
     */
    public function adoptFromParent(
        Request $child,
        Request $parent,
        string $linkedBy = 'system',
    ): Request {
        $this->assertCanLink($parent, $child);

        $existingChildItems = RequestItem::query()
            ->where('request_id', $child->id)
            ->exists();
        if ($existingChildItems) {
            throw new \InvalidArgumentException(
                "Child {$child->internal_code} уже имеет позиции — используй linkChild() вместо adoptFromParent()"
            );
        }

        $parentItems = RequestItem::query()
            ->where('request_id', $parent->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        if ($parentItems->isEmpty()) {
            Log::info('RequestInheritanceService::adoptFromParent skipped — parent has no active items', [
                'parent_request_id' => $parent->id,
                'child_request_id' => $child->id,
            ]);
            return $child->fresh();
        }

        return DB::transaction(function () use ($child, $parent, $parentItems, $linkedBy) {
            $groupId = $parent->inheritance_group_id ?? (string) Str::uuid();

            $parent->update([
                'inheritance_group_id' => $groupId,
                'inheritance_role' => $parent->inheritance_role ?? 'parent',
            ]);

            $child->update([
                'inheritance_group_id' => $groupId,
                'inheritance_role' => 'child',
                'inheritance_parent_id' => $parent->id,
            ]);

            $position = 0;
            $linksCreated = 0;
            foreach ($parentItems as $pi) {
                $position++;

                // Clone item с фокусом на data fields. image_attachment_id
                // НЕ копируем (вложение принадлежит parent-письму, не нашему).
                // KB-резолв (quality_assessment_*) копируем — артикул/бренд
                // те же, повторный LLM-проход не нужен.
                $childItem = RequestItem::create([
                    'request_id' => $child->id,
                    'position' => $position,
                    'parsed_name' => $pi->parsed_name,
                    'parsed_brand' => $pi->parsed_brand,
                    'parsed_article' => $pi->parsed_article,
                    'parsed_qty' => $pi->parsed_qty,
                    'parsed_unit' => $pi->parsed_unit,
                    'parsed_length' => $pi->parsed_length,
                    'parsed_length_unit' => $pi->parsed_length_unit,
                    'billing_unit' => $pi->billing_unit,
                    'supplier_note' => $pi->supplier_note,
                    'data_source' => 'inherited_from_parent',
                    'status' => 'active',
                    'is_active' => true,
                    'identification_category_id' => $pi->identification_category_id,
                    'manufacturer_brand_id' => $pi->manufacturer_brand_id,
                    'equipment_unit_id' => $pi->equipment_unit_id,
                    'category' => $pi->category,
                    'catalog_item_id' => $pi->catalog_item_id,
                    'quality_assessment_status' => $pi->quality_assessment_status,
                    'quality_assessment_payload' => $pi->quality_assessment_payload,
                    'match_path' => $pi->match_path,
                ]);

                RequestItemLink::create([
                    'child_item_id' => $childItem->id,
                    'parent_item_id' => $pi->id,
                    'qty_ratio' => 1.0,
                    'mapping_source' => 'adopt_from_parent',
                    'mapping_confidence' => 1.0,
                    'is_active' => true,
                    'linked_by' => $linkedBy,
                ]);
                $linksCreated++;
            }

            Log::info('Request inheritance adopted (items cloned)', [
                'parent_request_id' => $parent->id,
                'parent_code' => $parent->internal_code,
                'child_request_id' => $child->id,
                'child_code' => $child->internal_code,
                'items_cloned' => $parentItems->count(),
                'links_created' => $linksCreated,
                'group_id' => $groupId,
                'linked_by' => $linkedBy,
            ]);

            return $child->fresh();
        });
    }

    /**
     * Отвязать child от родителя: деактивирует все item-связи,
     * очищает inheritance_parent_id. group_id оставляем для истории.
     */
    public function unlinkChild(Request $child, string $reason = 'manual', string $unlinkedBy = 'system'): void
    {
        if (! $child->isInheritanceChild()) {
            return;
        }

        DB::transaction(function () use ($child, $reason, $unlinkedBy) {
            $parent = $child->inheritanceParent;

            $deactivated = RequestItemLink::query()
                ->whereIn('child_item_id', $child->items()->pluck('id'))
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $child->update([
                'inheritance_role' => null,
                'inheritance_parent_id' => null,
            ]);

            // Если у parent больше нет активных children — снимаем роль.
            if ($parent) {
                $hasOtherChildren = Request::query()
                    ->where('inheritance_parent_id', $parent->id)
                    ->exists();
                if (! $hasOtherChildren) {
                    $parent->update(['inheritance_role' => null]);
                }
            }

            Log::info('Request inheritance unlinked', [
                'child_request_id' => $child->id,
                'child_code' => $child->internal_code,
                'parent_request_id' => $parent?->id,
                'reason' => $reason,
                'items_deactivated' => $deactivated,
                'unlinked_by' => $unlinkedBy,
            ]);
        });
    }

    /**
     * Валидация: можно ли строить наследование parent ← child.
     *
     * @throws \InvalidArgumentException
     */
    private function assertCanLink(Request $parent, Request $child): void
    {
        if ($parent->id === $child->id) {
            throw new \InvalidArgumentException('Родитель и наследник — одна и та же заявка');
        }
        // child должен быть открытым (только что созданная заявка).
        if ($child->status->isTerminal()) {
            throw new \InvalidArgumentException(
                "Заявка {$child->internal_code} закрыта — не может быть наследником"
            );
        }
        // Parent в нашем случае — это archived closed_lost. Это нормально и ожидаемо.
        // Цепочки запрещены: parent не должен сам быть child.
        if ($parent->isInheritanceChild()) {
            throw new \InvalidArgumentException(
                "Заявка {$parent->internal_code} уже наследует от другой — цепочки не поддерживаются"
            );
        }
        // child не может быть одновременно родителем для других.
        if ($child->isInheritanceParent()) {
            throw new \InvalidArgumentException(
                "Заявка {$child->internal_code} является родителем для других — цепочки не поддерживаются"
            );
        }
        // child уже привязан к другому родителю.
        if ($child->inheritance_parent_id && $child->inheritance_parent_id !== $parent->id) {
            throw new \InvalidArgumentException(
                "Заявка {$child->internal_code} уже наследует от другой заявки (ID {$child->inheritance_parent_id})"
            );
        }
    }

    private function calcQtyRatio(RequestItem $child, RequestItem $parent): float
    {
        $pQty = (float) ($parent->parsed_qty ?? 0);
        $cQty = (float) ($child->parsed_qty ?? 0);
        if ($pQty <= 0 || $cQty <= 0) {
            return 1.0;
        }

        return round($cQty / $pQty, 2);
    }

    private function normalizeArticle(?string $article): string
    {
        if (empty($article)) {
            return '';
        }

        return (string) preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($article)));
    }

    /**
     * Может ли родитель быть реанимирован ВМЕСТО усыновления child'а:
     * закрыт «по тишине» (нет ответа) и недавно. Проверка номенклатуры сюда
     * НЕ входит — её делает вызывающий (в zero-items пути новой номенклатуры
     * нет по определению; в CheckInheritanceJob сверяются артикулы).
     */
    public function parentQualifiesForReanimate(Request $parent): bool
    {
        if ($parent->status !== \App\Enums\RequestStatus::ClosedLost) {
            return false;
        }
        $noResponse = in_array($parent->closed_lost_reason, [
            \App\Enums\ClosedLostReason::NoClientResponseToQuote->value,
            \App\Enums\ClosedLostReason::NoClientResponseToClarification->value,
        ], true);
        $maxDays = (int) config('services.inheritance.reanimate_max_days', 45);
        $recent = $parent->closed_at !== null
            && $parent->closed_at->gt(now()->subDays(max(1, $maxDays)));

        return $noResponse && $recent;
    }

    /**
     * Реанимировать родителя под тем же номером и свернуть свежесозданный
     * дубль: письма дубля → родителю, его клиентские авто-уведомления удаляем
     * (без FK, иначе осиротеют), реанимируем родителя, удаляем дубль (каскад
     * чистит items/state/assignments/views/ai_decisions). Одна транзакция.
     *
     * Общий код для ДВУХ путей, которые раньше расходились:
     *   - `CheckInheritanceJob` (ответ С позициями, сверка по артикулам + LLM);
     *   - `ParseRequestItemsJob::tryAdoptFromInheritanceCandidate` (ответ БЕЗ
     *     номенклатуры — до CheckInheritanceJob дело вообще не доходит, т.к.
     *     тот диспатчится из RequestItemPersister, а при 0 позиций Persister
     *     не вызывается).
     */
    public function reanimateParentAbsorbingChild(
        Request $parent,
        Request $child,
        ?\App\Models\EmailMessage $inbound,
        string $event,
        string $comment,
    ): void {
        $childId = $child->id;
        $childCode = $child->internal_code;

        DB::transaction(function () use ($parent, $child, $inbound, $event, $comment) {
            \App\Models\EmailMessage::query()
                ->where('related_request_id', $child->id)
                ->update(['related_request_id' => $parent->id]);

            DB::table('client_notifications_sent')->where('request_id', $child->id)->delete();

            app(RequestStateService::class)->reanimate(
                $parent,
                null,
                $inbound,
                reassessAssignee: $inbound !== null,
                event: $event,
                comment: $comment,
            );

            $child->delete();
        });

        Log::info('RequestInheritanceService: reanimated parent, absorbed fresh duplicate', [
            'parent_request_id' => $parent->id,
            'parent_code' => $parent->internal_code,
            'deleted_child_request_id' => $childId,
            'deleted_child_code' => $childCode,
            'event' => $event,
        ]);
    }
}
