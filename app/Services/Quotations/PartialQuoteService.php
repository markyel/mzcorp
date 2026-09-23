<?php

namespace App\Services\Quotations;

use App\Enums\RequestStatus;
use App\Models\Quotation;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\Supplier\PriceRefreshReconciler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Отложенное КП: выдаём цену по тому, что есть, и дошлём остальное.
 *
 * Зачем: в потоке 141 открытая заявка из 1 263 — с частично актуальными
 * ценами. Ждать, пока 1С обновит всё, значит молчать неделю; ответить только
 * по части и забыть — потерять вторую половину заказа.
 *
 * Как устроено. Менеджер проверяет матчинг и выдаёт КП на позиции с
 * актуальной ценой. Остальные сматченные позиции помечаются отслеживаемыми и
 * попадают в уже существующий цикл обновления цен (PriceRefreshReconciler,
 * он слушает импорт из 1С и ответы поставщиков), а заявка встаёт в статус
 * «Частичное КП» — в нём её не закрывает автозакрытие по молчанию клиента:
 * долг на нашей стороне.
 *
 * Дальше досылку ведёт `quotes:complete-partial`.
 */
class PartialQuoteService
{
    /** Сколько дней пытаемся дослать полное КП. */
    public const WINDOW_DAYS = 14;

    /** Не чаще раза в столько дней шлём промежуточное дополнение. */
    public const FOLLOWUP_DAYS = 2;

    public function __construct(
        private readonly PriceRefreshReconciler $priceRefresh,
    ) {}

