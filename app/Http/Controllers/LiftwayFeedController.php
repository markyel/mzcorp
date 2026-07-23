<?php

namespace App\Http\Controllers;

use App\Services\Catalog\LiftwayFeedService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Публичный YML-фид «Цены и наличие» для площадки LazyLift/Liftway.
 * LazyLift опрашивает URL по расписанию (по умолчанию раз в 12 ч) и обновляет
 * цену/наличие по коду позиции (sku). Доступ открытый (по согласованию) —
 * наружу уходят только sku + цена (закупка+наценка) + остаток, без себестоимости
 * и карточек. Кэш 15 мин: каталог меняется импортом ~2×/сутки.
 */
class LiftwayFeedController extends Controller
{
    private const CACHE_TTL = 900; // 15 минут

    public function prices(LiftwayFeedService $service): Response
    {
        $result = Cache::remember('liftway_feed:prices_yml', self::CACHE_TTL, fn () => $service->generatePricesYml());

        return $this->yml($result);
    }

    /** YML «Поставки в пути» — заказанные позиции в пути (stock_in_transit). */
    public function inTransit(LiftwayFeedService $service): Response
    {
        $result = Cache::remember('liftway_feed:in_transit_yml', self::CACHE_TTL, fn () => $service->generateInTransitYml());

        return $this->yml($result);
    }

    /**
     * @param  array{xml:string, count:int, generated_at:string}  $result
     */
    private function yml(array $result): Response
    {
        return response($result['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'X-Feed-Offers' => (string) $result['count'],
            'X-Feed-Generated' => $result['generated_at'],
        ]);
    }
}
