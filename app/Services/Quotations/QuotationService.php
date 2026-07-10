<?php

namespace App\Services\Quotations;

use App\Enums\QuotationStatus;
use App\Models\CatalogItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Жизненный цикл КП (исходящего). Single source of truth для:
 *  - создания первого draft'а из RequestItem'ов (auto-fill из сматченных позиций каталога)
 *  - пересчёта итогов (subtotal / discount_amount / total / vat_amount)
 *  - применения правила «MAX(price × (1 - discount%), price_min)»
 *  - freeze версии (immutable snapshot для отправки)
 *  - markSent / markAccepted / markRejected / markCancelled
 *
 * Архитектурные решения см. в doc-блоках migration'ов
 * (2026_05_23_120000_create_quotations_table.php) и enum'а QuotationStatus.
 */
class QuotationService
{
    /**
     * Создать новый КП-черновик для заявки.
     *
     * Авто-fill items: для каждой active RequestItem с непустым
     * catalog_item_id создаётся QuotationItem со snapshot'ом каталога.
     * Несматченные позиции **пропускаются** (по решению из MEMORY:
     * «КП включает только сматченные, остальные показываем warning'ом»).
     *
     * Recipient: client_name / client_email из Request (default).
     * ИНН/адрес — оставляем пустыми, менеджер заполняет.
     */
    public function createDraft(Request $request, User $byUser): Quotation
    {
        return DB::transaction(function () use ($request, $byUser) {
            $quotation = new Quotation([
                'request_id' => $request->id,
                'internal_code' => $this->generateInternalCode(),
                'version' => 1,
                'status' => QuotationStatus::Draft->value,
                'recipient_name' => $request->client_name ?: $request->client_email,
                'responsible_user_id' => $request->assigned_user_id ?: $byUser->id,
                'valid_days' => 5,
                'discount_percent' => 0,
                'vat_rate' => (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22)),
                'created_by_user_id' => $byUser->id,
            ]);
            $quotation->save();

            $this->autoFillItemsFromRequest($quotation, $request);
            $this->recalcTotals($quotation);

            Log::info('QuotationService: draft created', [
                'request_id' => $request->id,
                'quotation_id' => $quotation->id,
                'internal_code' => $quotation->internal_code,
                'items_count' => $quotation->items()->count(),
                'by_user_id' => $byUser->id,
            ]);

            return $quotation->fresh('items');
        });
    }

    /**
     * Создать новую версию КП на основе текущего draft'а (Hybrid versioning,
     * семантика «второй вариант»).
     *
     * UX-сценарий: менеджер хочет предложить клиенту второй вариант
     * комплектации (например, дороже-быстрее vs дешевле-долго). Клонирует
     * текущий draft в v+1 — обе версии становятся в timeline'е КП:
     *  - старая v1 → cancelled (frozen snapshot, видна для просмотра)
     *  - новая v2 → draft, активна для редактирования
     *
     * Правки внутри одной версии идут in-place через wire:blur (auto-save),
     * НЕ требуют явного «сохранить». Эта кнопка — только для развилки
     * «начать новый вариант».
     *
     * При markSent (Фаза 4) — то же самое, только новая версия не создаётся
     * автоматически; sent → immutable, чтобы редактировать дальше — менеджер
     * жмёт «Создать новый вариант» снова.
     */
    public function createNextVersion(Quotation $current, User $byUser, ?string $reason = null): Quotation
    {
        if (! $current->status->isEditable()) {
            throw new \DomainException("Quotation {$current->internal_code} not editable (status={$current->status->value})");
        }

        return DB::transaction(function () use ($current, $byUser, $reason) {
            $clone = $current->replicate(['internal_code', 'sent_at', 'sent_email_message_id', 'accepted_at', 'declined_at', 'cancelled_at']);
            $clone->version = $current->version + 1;
            $clone->status = QuotationStatus::Draft->value;
            $clone->internal_code = $this->generateInternalCode();
            $clone->created_by_user_id = $byUser->id;
            $clone->save();

            foreach ($current->items as $item) {
                $itemClone = $item->replicate();
                $itemClone->quotation_id = $clone->id;
                $itemClone->save();
            }

            $current->forceFill([
                'status' => QuotationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'notes' => trim(($current->notes ? $current->notes . "\n" : '')
                    . 'Заморожена при создании v' . $clone->version
                    . ($reason ? ': ' . $reason : '')),
            ])->save();

            Log::info('QuotationService: next version created', [
                'request_id' => $current->request_id,
                'previous_quotation_id' => $current->id,
                'new_quotation_id' => $clone->id,
                'new_version' => $clone->version,
                'by_user_id' => $byUser->id,
            ]);

            return $clone->fresh('items');
        });
    }

    /**
     * Пересчитать subtotal/discount/total/vat. Дёргается:
     *  - после createDraft (auto-fill)
     *  - после каждого item-update в QuotationEditor
     *  - после refreshPrices
     *
     * Формула per-item:
     *   effective_discount = item.discount_percent ?? quotation.discount_percent
     *   final_unit_price = MAX(catalog_unit_price × (1 - effective_discount/100),
     *                          catalog_price_min ?? 0)
     *   line_total = final_unit_price × qty
     *   vat_amount = line_total - (line_total / (1 + vat_rate/100))   // НДС в т.ч.
     *
     * Итоги quotation:
     *   subtotal = SUM(catalog_unit_price × qty)
     *   discount_amount = subtotal - SUM(line_total)
     *   total = SUM(line_total)
     *   vat_amount = SUM(item.vat_amount)
     */
    public function recalcTotals(Quotation $quotation): void
    {
        $vatRate = (float) $quotation->vat_rate;
        $generalDiscount = (float) $quotation->discount_percent;

        $subtotal = 0.0;
        $total = 0.0;
        $vatTotal = 0.0;

        foreach ($quotation->items as $item) {
            $effectiveDiscount = $item->discount_percent !== null
                ? (float) $item->discount_percent
                : $generalDiscount;

            $finalUnitPrice = $this->computeFinalUnitPrice(
                (float) $item->catalog_unit_price,
                $item->catalog_price_min !== null ? (float) $item->catalog_price_min : null,
                $effectiveDiscount,
            );
            $lineTotal = round($finalUnitPrice * (float) $item->qty, 2);
            // НДС в т.ч. — line_total включает НДС, выделяем.
            $itemVat = $vatRate > 0
                ? round($lineTotal - ($lineTotal / (1 + $vatRate / 100)), 2)
                : 0.0;

            $item->forceFill([
                'final_unit_price' => $finalUnitPrice,
                'line_total' => $lineTotal,
                'vat_amount' => $itemVat,
            ])->save();

            $subtotal += (float) $item->catalog_unit_price * (float) $item->qty;
            $total += $lineTotal;
            $vatTotal += $itemVat;
        }

        $subtotal = round($subtotal, 2);
        $total = round($total, 2);
        $vatTotal = round($vatTotal, 2);

        $quotation->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => round($subtotal - $total, 2),
            'total' => $total,
            'vat_amount' => $vatTotal,
        ])->save();
    }

    /**
     * Правило заказчика: «При формировании цена со скидкой берётся наибольшая
     * между ценой с указанной скидкой и минимальной ценой из MDB».
     *
     * Защита от продажи ниже catalog_items.price_min даже когда менеджер
     * поставил большую скидку.
     *
     * @param  float       $catalogUnitPrice
     * @param  float|null  $catalogPriceMin  null = нет защиты, считаем как обычно
     * @param  float       $discountPercent  0..100
     */
    public function computeFinalUnitPrice(float $catalogUnitPrice, ?float $catalogPriceMin, float $discountPercent): float
    {
        $discounted = $catalogUnitPrice * (1 - max(0, min(100, $discountPercent)) / 100);
        if ($catalogPriceMin !== null && $catalogPriceMin > 0) {
            $discounted = max($discounted, $catalogPriceMin);
        }

        return round($discounted, 2);
    }

    /**
     * Пере-snapshot catalog данных в QuotationItems из текущих catalog_items.
     * Используется кнопкой «Обновить цены из каталога» в редакторе draft'а.
     * Для sent/accepted версий — запрещено.
     *
     * @return int сколько items реально обновлены (цена / lead_time изменились).
     */
    public function refreshPrices(Quotation $quotation): int
    {
        if (! $quotation->status->isEditable()) {
            throw new \DomainException("Quotation {$quotation->internal_code} not editable for price refresh");
        }
        $changed = 0;
        foreach ($quotation->items as $item) {
            if ($item->catalog_item_id === null) {
                continue;
            }
            /** @var CatalogItem|null $cat */
            $cat = CatalogItem::find($item->catalog_item_id);
            if (! $cat) {
                continue;
            }
            $before = [
                $item->catalog_unit_price, $item->catalog_price_min,
                $item->catalog_lead_time_days, $item->catalog_in_stock,
            ];
            $this->fillSnapshotFromCatalog($item, $cat);
            $after = [
                $item->catalog_unit_price, $item->catalog_price_min,
                $item->catalog_lead_time_days, $item->catalog_in_stock,
            ];
            if ($before !== $after) {
                $item->save();
                $changed++;
            }
        }
        if ($changed > 0) {
            $this->recalcTotals($quotation->fresh('items'));
        }

        return $changed;
    }

    /**
     * Отметить КП как отправленный (вызывается из ComposeForm post-send hook
     * когда detected_artifacts содержит quotation_sent marker).
     */
    public function markSent(Quotation $quotation, int $emailMessageId): void
    {
        $quotation->forceFill([
            'status' => QuotationStatus::Sent->value,
            'sent_email_message_id' => $emailMessageId,
            'sent_at' => now(),
            'snapshot_company' => $quotation->snapshot_company ?: config('services.company'),
        ])->save();

        Log::info('QuotationService: marked sent', [
            'quotation_id' => $quotation->id,
            'internal_code' => $quotation->internal_code,
            'email_message_id' => $emailMessageId,
        ]);
    }

    public function markAccepted(Quotation $quotation): void
    {
        $quotation->forceFill([
            'status' => QuotationStatus::Accepted->value,
            'accepted_at' => now(),
        ])->save();
    }

    public function markRejected(Quotation $quotation, ?string $reason = null): void
    {
        $patch = ['status' => QuotationStatus::Rejected->value, 'declined_at' => now()];
        if ($reason) {
            $patch['notes'] = trim(($quotation->notes ? $quotation->notes . "\n" : '') . 'Отклонено: ' . $reason);
        }
        $quotation->forceFill($patch)->save();
    }

    public function markCancelled(Quotation $quotation, ?string $reason = null): void
    {
        $patch = ['status' => QuotationStatus::Cancelled->value, 'cancelled_at' => now()];
        if ($reason) {
            $patch['notes'] = trim(($quotation->notes ? $quotation->notes . "\n" : '') . 'Отменено: ' . $reason);
        }
        $quotation->forceFill($patch)->save();
    }

    /**
     * Поднимает items из RequestItem'ов где catalog_item_id != null.
     * Несматченные skip с warning'ом (см. createDraft doc).
     */
    private function autoFillItemsFromRequest(Quotation $quotation, Request $request): void
    {
        $position = 0;
        $items = $request->items()
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->with('catalogItem')
            ->orderBy('position')
            ->get();

        foreach ($items as $reqItem) {
            /** @var RequestItem $reqItem */
            $cat = $reqItem->catalogItem;
            if (! $cat) {
                continue;
            }
            $position++;
            // Мерность считаем через RequestItem::effectiveQty()/effectiveUnit()
            // — та же формула, что и при ручном добавлении (Editor::addItemFromRequest).
            // Если менеджер переключил billing_unit на parsed_length_unit (напр. «м»),
            // effectiveQty вернёт parsed_qty × parsed_length (6 × 55 = 330 м); иначе —
            // обычное parsed_qty. Без этого черновик КП терял длину мерных позиций.
            $effQty = $reqItem->effectiveQty();
            $billingQty = $effQty > 0 ? $effQty : ((float) ($reqItem->parsed_qty ?: 1));
            $billingUnit = $reqItem->effectiveUnit() ?: 'шт';
            $item = new QuotationItem([
                'quotation_id' => $quotation->id,
                'position' => $position,
                'request_item_id' => $reqItem->id,
                'catalog_item_id' => $cat->id,
                'qty' => $billingQty,
                'unit' => $billingUnit,
            ]);
            $this->fillSnapshotFromCatalog($item, $cat);
            $item->save();
        }
    }

    /**
     * Заполнить snapshot-поля item'а из catalog. Используется при autoFill
     * и при refreshPrices.
     */
    private function fillSnapshotFromCatalog(QuotationItem $item, CatalogItem $cat): void
    {
        $item->catalog_unit_price = (float) ($cat->price ?: 0);
        $item->catalog_price_min = $cat->price_min !== null ? (float) $cat->price_min : null;
        $item->catalog_lead_time_days = $cat->lead_time_days;
        $item->catalog_in_stock = ((int) ($cat->stock_available ?? 0)) > 0;
        $item->catalog_stock_available = $cat->stock_available;
        $item->snapshot_name = (string) $cat->name;
        $item->snapshot_sku = $cat->sku;
        $item->snapshot_brand = $cat->brand;
        $item->snapshot_brand_article = $cat->brand_article;
        $item->snapshot_photo_url = $cat->photo_url;
        // Default delivery_text если позиция не на складе.
        if (! $item->delivery_text && ! $item->catalog_in_stock && $cat->lead_time_days) {
            $weeks = (int) ceil($cat->lead_time_days / 7);
            $item->delivery_text = "Под заказ {$weeks} нед";
        }
    }

    /**
     * Сгенерировать КП-2026-NNNN. Год = текущий, NNNN = nextval из
     * quotations_seq.  Sequence монотонно растёт независимо от года —
     * это компромисс: на 1 января номер не сбрасывается. Если потребуется
     * yearly reset — переделать на per-year sequence (отдельная миграция).
     */
    private function generateInternalCode(): string
    {
        $next = (int) DB::selectOne("SELECT nextval('quotations_seq') AS n")->n;
        $year = now()->year;

        return sprintf('КП-%d-%04d', $year, $next);
    }
}