    /**
     * Позиции заявки, которых нет в КП: сматченные и активные, но оставленные
     * без цены. Именно их мы обещаем дослать.
     *
     * @return Collection<int, RequestItem>
     */
    public function pendingItems(Request $request, Quotation $quotation): Collection
    {
        $quoted = $quotation->items->pluck('request_item_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $request->items
            ->filter(fn (RequestItem $i) => (bool) $i->is_active
                && $i->catalog_item_id !== null
                && ! in_array((int) $i->id, $quoted, true))
            ->values();
    }

    /** КП покрывает не всю заявку. */
    public function isPartial(Request $request, Quotation $quotation): bool
    {
        return $this->pendingItems($request, $quotation)->isNotEmpty();
    }

    /**
     * Запустить досылку: пометить отложенные позиции и включить счётчик двух
     * недель. Цикл обновления цен уже умеет вести такие позиции — переиспользуем
     * его, вместо того чтобы заводить второй.
     */
    public function start(Request $request, Quotation $quotation): void
    {
        $pending = $this->pendingItems($request, $quotation);
        if ($pending->isEmpty()) {
            return;
        }

        $this->priceRefresh->markAwaiting($request, $pending->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($request->partial_quote_started_at === null) {
            $request->forceFill(['partial_quote_started_at' => now()])->save();
        }

        Log::info('PartialQuoteService: досылка КП запущена', [
            'request_id' => $request->id,
            'quotation_id' => $quotation->id,
            'pending_items' => $pending->count(),
        ]);
    }

    /**
     * Убрать из КП позиции без актуальной цены.
     *
     * Состав КП собирается из всех сматченных позиций заявки, включая те, чью
     * цену 1С ещё не обновила — в документе они дали бы старую цену, выданную
     * за сегодняшнюю. В клиентское предложение идёт только оценённое (решение
     * заказчика), остальное уходит в досылку.
     *
     * @return int сколько строк убрано
     */
    public function trimToPriced(Quotation $quotation): int
    {
        $stale = $quotation->items()
            ->with('catalogItem:id,price,is_price_actual')
            ->get()
            ->filter(function ($item) {
                $catalog = $item->catalogItem;

                return $catalog === null
                    || ! $catalog->is_price_actual
                    || (float) $catalog->price <= 0;
            });

        if ($stale->isEmpty()) {
            return 0;
        }

        $quotation->items()->whereIn('id', $stale->pluck('id'))->delete();
        app(QuotationService::class)->recalcTotals($quotation->fresh('items'));

        return $stale->count();
    }

    /** Две недели вышли — больше не досылаем. */
    public function windowExpired(Request $request): bool
    {
        return $request->partial_quote_started_at !== null
            && $request->partial_quote_started_at->lt(now()->subDays(self::WINDOW_DAYS));
    }

    /**
     * Остановить досылку.
     *
     * Клиент мог передумать или уйти, не дождавшись второй половины, — тогда
     * автоматическое письмо будет не помощью, а помехой. Статус заявки не
     * трогаем: что с ней делать дальше, решает менеджер.
     */
    public function stop(Request $request, ?User $by = null): void
    {
        if ($request->partial_quote_stopped_at !== null) {
            return;
        }

        $request->forceFill(['partial_quote_stopped_at' => now()])->save();

        Log::info('PartialQuoteService: досылка остановлена', [
            'request_id' => $request->id,
            'by_user_id' => $by?->id,
        ]);
    }

    /** Вернуть заявку в очередь на досылку. */
    public function resume(Request $request): void
    {
        if ($request->partial_quote_stopped_at === null) {
            return;
        }

        $request->forceFill(['partial_quote_stopped_at' => null])->save();
    }

    public function isStopped(Request $request): bool
    {
        return $request->partial_quote_stopped_at !== null;
    }

    /**
     * Что показать менеджеру в карточке. null — досылки по заявке нет.
     *
     * @return array{pending: int, priced: int, until: \Illuminate\Support\Carbon, stopped: bool, expired: bool}|null
     */
    public function state(Request $request): ?array
    {
        if ($request->status !== RequestStatus::PartiallyQuoted || $request->partial_quote_started_at === null) {
            return null;
        }

        $watched = $this->watchedItems($request);

        return [
            'pending' => $watched->count(),
            'priced' => $watched->filter(fn (RequestItem $i) => $this->hasPrice($i))->count(),
            'until' => $request->partial_quote_started_at->copy()->addDays(self::WINDOW_DAYS),
            'stopped' => $this->isStopped($request),
            'expired' => $this->windowExpired($request),
        ];
    }

    /**
     * Что мешает дослать прямо сейчас. null — можно слать.
     *
     * Полное КП уходит, как только цена появилась у ВСЕХ отложенных позиций.
     * Пока часть без цены — ждём два дня от последней отправки, чтобы не слать
     * клиенту письмо на каждую позицию по отдельности.
     */
    public function holdReason(Request $request): ?string
    {
        if ($request->status !== RequestStatus::PartiallyQuoted) {
            return 'заявка уже не в статусе частичного КП';
        }
        if ($this->isStopped($request)) {
            return 'досылку остановил менеджер';
        }
        if ($this->windowExpired($request)) {
            return 'вышли две недели на досылку';
        }

        $pending = $this->watchedItems($request);
        if ($pending->isEmpty()) {
            return 'нет отложенных позиций';
        }

        $priced = $pending->filter(fn (RequestItem $i) => $this->hasPrice($i));
        if ($priced->isEmpty()) {
            return 'цены пока не появились';
        }

        if ($priced->count() === $pending->count()) {
            return null; // всё оценено — шлём полное сразу
        }

        $lastSent = $this->lastSentAt($request);
        if ($lastSent !== null && $lastSent->gt(now()->subDays(self::FOLLOWUP_DAYS))) {
            return 'ждём два дня с прошлой отправки';
        }

        return null;
    }

    /** Полное ли КП получится: цена появилась у всех отложенных позиций. */
    public function isComplete(Request $request): bool
    {
        $pending = $this->watchedItems($request);

        return $pending->isNotEmpty()
            && $pending->every(fn (RequestItem $i) => $this->hasPrice($i));
    }

    /**
     * Отслеживаемые позиции заявки — те, по которым ждём цену.
     *
     * @return Collection<int, RequestItem>
     */
    public function watchedItems(Request $request): Collection
    {
        return RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->where('price_refresh_watched', true)
            ->with('catalogItem:id,price,is_price_actual')
            ->get();
    }

    /** Когда по заявке в последний раз уходило КП. */
    public function lastSentAt(Request $request): ?\Illuminate\Support\Carbon
    {
        $at = Quotation::query()
            ->where('request_id', $request->id)
            ->whereNotNull('sent_at')
            ->max('sent_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** Заявки, по которым пора попробовать дослать. */
    public function due(int $limit = 50)
    {
        return Request::query()
            ->where('status', RequestStatus::PartiallyQuoted->value)
            ->whereNotNull('partial_quote_started_at')
            ->whereNull('partial_quote_stopped_at')
            ->where('partial_quote_started_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->with(['items.catalogItem:id,price,is_price_actual', 'assignedUser'])
            ->orderBy('partial_quote_started_at')
            ->limit($limit)
            ->get();
    }

    /** Кто отправляет досылку, если менеджера у заявки нет. */
    public function actorFor(Request $request): ?User
    {
        return $request->assignedUser;
    }

    private function hasPrice(RequestItem $item): bool
    {
        $catalog = $item->catalogItem;

        return $catalog !== null
            && (bool) $catalog->is_price_actual
            && (float) $catalog->price > 0;
    }
}
