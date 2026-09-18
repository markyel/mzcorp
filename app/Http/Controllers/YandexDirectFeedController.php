<?php

namespace App\Http\Controllers;

use App\Services\Catalog\YandexDirectFeedService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * YML-фид товарной кампании Яндекс.Директа.
 *
 * Без авторизации — Яндекс забирает файл роботом, cookie и сессии ему недоступны.
 * Вместо этого секрет в самом адресе (YANDEX_DIRECT_FEED_TOKEN): фид раскрывает
 * РОЗНИЧНЫЕ цены, которых на сайте анонимному посетителю не видно, и отдавать
 * его по угадываемому адресу нельзя. Токен меняется в .env — старая ссылка
 * мгновенно перестаёт работать.
 *
 * Кэш 30 минут: склад обновляется импортом пару раз в сутки, а Директ
 * перечитывает фид по своему расписанию.
 */
class YandexDirectFeedController extends Controller
{
    private const CACHE_TTL = 1800; // 30 минут

    public function products(string $token, YandexDirectFeedService $service): Response
    {
        $expected = (string) config('services.yandex_direct.feed.token', '');
        // hash_equals — сравнение без утечки времени; пустой токен = фид закрыт.
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        $result = Cache::remember(
            'yandex_direct_feed:products_yml',
            self::CACHE_TTL,
            fn () => $service->generate(),
        );

        return response($result['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_TTL,
            'X-Feed-Offers' => (string) $result['count'],
            'X-Feed-Generated' => $result['generated_at'],
        ]);
    }
}
