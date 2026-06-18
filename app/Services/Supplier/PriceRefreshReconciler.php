<?php

namespace App\Services\Supplier;

use App\Enums\PriceRefreshState;
use App\Enums\RequestActivityType;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestActivityService;

/**
 * Реконсилер цикла обновления цен (Фаза 3.5). Сводит состояние заявки по
 * набору ОТСЛЕЖИВАЕМЫХ позиций (price_refresh_watched) и переводит
 * requests.price_refresh_state:
 *   - есть pending позиции            → awaiting;
 *   - все решены, есть актуальная цена → actualized (алерт «сделать КП»);
 *   - все решены, ни одной актуальной  → refused   (алерт «поставщики отказали»).
 *
 * Позиция «решена», если её цена стала актуальной (catalog_item.is_price_actual)
 * ИЛИ помечена possibly_discontinued (все ответы поставщиков = отказ). Алертим
 * (attention + активность) только на переход awaiting → actualized/refused —
 * идемпотентно. Триггеры: отправка RFQ (markAwaiting), ответ поставщика
 * (reconcile с markDiscontinued), импорт каталога (ReconcilePriceRefreshJob).
 * См. [[suppliers-module]].
 */
class PriceRefreshReconciler
{
    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * Запустить цикл обновления цен: пометить отслеживаемые позиции (сматченные
     * + неактуальная цена среди отправленных) и перевести заявку в awaiting.
     * Если таких позиций нет — цикл не запускаем (нечего актуализировать).
     *
     * @param  array<int, int>  $itemIds  позиции, отправленные в RFQ ([] = все активные)
     */
    public function markAwaiting(Request $request, array $itemIds = []): void
    {
        $q = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->whereHas('catalogItem', fn ($c) => $c->where('is_price_actual', false));
        if ($itemIds !== []) {
            $q->whereIn('id', array_map('intval', $itemIds));
        }
        $staleIds = $q->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        RequestItem::query()->whereIn('id', $staleIds)->update(['price_refresh_watched' => true]);

        if ($request->price_refresh_state !== PriceRefreshState::Awaiting) {
            $request->forceFill(['price_refresh_state' => PriceRefreshState::Awaiting->value])->save();
        }
    }

    /**
     * Пересчитать статус обновления цен заявки.
     *
     * @param  bool  $markDiscontinued  пометить possibly_discontinued по позициям,
     *               где все ответы поставщиков = отказ (true только в reply-флоу,
     *               чтобы импорт не перетирал ручное решение менеджера).
     */
    public function reconcile(Request $request, bool $markDiscontinued = false): void
    {
        if ($request->price_refresh_state === null) {
            return;
        }

        $watched = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('price_refresh_watched', true)
            ->with(['catalogItem:id,is_price_actual', 'supplierInquiryItems:id,request_item_id,status'])
            ->get();

        if ($watched->isEmpty()) {
            return;
        }

        $pending = 0;
        $actual = 0;
        foreach ($watched as $it) {
            if ($it->catalog_item_id && $it->catalogItem?->is_price_actual) {
                $actual++;
                continue;
            }

            if ($markDiscontinued && ! $it->possibly_discontinued) {
                $sii = $it->supplierInquiryItems;
                $allRefused = $sii->isNotEmpty()
                    && $sii->every(fn ($x) => in_array($x->status, ['refused', 'cancelled'], true));
                if ($allRefused) {
                    $it->forceFill(['possibly_discontinued' => true])->save();
                }
            }

            if ($it->possibly_discontinued) {
                continue; // решена как «возможно не поставляется»
            }
            $pending++;
        }

        $new = $pending > 0
            ? PriceRefreshState::Awaiting
            : ($actual > 0 ? PriceRefreshState::Actualized : PriceRefreshState::Refused);

        $old = $request->price_refresh_state;
        if ($new === $old) {
            return;
        }

        $request->forceFill(['price_refresh_state' => $new->value])->save();

        // Алерт только на разрешение awaiting → actualized/refused.
        if ($old === PriceRefreshState::Awaiting && $new === PriceRefreshState::Actualized) {
            $this->activity->touch($request, RequestActivityType::PricesActualized);
            $this->attention->onPricesActualized($request->fresh() ?? $request);
        } elseif ($old === PriceRefreshState::Awaiting && $new === PriceRefreshState::Refused) {
            $this->activity->touch($request, RequestActivityType::AllSuppliersRefused);
            $this->attention->onAllSuppliersRefused($request->fresh() ?? $request);
        }
    }

    /**
     * Рабочие статусы заявки, для которых имеет смысл алерт «цены готовы» даже
     * без отправленного RFQ (заявка в активной работе, менеджер ещё не
     * отквотовал). Терминальные/оплаченные/quoted/paused — исключены.
     */
    private const WORKING_STATUSES = [
        'new', 'assigned', 'in_progress', 'awaiting_client_clarification',
    ];

    /**
     * Заявка БЕЗ активного цикла обновления цен (price_refresh_state=null), но
     * в рабочем статусе: если у неё не осталось ни одной неактуальной
     * сматченной позиции — значит цены только что доехали (одна из позиций
     * флипнулась в этом импорте) → алерт «цены готовы». Идемпотентно: ставим
     * state=actualized, повторно не сработает (next import фильтрует null).
     * Вызывается из ReconcilePriceRefreshJob для рабочих заявок, содержащих
     * актуализированный товар.
     */
    public function reconcileWorking(Request $request): void
    {
        if ($request->price_refresh_state !== null) {
            return; // уже в цикле (RFQ) — это группа reconcile()
        }
        if (! in_array($request->status->value, self::WORKING_STATUSES, true)) {
            return;
        }

        $matched = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->with('catalogItem:id,is_price_actual')
            ->get();

        if ($matched->isEmpty()) {
            return;
        }

        // Осталась хоть одна неактуальная сматченная позиция — рано.
        $hasStale = $matched->contains(fn (RequestItem $it) => ! $it->catalogItem?->is_price_actual);
        if ($hasStale) {
            return;
        }

        // Все сматченные актуальны (а заявка попала сюда из-за только что
        // флипнувшейся позиции) → цены доехали.
        $request->forceFill(['price_refresh_state' => PriceRefreshState::Actualized->value])->save();
        $this->activity->touch($request, RequestActivityType::PricesActualized);
        $this->attention->onPricesActualized($request->fresh() ?? $request);
    }

    /**
     * Завершить цикл обновления цен (менеджер сделал КП / явный сброс): снять
     * статус и флаги отслеживания. Идемпотентно.
     */
    public function clear(Request $request): void
    {
        if ($request->price_refresh_state !== null) {
            $request->forceFill(['price_refresh_state' => null])->save();
        }
        RequestItem::query()
            ->where('request_id', $request->id)
            ->where(fn ($q) => $q->where('price_refresh_watched', true)->orWhere('possibly_discontinued', true))
            ->update(['price_refresh_watched' => false, 'possibly_discontinued' => false]);
    }
}
