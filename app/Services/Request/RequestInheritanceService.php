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
}
