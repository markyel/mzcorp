<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Есть ли наш M-артикул на сайте mylift.ru — товар появляется там сразу после заведения в корп. базе (1С), а в каталог
 * mzCorp приходит со следующей синхронизацией. Менеджер завёл новую карточку под позицию и выдал КП (MZ2026-01220,
 * M34817, 20.11.2026): по сайту артикул настоящий, не дожидаясь синхронизации.
 *
 * Страница товара — https://mylift.ru/?code=M…&fn=view: у существующего товара `itemprop="name"` с названием, у
 * несуществующего — пустой. Кэш: найден — на 30 дней, не найден — на 2 часа (могут завести с минуты на минуту);
 * сбой сети не кэшируем и ответ «не знаем» (null).
 */
class MyliftArticleLookup
{
    private const CACHE_PREFIX = 'mylift:sku:';

    /** Название товара на сайте; null — нет такого артикула или сайт не ответил. */
    public function name(string $sku): ?string
    {
        $sku = strtoupper(trim($sku));
        if (preg_match('/^M\d{4,6}$/', $sku) !== 1) {
            return null;
        }
        $cached = Cache::get(self::CACHE_PREFIX.$sku);
        if (is_array($cached)) {
            return $cached['name'];
        }

        try {
            $response = Http::timeout(10)->withHeaders(['User-Agent' => 'mzCorp catalog check'])
                ->get(rtrim((string) config('services.mylift.url', 'https://mylift.ru'), '/').'/', ['code' => $sku, 'fn' => 'view']);
        } catch (Throwable $e) {
            Log::info('MyliftArticleLookup: site unavailable', ['sku' => $sku, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $name = self::nameFromHtml($response->body());
        Cache::put(self::CACHE_PREFIX.$sku, ['name' => $name], $name !== null ? now()->addDays(30) : now()->addHours(2));

        return $name;
    }

    /** Название товара со страницы (itemprop="name"); пусто — товара нет. */
    public static function nameFromHtml(string $html): ?string
    {
        // Кавычки внутри названия сайт не экранирует («Кнопка приказа "-1" тип…») — до конца тега.
        if (preg_match('/itemprop="name"\s+content="(.*?)"\s*\/?>/u', $html, $m) !== 1) {
            return null;
        }
        $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $name !== '' ? $name : null;
    }
}
